"""Independent image/frame embedding queue; captions and OCR are never required."""
from __future__ import annotations
import base64
from contextlib import contextmanager
import json
import re
import sqlite3
import tempfile
import time
from pathlib import Path
import media_pipeline as p
from inference_gate import hold,InferenceCancelled
from embedding_server import vector


@contextmanager
def video_source(source, item, path, maximum):
    if hasattr(source,'embedding_video'):
        with source.embedding_video(item,path,maximum) as current:
            yield current
    else:
        if item.size>maximum:raise p.PipelineError('Video exceeds download limit')
        source.download_to(item,path,maximum)
        yield path


def space_id(info, config):
    return p.hashlib.sha256((info['space']+'|image-v2-png|edge='+str(config['max_edge'])+'|frames='+str(config['video_frames'])).encode()).hexdigest()


class Client:
    def __init__(self, config):
        self.config=config
        self.url=('http://127.0.0.1:9379/v1' if config['location']=='local' else config['url']).rstrip('/')
        self.headers={'Content-Type':'application/json'}
        if config.get('api_key'): self.headers['Authorization']='Bearer '+config['api_key']
    def call(self, endpoint, body=None, maximum=1024*1024):
        wire,_=p.request(self.url+'/'+endpoint,'POST' if body is not None else 'GET',json.dumps(body).encode() if body is not None else None,
                         self.headers,timeout=self.config['timeout_seconds'],maximum=maximum)
        return json.loads(wire)
    def health(self):
        if self.config.get('transport')=='lmstudio' and self.config['location']=='external':
            raise p.PipelineError('LM Studio: Bild-Embeddings über die Embedding-API nicht unterstützt. Multimodalen Dienst wählen.')
        info=self.call('info')
        custom=self.config['family']=='custom' and self.config['location']=='external'
        family=info.get('family')
        if (info.get('model')!=self.config['model'] or (not custom and family!=self.config['family'])
            or not isinstance(family,str) or not family.strip() or len(family)>128
            or any(ord(char)<32 or ord(char)==127 for char in family)
            or not isinstance(info.get('modalities'),list) or not all(modality in info['modalities'] for modality in ('text','image'))):
            raise p.PipelineError('Embedding-Dienst oder Modell unterstützt die benötigten Eingaben nicht')
        if type(info.get('dimensions')) is not int or not 128 <= info['dimensions'] <=4096 or not isinstance(info.get('space'),str) or not re.fullmatch('[a-f0-9]{64}',info['space']):
            raise p.PipelineError('Invalid embedding service identity')
        self.info=info; self.space=space_id(info,self.config)
        return info
    def image(self, wire):
        with hold(self.config.get("_inference_directory"),self.url):
            return self._image(wire)

    def _image(self, wire):
        result=self.call('embed',{'model':self.config['model'],'image':base64.b64encode(wire).decode()})
        values=vector(result['embedding'])
        if result['space']!=self.info['space'] or len(values)!=self.info['dimensions']: raise p.PipelineError('Embedding model changed during run')
        return values

    def batch_size(self):
        if self.config['location']!='external' or self.info.get('image_batch') is not True:
            return 1
        limit=self.info.get('max_batch_size',1)
        if type(limit) is not int or not 1<=limit<=64:
            return 1
        return min(max(1,self.config.get('batch_size',1)),limit)

    def images(self, wires, should_stop=lambda:False):
        """Keep one output per input, including on older single-image services."""
        results=[]; batch=[]; size=0
        def send():
            if should_stop():raise InferenceCancelled()
            with hold(self.config.get('_inference_directory'),self.url,should_stop):
                if len(batch)==1:
                    return [self._image(base64.b64decode(batch[0]))]
                response=self.call('embed',{'model':self.config['model'],'images':batch},
                    maximum=1024+len(batch)*self.info['dimensions']*32)
                values=response.get('embeddings')
                if response.get('space')!=self.info['space'] or not isinstance(values,list) or len(values)!=len(batch):
                    raise p.PipelineError('Invalid embedding batch response')
                vectors=[vector(value) for value in values]
                if any(len(value)!=self.info['dimensions'] for value in vectors):
                    raise p.PipelineError('Embedding model changed during run')
                return vectors
        for wire in wires:
            encoded=base64.b64encode(wire).decode()
            # Leave room for JSON/model metadata under the existing 24 MiB limit.
            if batch and (len(batch)>=self.batch_size() or size+len(encoded)>24*1024*1024-4096):
                results.extend(send());batch=[];size=0
            if len(encoded)>24*1024*1024-4096:
                raise p.PipelineError('Embedding image exceeds request limit')
            batch.append(encoded);size+=len(encoded)+4
        if batch:results.extend(send())
        return results


class Store:
    def __init__(self, path):
        self.db=sqlite3.connect(path); self.db.row_factory=sqlite3.Row
        self.db.execute('CREATE TABLE IF NOT EXISTS embeddings (id TEXT,space TEXT,etag TEXT,path TEXT,status TEXT,active INTEGER DEFAULT 1,vectors TEXT,error TEXT,PRIMARY KEY(id,space))')
    def stats(self, space):
        counts={r['status']:r['n'] for r in self.db.execute('SELECT status,COUNT(*) n FROM embeddings WHERE space=? AND active=1 GROUP BY status',(space,))}
        return {k:counts.get(k,0) for k in ('done','pending','error')}
    def reset(self):
        with self.db:self.db.execute('DELETE FROM embeddings')


def run(config, source, api, directory, observe, should_stop, retry_errors=False):
    client=Client(config['embedding']); store=Store(directory/'embeddings.sqlite3'); space=None
    processed=errors=0; started=time.monotonic()
    try:
        client.health(); space=client.space
        items=source.inventory()
        retry_ids={}
        with store.db:
            store.db.execute('UPDATE embeddings SET active=0')
            for item in items:
                old=store.db.execute('SELECT etag,status,error FROM embeddings WHERE id=? AND space=?',(item.key,space)).fetchone()
                if old and old['etag']==item.etag and (old['status']=='pending' and old['error'] or retry_errors and old['status']=='error'):
                    retry_ids[item.key]=(1 if p.is_video(item) else 0,item.size)
                if old and old['etag']!=item.etag:store.db.execute('DELETE FROM embeddings WHERE id=? AND space=?',(item.key,space))
                store.db.execute('INSERT OR IGNORE INTO embeddings(id,space,etag,path,status) VALUES (?,?,?,?,?)',(item.key,space,item.etag,item.path,'pending'))
                store.db.execute('UPDATE embeddings SET active=1,path=? WHERE id=? AND space=?',(item.path,item.key,space))
                if retry_errors:store.db.execute("UPDATE embeddings SET status='pending' WHERE id=? AND space=? AND status='error'",(item.key,space))
        if retry_ids:
            items.sort(key=lambda item:retry_ids.get(item.key,(2,0)))
        observe('embedding_inventory_finished',embedding_stats=store.stats(space),embedding_space=space)
        pending=[]; pending_bytes=0
        def rejected(error):
            # A 5xx while /info still answers points at this input, not at an outage;
            # otherwise one bad file would stop every later run at the same place.
            if not isinstance(error.status,int) or error.status<500:return False
            try:client.health()
            except (p.PipelineError,ValueError,KeyError,TypeError,OSError):return False
            return client.space==space
        def rejection(error):
            return p.PipelineError('Embedding service failed on this file ('+str(error)[:200]+')')
        def fail(item,error):
            nonlocal errors
            with store.db:store.db.execute("UPDATE embeddings SET status='error',error=? WHERE id=? AND space=?",(str(error)[:300],item.key,space))
            errors+=1;observe('embedding_error',path=item.path,file_id=item.locator,stage='embedding',error=str(error)[:300],level='error',embedding_stats=store.stats(space))
        def stage(item,vectors):
            if not source.unchanged(item):raise p.PipelineError('Media changed during embedding')
            with store.db:store.db.execute('UPDATE embeddings SET vectors=? WHERE id=? AND space=?',(json.dumps(vectors),item.key,space))
        def publish(item,vectors):
            nonlocal processed
            if not source.unchanged(item):raise p.PipelineError('Media changed during embedding')
            api.call('publish-embedding',{'file_id':int(item.locator),'etag':item.etag,'space':space,'vectors':vectors})
            with store.db:
                store.db.execute("UPDATE embeddings SET status='done',error=NULL WHERE id=? AND space=?",(item.key,space))
                store.db.execute('DELETE FROM embeddings WHERE id=? AND space<>?',(item.key,space))
            processed+=1;observe('embedding_finished',path=item.path,file_id=item.locator,stage='embedding',frames=len(vectors),embedding_stats=store.stats(space))
        def flush():
            nonlocal pending,pending_bytes
            if not pending:return
            if should_stop():raise InferenceCancelled()
            entries=pending;pending=[];pending_bytes=0
            started_batch=time.monotonic()
            try:
                with hold(config['embedding'].get('_inference_directory'),getattr(client,'url',config['embedding']['url']),should_stop), p.ImageBudget(config.get('image_timeout_seconds',0)):
                    observe('embedding_batch_start',stage='embedding',files=[{'file_id':item.locator,'path':item.path} for item,wire in entries])
                    values=client.images([wire for item,wire in entries],should_stop)
            except InferenceCancelled:raise
            except (p.PipelineError,p.ImageTimeout,ValueError,KeyError,TypeError,OSError) as error:
                if isinstance(error,p.ServiceUnavailable) and not rejected(error):raise
                # Isolate a bad entry instead of failing all its healthy neighbours.
                values=[]
                for item,wire in entries:
                    if should_stop():raise InferenceCancelled()
                    try:
                        with hold(config['embedding'].get('_inference_directory'),getattr(client,'url',config['embedding']['url']),should_stop), p.ImageBudget(config.get('image_timeout_seconds',0)):
                            observe('stage_start',path=item.path,file_id=item.locator,stage='embedding')
                            values.append(client.image(wire))
                    except InferenceCancelled:raise
                    except p.ServiceUnavailable as error:
                        if not rejected(error):raise
                        fail(item,rejection(error));values.append(None)
                    except (p.PipelineError,p.ImageTimeout,ValueError,KeyError,TypeError,OSError) as error:
                        fail(item,error);values.append(None)
            staged=[]
            for (item,wire),value in zip(entries,values):
                if value is None:continue
                vectors=[{'frame':0,'seconds':0,'values':value}]
                try:stage(item,vectors);staged.append((item,vectors))
                except (p.PipelineError,ValueError,KeyError,TypeError,OSError) as error:fail(item,error)
            # Persist the entire successful batch before publishing any item.
            # A publication outage can then resume without recomputing its peers.
            observe('embedding_batch_finished',images=len(entries),seconds=round(time.monotonic()-started_batch,3))
            for item,vectors in staged:
                if should_stop():raise InferenceCancelled()
                observe('stage_start',path=item.path,file_id=item.locator,stage='publishing')
                try:publish(item,vectors)
                except p.ServiceUnavailable:raise
                except (p.PipelineError,ValueError,KeyError,TypeError,OSError) as error:fail(item,error)
        for item in items:
            if should_stop():break
            row=store.db.execute('SELECT * FROM embeddings WHERE id=? AND space=?',(item.key,space)).fetchone()
            if row['status']!='pending':continue
            observe('stage_start',path=item.path,file_id=item.locator,stage='embedding')
            try:
                vectors=json.loads(row['vectors']) if row['vectors'] else None
                if vectors is not None:
                    flush();observe('stage_start',path=item.path,file_id=item.locator,stage='publishing');publish(item,vectors);continue
                if p.is_video(item):
                    flush()
                    with p.ImageBudget(config.get('image_timeout_seconds',0)), tempfile.TemporaryDirectory(prefix='nextgallery-embedding-') as temp:
                        path=Path(temp)/('source'+Path(item.path).suffix)
                        maximum=config['max_video_download_bytes']
                        with video_source(source,item,path,maximum) as current:
                            report={}; batch,_,_=p.prepare_video(current,{'frame_count':config['embedding']['video_frames'],'max_edge':config['embedding']['max_edge'],
                                'decoder_timeout_seconds':max(120,config.get('image_timeout_seconds',0) or 600),
                                '_should_stop':should_stop,'decoder_threads':4},config['max_image_pixels'],report)
                    try:
                        with hold(config['embedding'].get('_inference_directory'),getattr(client,'url',config['embedding']['url']),should_stop), p.ImageBudget(config.get('image_timeout_seconds',0)):
                            observe('stage_start',path=item.path,file_id=item.locator,stage='embedding')
                            values=client.images([image[0] for image in batch.images],should_stop)
                    except p.ServiceUnavailable as error:
                        if not rejected(error):raise
                        raise rejection(error) from None
                    vectors=[{'frame':i,'seconds':report['frames'][i]['sample_seconds'],'values':value} for i,value in enumerate(values)]
                    stage(item,vectors);publish(item,vectors)
                else:
                    with p.ImageBudget(config.get('image_timeout_seconds',0)):
                        if item.size>config['max_download_bytes']:raise p.PipelineError('Image exceeds download limit')
                        from embedding_image import prepare_image
                        wire,_,_=prepare_image(source.read(item,config['max_download_bytes']),config['embedding']['max_edge'],config['max_image_pixels'])
                    wire_size=4*((len(wire)+2)//3)+4
                    if wire_size>24*1024*1024-4096:raise p.PipelineError('Embedding image exceeds request limit')
                    if pending and pending_bytes+wire_size>24*1024*1024-4096:flush()
                    pending.append((item,wire));pending_bytes+=wire_size
                if len(pending)>=client.batch_size():flush()
            except InferenceCancelled:break
            except p.ServiceUnavailable:raise
            except (p.PipelineError,p.ImageTimeout,ValueError,KeyError,TypeError,OSError) as error:fail(item,error)
        if not should_stop():flush()
        return {'state':'paused' if should_stop() else 'finished','processed':processed,'errors':errors,'stats':store.stats(space),'space':space,'seconds':round(time.monotonic()-started,3)}
    except InferenceCancelled:
        return {'state':'paused','processed':processed,'errors':errors,'stats':store.stats(space) if space else {},'space':space,'seconds':round(time.monotonic()-started,3)}
    except (p.PipelineError,ValueError,KeyError,TypeError,OSError) as error:
        return {'state':'deferred','reason':str(error)[:300],'processed':processed,'errors':errors,'stats':store.stats(space) if space else {'pending':0,'error':0,'done':0}}
    finally:store.db.close()
