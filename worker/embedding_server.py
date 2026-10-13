"""Small multimodal service shared by indexing and query embedding.
Run beside Nextcloud (CPU) or on another host. No media paths accepted over HTTP.
"""
from __future__ import annotations
import argparse
import base64
import hashlib
import hmac
import importlib
import io
import json
import math
import os
import re
from pathlib import Path
from http.server import BaseHTTPRequestHandler, HTTPServer


def vector(values):
    if not isinstance(values, list) or not 128 <= len(values) <= 4096:
        raise ValueError('Invalid embedding dimensions')
    if any(type(x) not in (int, float) or not math.isfinite(x) for x in values):
        raise ValueError('Embedding contains invalid numbers')
    norm = math.sqrt(sum(x*x for x in values))
    if not math.isfinite(norm) or norm < 1e-8: raise ValueError('Empty or invalid embedding')
    return [float(x/norm) for x in values]


def is_oom(err, torch_mod=None):
    if torch_mod is not None:
        oom_cls = getattr(getattr(torch_mod, 'cuda', None), 'OutOfMemoryError', None)
        if oom_cls and isinstance(err, oom_cls):
            return True
    if type(err).__name__ == 'OutOfMemoryError':
        return True
    msg = str(err).lower()
    return 'out of memory' in msg


class Gemma:
    def __init__(self, args):
        import litert_lm as lm
        import importlib.metadata
        self.lm = lm
        model = Path(args.model_path).resolve(strict=True)
        with model.open('rb') as stream:
            sha = hashlib.sha256()
            for block in iter(lambda:stream.read(1024*1024), b''): sha.update(block)
        self.info = {'model': args.model, 'family': 'gemma2', 'dimensions': 768,
                     'modalities': ['text', 'image'], 'video': 'sampled_frames', 'profile':'lite', 'precision':'int4/int8', 'vision_tokens':args.image_tokens, 'model_sha256':sha.hexdigest(),
                     'space': hashlib.sha256((sha.hexdigest()+'|litert-lm='+importlib.metadata.version('litert-lm')+'|tokens='+str(args.image_tokens)).encode()).hexdigest()}
        backend = lm.Backend.CPU(thread_count=args.threads)
        self.engine = lm.EmbeddingEngine(str(model), backend=backend, vision_backend=backend,
            min_input_length=128, max_input_length=2048, vision_tokens_per_image=args.image_tokens)
        self.options = lm.EmbeddingOptions(normalize=True, output_size=768, vision_tokens_per_image=args.image_tokens,
                                           input_overflow_strategy=lm.InputOverflowStrategy.ERROR)
    def embed(self, text=None, image=None):
        content = self.lm.Content.ImageBytes(image) if image is not None else 'task: search query | text: '+text
        return vector(list(self.engine.compute_embedding(content, options=self.options).embedding))
    def embed_images(self, images):
        return [self.embed(image=img) for img in images]



class GemmaFull:
    """Original checkpoint, float32 on CPU and GPU, standard 280-token image budget."""
    def __init__(self,args):
        import torch
        import importlib.metadata
        from sentence_transformers import SentenceTransformer
        device=getattr(args,'device',None) or os.environ.get('NEXTGALLERY_EMBEDDING_DEVICE','cpu')
        if device=='cpu':torch.set_num_threads(args.threads)
        self.torch=torch
        self.device=device
        weights=checkpoint_digest(args.model_path)
        # EmbeddingGemma 2 does not support float16. Keep the same precision as
        # existing CPU vectors when moving the original model to a CUDA host.
        dtype=torch.float32
        if device.startswith('cuda'):
            torch.backends.cuda.matmul.allow_tf32=False
            torch.backends.cudnn.allow_tf32=False
        self.engine=SentenceTransformer(args.model_path,device=device,local_files_only=True,trust_remote_code=False,
            model_kwargs={'torch_dtype':dtype,'attn_implementation':'sdpa'},config_kwargs={'audio_config':None})
        versions={n:importlib.metadata.version(n) for n in ('torch','transformers','sentence-transformers')}
        runtime=gemma_full_runtime(versions)
        self.info={'model':args.model,'family':'gemma2','dimensions':768,'modalities':['text','image'],
            'video':'sampled_frames','profile':'full','precision':'float32','vision_tokens':280,'model_sha256':weights,'runtime':versions,
            'image_batch':True,'max_batch_size':64,
            'space':hashlib.sha256((weights+'|'+runtime+'|fp32-cpu-vision280-search-v1').encode()).hexdigest()}

    def _encode_items(self, items):
        if not items: return []
        try:
            with self.torch.inference_mode():
                result=self.engine.encode(items,batch_size=len(items),normalize_embeddings=True,show_progress_bar=False)
            if hasattr(result,'cpu'): result=result.cpu()
            rows=result.tolist() if hasattr(result,'tolist') else list(result)
            return [vector(r.tolist() if hasattr(r,'tolist') else list(r)) for r in rows]
        except Exception as e:
            if len(items)<=1 or not is_oom(e,self.torch):raise
        # Leave the exception scope first: its traceback may retain GPU tensors.
        result=None;rows=None
        if hasattr(self.torch,'cuda') and hasattr(self.torch.cuda,'empty_cache'):
            self.torch.cuda.empty_cache()
        mid=len(items)//2
        return self._encode_items(items[:mid]) + self._encode_items(items[mid:])

    def embed_images(self, images):
        from PIL import Image
        items=[{'image':Image.open(io.BytesIO(img)).convert('RGB') if isinstance(img,(bytes,bytearray)) else img.convert('RGB')} for img in images]
        return self._encode_items(items)

    def embed(self,text=None,image=None):
        from PIL import Image
        item={'image':Image.open(io.BytesIO(image)).convert('RGB')} if image is not None else text
        kwargs={} if image is not None else {'prompt_name':'SearchQuery'}
        with self.torch.inference_mode():
            result=self.engine.encode([item],normalize_embeddings=True,show_progress_bar=False,**kwargs)
        if hasattr(result,'cpu'):result=result.cpu()
        values=result[0].tolist() if hasattr(result[0],'tolist') else list(result[0])
        return vector(values)


def gemma_full_runtime(versions):
    # Official CPU/CUDA wheels of the same PyTorch release describe the same
    # FP32 model space. Retain the established +cpu spelling so existing CPU
    # vectors keep their identity. Other build tags and release changes remain
    # distinct; model files, preprocessing and prompts are still fingerprinted.
    torch_version=versions['torch']
    if '+' not in torch_version:torch_version+='+cpu'
    elif re.fullmatch(r'.+\+cu[0-9]+',torch_version):torch_version=torch_version.split('+',1)[0]+'+cpu'
    return '|'.join((torch_version,versions['transformers'],versions['sentence-transformers']))


def checkpoint_digest(directory):
    directory=Path(directory).resolve(strict=True)
    if not directory.is_dir():raise ValueError('Qwen requires a local model directory')
    sha=hashlib.sha256(); files=0
    for path in sorted(directory.rglob('*')):
        relative=path.relative_to(directory)
        if not path.is_file() or any(part.startswith('.') for part in relative.parts) or path.suffix not in ('.json','.safetensors','.bin'):continue
        files+=1;sha.update(str(relative).encode()+b'\0')
        with path.open('rb') as stream:
            for block in iter(lambda:stream.read(1024*1024),b''):sha.update(block)
    if not files:raise ValueError('Qwen model directory is empty')
    return sha.hexdigest()


class Qwen:
    def __init__(self, args):
        # Install upstream implementation separately; never download executable code on a request.
        import sys
        import torch
        import importlib.metadata
        if not args.qwen_source or not args.model_revision:
            raise ValueError('Qwen requires --qwen-source and a pinned --model-revision')
        source=Path(args.qwen_source).resolve(strict=True)
        implementation=hashlib.sha256((source/'src/models/qwen3_vl_embedding.py').read_bytes()).hexdigest()
        sys.path.insert(0, str(source))
        from src.models.qwen3_vl_embedding import Qwen3VLEmbedder
        weights=checkpoint_digest(args.model_path)
        dtype=torch.bfloat16 if torch.cuda.is_available() else torch.float32
        runtime='|'.join(importlib.metadata.version(name) for name in ('transformers','torch','qwen-vl-utils'))+'|'+str(dtype)
        self.torch = torch
        self.engine = Qwen3VLEmbedder(model_name_or_path=args.model_path, revision=args.model_revision,
            torch_dtype=dtype, attn_implementation='sdpa', max_length=8192)
        dimensions = int(self.engine.model.config.hidden_size)
        self.info = {'model': args.model, 'family': 'qwen3_vl', 'dimensions': dimensions,
                     'modalities': ['text', 'image'], 'video': 'sampled_frames', 'model_revision':args.model_revision, 'model_sha256':weights,
                     'image_batch': True, 'max_batch_size': 64,
                     'space': hashlib.sha256((args.model+'|'+args.model_revision+'|'+weights+'|'+implementation+'|'+runtime+'|qwen-v1').encode()).hexdigest()}

    def _process_items(self, items):
        if not items: return []
        try:
            with self.torch.inference_mode():
                results = self.engine.process(items, normalize=True)
            if hasattr(results, 'cpu'): results = results.cpu()
            rows = [r.float().cpu().tolist() if hasattr(r, 'float') else (r.tolist() if hasattr(r, 'tolist') else list(r)) for r in results]
            return [vector(row) for row in rows]
        except Exception as e:
            if len(items)<=1 or not is_oom(e,self.torch):raise
        results=None;rows=None
        if hasattr(self.torch,'cuda') and hasattr(self.torch.cuda,'empty_cache'):
            self.torch.cuda.empty_cache()
        mid=len(items)//2
        return self._process_items(items[:mid]) + self._process_items(items[mid:])

    def embed_images(self, images):
        from PIL import Image
        items = [{'image': Image.open(io.BytesIO(img)).convert('RGB') if isinstance(img,(bytes,bytearray)) else img.convert('RGB')} for img in images]
        return self._process_items(items)

    def embed(self, text=None, image=None):
        from PIL import Image
        item = {'image': Image.open(io.BytesIO(image)).convert('RGB')} if image is not None else {'text':text}
        return vector(self.engine.process([item], normalize=True)[0].float().cpu().tolist())


class Custom:
    """Load an explicitly selected local adapter; never fetch executable model code."""
    def __init__(self, args):
        import importlib.util
        import re
        if not args.custom_adapter:
            raise ValueError('Custom models require --custom-adapter /path/to/adapter.py')
        path=Path(args.custom_adapter).resolve(strict=True)
        spec=importlib.util.spec_from_file_location('nextgallery_custom_adapter',path)
        if spec is None or spec.loader is None:
            raise ValueError('Invalid custom adapter file')
        adapter=importlib.util.module_from_spec(spec)
        spec.loader.exec_module(adapter)
        self.engine=adapter.create_engine(args)
        info=self.engine.info
        family=info.get('family')
        if (info.get('model')!=args.model or not isinstance(family,str) or not family.strip()
            or len(family)>128 or any(ord(char)<32 or ord(char)==127 for char in family)
            or type(info.get('dimensions')) is not int or not 128<=info['dimensions']<=4096
            or not isinstance(info.get('space'),str) or not re.fullmatch('[a-f0-9]{64}',info['space'])
            or not isinstance(info.get('modalities'),list) or not all(modality in info['modalities'] for modality in ('image','text'))):
            raise ValueError('Custom adapter must declare its model, family, dimensions, vector space and image/text support')
        self.info=dict(info)

    def embed_images(self, images):
        raw = self.engine.embed_images(images) if hasattr(self.engine, 'embed_images') else [self.engine.embed(image=img) for img in images]
        values = [vector(v) for v in raw]
        if len(values) != len(images):
            raise ValueError('Custom adapter returned incorrect count of embeddings')
        if any(len(v) != self.info['dimensions'] for v in values):
            raise ValueError('Custom adapter returned a different dimension')
        return values

    def embed(self,text=None,image=None):
        values=vector(self.engine.embed(text=text,image=image))
        if len(values)!=self.info['dimensions']:
            raise ValueError('Custom adapter returned a different dimension')
        return values


def handler(engine, token):
    class Handler(BaseHTTPRequestHandler):
        def setup(self):
            super().setup(); self.connection.settimeout(15)
        def log_message(self, *args): pass  # Never log media, queries or authentication.
        def send(self, code, data):
            wire=json.dumps(data,allow_nan=False).encode()
            self.send_response(code); self.send_header('Content-Type','application/json'); self.send_header('Cache-Control','no-store'); self.send_header('Content-Length',str(len(wire))); self.end_headers(); self.wfile.write(wire)
        def authorized(self):
            return not token or hmac.compare_digest(self.headers.get('Authorization',''), 'Bearer '+token)
        def do_GET(self):
            if not self.authorized(): return self.send(401,{'error':'Authentication required'})
            if self.path != '/v1/info': return self.send(404,{'error':'Not found'})
            self.send(200, engine.info)
        def do_POST(self):
            if not self.authorized(): return self.send(401,{'error':'Authentication required'})
            if self.path != '/v1/embed': return self.send(404,{'error':'Not found'})
            try:
                size=int(self.headers.get('Content-Length','0'))
                if not 0 < size <= 24*1024*1024: return self.send(413,{'error':'Request exceeds limit'})
                body=json.loads(self.rfile.read(size))
                if not isinstance(body,dict): raise ValueError('Invalid request body')
                if body.get('model') != engine.info['model']: raise ValueError('Model mismatch')
                text=body.get('text'); wire=body.get('image'); wires=body.get('images')
                if sum(x is not None for x in (text, wire, wires)) != 1:
                    raise ValueError('Supply exactly one of text, image or images')
                if text is not None:
                    if not isinstance(text,str) or not text.strip() or len(text)>200: raise ValueError('Invalid query')
                    embedding=engine.embed(text=text)
                    if len(embedding)!=engine.info['dimensions']: raise ValueError('Dimension mismatch')
                    self.send(200,{'space':engine.info['space'],'embedding':embedding})
                elif wire is not None:
                    if not isinstance(wire,str): raise ValueError('Invalid image')
                    image=base64.b64decode(wire,validate=True)
                    from PIL import Image
                    with Image.open(io.BytesIO(image)) as im:
                        if im.width*im.height > 16_777_216: raise ValueError('Image exceeds decode limit')
                        im.verify()
                    embedding=engine.embed(image=image)
                    if len(embedding)!=engine.info['dimensions']: raise ValueError('Dimension mismatch')
                    self.send(200,{'space':engine.info['space'],'embedding':embedding})
                else:
                    if not isinstance(wires,list) or not 1 <= len(wires) <= 64:
                        raise ValueError('Invalid images batch')
                    from PIL import Image
                    images=[]
                    for w in wires:
                        if not isinstance(w,str): raise ValueError('Invalid image')
                        img_bytes=base64.b64decode(w,validate=True)
                        with Image.open(io.BytesIO(img_bytes)) as im:
                            if im.width*im.height > 16_777_216: raise ValueError('Image exceeds decode limit')
                            im.verify()
                        images.append(img_bytes)
                    embeddings=engine.embed_images(images) if hasattr(engine,'embed_images') else [engine.embed(image=img) for img in images]
                    if not isinstance(embeddings,list) or len(embeddings)!=len(images):
                        raise ValueError('Batch count mismatch')
                    embeddings=[vector(emb) for emb in embeddings]
                    if any(len(emb)!=engine.info['dimensions'] for emb in embeddings):raise ValueError('Dimension mismatch')
                    self.send(200,{'space':engine.info['space'],'embeddings':embeddings})
            except (ValueError,KeyError,TypeError, OSError): self.send(400,{'error':'Invalid embedding request'})
            except Exception: self.send(503,{'error':'Embedding inference unavailable'})
    return Handler


def main():
    parser=argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--host',default='127.0.0.1'); parser.add_argument('--port',type=int,default=9379)
    parser.add_argument('--family',choices=['gemma2','qwen3_vl','custom'],default='gemma2')
    parser.add_argument('--custom-adapter',help='Trusted local Python file exporting create_engine(args); custom family only')
    parser.add_argument('--profile',choices=['lite','full'],default='lite')
    parser.add_argument('--model',default='embeddinggemma-2'); parser.add_argument('--model-path',required=True)
    parser.add_argument('--model-revision',default=''); parser.add_argument('--qwen-source')
    parser.add_argument('--threads',type=int,default=4); parser.add_argument('--image-tokens',type=int,default=140)
    parser.add_argument('--device',default=os.environ.get('NEXTGALLERY_EMBEDDING_DEVICE','cpu'))
    args=parser.parse_args(); token=os.environ.get('NEXTGALLERY_EMBEDDING_KEY','')
    if args.host not in ('127.0.0.1','::1','localhost') and not token: parser.error('External service requires NEXTGALLERY_EMBEDDING_KEY')
    engine=(Custom if args.family=='custom' else GemmaFull if args.family=='gemma2' and args.profile=='full' else Gemma if args.family=='gemma2' else Qwen)(args)
    server=HTTPServer((args.host,args.port),handler(engine,token)); server.timeout=120
    print(json.dumps({'ready':True,**engine.info}),flush=True)
    try: server.serve_forever()
    finally: server.server_close()

if __name__=='__main__': main()
