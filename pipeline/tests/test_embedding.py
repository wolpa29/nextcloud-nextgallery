import base64
import io
import json
from pathlib import Path
import tempfile
import hashlib
from types import SimpleNamespace
import unittest
from unittest.mock import patch
import sys
sys.path.insert(0,str(Path(__file__).resolve().parents[2]/'worker'))
import media_pipeline as p
import media_ai_embedding as e
import app_worker as w
from embedding_server import vector,checkpoint_digest,GemmaFull,gemma_full_runtime

class Reporter:
    def __init__(self):self.value={};self.events=[]
    def update(self,**fields):self.value.update(fields)
    def event(self,event,**fields):self.events.append((event,fields))
class FakeClient:
    calls=0
    def __init__(self,config):self.info={'dimensions':128,'space':'a'*64};self.space=e.space_id(self.info,config)
    def health(self):return self.info
    def batch_size(self):return 1
    def images(self,wires,should_stop=lambda:False):return [self.image(wire) for wire in wires]
    def image(self,wire):FakeClient.calls+=1;return [1.]+[0.]*127
class Source:
    scope='synthetic'
    def __init__(self):
        from PIL import Image
        b=io.BytesIO();Image.new('RGB',(64,64),'red').save(b,'PNG');self.wire=b.getvalue();self.etag='v1'
    def inventory(self):return [p.Item('synthetic:1','test.png','1',self.etag,len(self.wire),'image/png')]
    def read(self,item,maximum):return self.wire
    def unchanged(self,item):return item.etag==self.etag
class API:
    url='https://synthetic.invalid/native/'
    def __init__(self):self.published=[];self.fail=False
    def call(self,path,body=None):
        if path=='publish-embedding':
            if self.fail:raise p.ServiceUnavailable('Publisher offline')
            self.published.append(body);return {'stored':True}
        raise AssertionError(path)

def config():return {'analysis_enabled':False,'user_id':'synthetic','folders':['/'],'exclude_folders':[],
 'max_download_bytes':67108864,'max_video_download_bytes':536870912,'max_image_pixels':80000000,
 'embedding':{'enabled':True,'location':'external','url':'http://127.0.0.1:9379/v1','transport':'multimodal','family':'gemma2','model':'embeddinggemma-2','max_edge':512,'video_frames':3,'timeout_seconds':15}}

class EmbeddingTests(unittest.TestCase):
    def setUp(self):FakeClient.calls=0
    def test_full_cpu_and_cuda_builds_share_the_fp32_space_without_changing_cpu_identity(self):
        versions={'torch':'2.14.1+cpu','transformers':'5.19.0','sentence-transformers':'6.1.0'}
        original='2.14.1+cpu|5.19.0|6.1.0'
        self.assertEqual(gemma_full_runtime(versions),original)
        for build in ('2.14.1','2.14.1+cu124','2.14.1+cu130'):
            self.assertEqual(gemma_full_runtime(dict(versions,torch=build)),original)
        for package,version in (('torch','2.14.2+cpu'),('torch','2.14.1+custom'),('transformers','5.20.0'),('sentence-transformers','6.2.0')):
            self.assertNotEqual(gemma_full_runtime(dict(versions,**{package:version})),original)

    def test_full_gpu_uses_fp32_and_preserves_the_existing_cpu_fingerprint(self):
        from unittest.mock import Mock
        versions={'torch':'2.14.1','transformers':'5.19.0','sentence-transformers':'6.1.0'}
        weights='a'*64
        torch=SimpleNamespace(float32=object(),backends=SimpleNamespace(cuda=SimpleNamespace(matmul=SimpleNamespace(allow_tf32=True)),cudnn=SimpleNamespace(allow_tf32=True)))
        loader=Mock()
        args=SimpleNamespace(device='cuda:0',model_path='/synthetic',model='embeddinggemma-2-full',threads=4)
        with patch.dict(sys.modules,{'torch':torch,'sentence_transformers':SimpleNamespace(SentenceTransformer=loader)}),patch('embedding_server.checkpoint_digest',return_value=weights),patch('importlib.metadata.version',side_effect=versions.__getitem__):
            engine=GemmaFull(args)
        self.assertIs(loader.call_args.kwargs['model_kwargs']['torch_dtype'],torch.float32)
        self.assertEqual(loader.call_args.kwargs['device'],'cuda:0')
        self.assertFalse(torch.backends.cuda.matmul.allow_tf32)
        self.assertFalse(torch.backends.cudnn.allow_tf32)
        self.assertEqual(engine.info['precision'],'float32')
        self.assertEqual(engine.info['runtime'],versions)
        original=hashlib.sha256((weights+'|2.14.1+cpu|5.19.0|6.1.0|fp32-cpu-vision280-search-v1').encode()).hexdigest()
        self.assertEqual(engine.info['space'],original)

    def test_independent_embedding_only_no_caption_model_and_no_duplicate_indexing(self):
        with tempfile.TemporaryDirectory() as temp,patch.object(e,'Client',FakeClient),patch.object(w,'NativeSource',return_value=Source()),patch.object(p,'Model',side_effect=AssertionError('Caption model constructed')):
            d=Path(temp);api=API();reporter=Reporter();w.atomic(d/'control.json',{'paused':False})
            result=w.cycle(api,config(),d,reporter,{})
            self.assertEqual(result['analysis']['state'],'disabled');self.assertEqual(result['embedding']['processed'],1)
            w.cycle(api,config(),d,reporter,{})
            self.assertEqual(FakeClient.calls,1);self.assertEqual(len(api.published),1)
    def test_both_off_does_not_contact_any_model(self):
        c=config();c['embedding']['enabled']=False
        with tempfile.TemporaryDirectory() as temp,patch.object(p,'Model',side_effect=AssertionError()),patch.object(e,'Client',side_effect=AssertionError()):
            result=w.cycle(API(),c,Path(temp),Reporter(),{})
            self.assertEqual(result['processed'],0)
    def test_caption_outage_does_not_block_embedding_stage(self):
        c=config();c.update(analysis_enabled=True,vision={'url':'http://localhost/v1','model':'v'},ocr={'url':'http://localhost/v1','model':'o'})
        deferred={'state':'deferred','reason':'Caption server offline','processed':0,'errors':0}
        with tempfile.TemporaryDirectory() as temp,patch.object(e,'Client',FakeClient),patch.object(w,'NativeSource',return_value=Source()),patch.object(p,'run',return_value=deferred):
            d=Path(temp);w.atomic(d/'control.json',{'paused':False});api=API();result=w.cycle(api,c,d,Reporter(),{})
            self.assertEqual(len(api.published),1);self.assertEqual(result['state'],'paused');self.assertTrue(w.read(d/'control.json',{})['paused'])
    def test_publication_outage_retains_vectors_and_resume_does_not_reembed(self):
        with tempfile.TemporaryDirectory() as temp,patch.object(e,'Client',FakeClient):
            d=Path(temp);api=API();api.fail=True
            a=e.run(config(),Source(),api,d,lambda *a,**k:None,lambda:False)
            self.assertEqual(a['state'],'deferred');self.assertEqual(a['stats']['pending'],1)
            api.fail=False;e.run(config(),Source(),api,d,lambda *a,**k:None,lambda:False)
            self.assertEqual(FakeClient.calls,1);self.assertEqual(len(api.published),1)
    def test_model_replacement_cleans_old_space_only_after_success(self):
        with tempfile.TemporaryDirectory() as temp,patch.object(e,'Client',FakeClient):
            d=Path(temp);api=API();c=config();source=Source()
            e.run(c,source,api,d,lambda *a,**k:None,lambda:False)
            old=api.published[-1]['space'];c['embedding']['max_edge']=1024;api.fail=True
            e.run(c,source,api,d,lambda *a,**k:None,lambda:False)
            store=e.Store(d/'embeddings.sqlite3')
            self.assertEqual(store.db.execute('SELECT COUNT(*) FROM embeddings WHERE space=?',(old,)).fetchone()[0],1)
            store.db.close();api.fail=False
            e.run(c,source,api,d,lambda *a,**k:None,lambda:False)
            store=e.Store(d/'embeddings.sqlite3')
            self.assertEqual(store.db.execute('SELECT COUNT(*) FROM embeddings').fetchone()[0],1)
            self.assertEqual(store.db.execute('SELECT space FROM embeddings').fetchone()[0],api.published[-1]['space'])
            store.db.close();self.assertEqual(FakeClient.calls,2)
    def test_file_etag_and_model_space_changes_reembed_without_resetting_captions(self):
        with tempfile.TemporaryDirectory() as temp,patch.object(e,'Client',FakeClient):
            source=Source();api=API();d=Path(temp);c=config()
            for etag in ['v1','v2']:
                source.etag=etag;e.run(c,source,api,d,lambda *a,**k:None,lambda:False)
            c['embedding']['max_edge']=1024;e.run(c,source,api,d,lambda *a,**k:None,lambda:False)
            self.assertEqual(FakeClient.calls,3);self.assertNotEqual(api.published[1]['space'],api.published[2]['space'])
    def test_changed_qwen_weights_have_a_different_identity_even_with_same_model_name(self):
        with tempfile.TemporaryDirectory() as temp:
            d=Path(temp);(d/'config.json').write_text('{}');(d/'model.safetensors').write_bytes(b'synthetic-a')
            original=checkpoint_digest(d);(d/'model.safetensors').write_bytes(b'synthetic-b')
            self.assertNotEqual(original,checkpoint_digest(d))
    def test_invalid_vectors_rejected(self):
        for values in [[0.]*128,[float('nan')]+[0.]*127,[float('inf')]+[0.]*127,[1e308]*128,['1']*128,[1]*64]:
            with self.assertRaises(ValueError):vector(values)
        self.assertAlmostEqual(sum(x*x for x in vector([2.]+[0.]*127)),1.)
    def test_local_query_service_is_kept_available_without_starting_media_processing(self):
        c=config();c['embedding']['location']='local';report=Reporter()
        with tempfile.TemporaryDirectory() as temp,patch.object(w,'_embedding_service_check',(None,0.)),patch.object(w,'ensure_local_embedding') as start:
            w.maintain_embedding_service(c,Path(temp),report)
            w.maintain_embedding_service(c,Path(temp),report)
            start.assert_called_once();self.assertTrue(report.value['embedding_service']['ready'])
    def test_missing_query_runtime_does_not_block_other_processing(self):
        c=config();c['embedding']['location']='local';report=Reporter()
        with tempfile.TemporaryDirectory() as temp,patch.object(w,'_embedding_service_check',(None,0.)),patch.object(w,'ensure_local_embedding',side_effect=p.PipelineError('Runtime missing')):
            w.maintain_embedding_service(c,Path(temp),report)
            self.assertFalse(report.value['embedding_service']['ready'])
    def test_lmstudio_not_silently_used_for_images(self):
        c=config()['embedding'];c['transport']='lmstudio'
        with self.assertRaises(p.PipelineError):e.Client(c).health()

if __name__=='__main__':unittest.main()
