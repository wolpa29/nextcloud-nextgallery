"""Independent image/frame embedding queue; captions and OCR are never required."""
from __future__ import annotations
import base64
import json
import re
import sqlite3
import tempfile
import time
from pathlib import Path
import media_pipeline as p
from embedding_server import vector


def space_id(info, config):
    return p.hashlib.sha256((info['space']+'|image-v1|edge='+str(config['max_edge'])+'|frames='+str(config['video_frames'])).encode()).hexdigest()


class Client:
    def __init__(self, config):
        self.config=config
        self.url=('http://127.0.0.1:9379/v1' if config['location']=='local' else config['url']).rstrip('/')
        self.headers={'Content-Type':'application/json'}
        if config.get('api_key'): self.headers['Authorization']='Bearer '+config['api_key']
    def call(self, endpoint, body=None):
        wire,_=p.request(self.url+'/'+endpoint,'POST' if body is not None else 'GET',json.dumps(body).encode() if body is not None else None,
                         self.headers,timeout=self.config['timeout_seconds'],maximum=1024*1024)
        return json.loads(wire)
    def health(self):
        if self.config.get('transport')=='lmstudio' and self.config['location']=='external':
            raise p.PipelineError('LM Studio: Bild-Embeddings über die Embedding-API nicht unterstützt. Multimodalen Dienst wählen.')
        info=self.call('info')
        if info.get('model')!=self.config['model'] or info.get('family')!=self.config['family'] or not {'text','image'}<=set(info.get('modalities',[])):
            raise p.PipelineError('Embedding-Dienst oder Modell unterstützt die benötigten Eingaben nicht')
        if type(info.get('dimensions')) is not int or not 128 <= info['dimensions'] <=4096 or not isinstance(info.get('space'),str) or not re.fullmatch('[a-f0-9]{64}',info['space']):
            raise p.PipelineError('Invalid embedding service identity')
        self.info=info; self.space=space_id(info,self.config)
        return info
    def image(self, wire):
        result=self.call('embed',{'model':self.config['model'],'image':base64.b64encode(wire).decode()})
        values=vector(result['embedding'])
        if result['space']!=self.info['space'] or len(values)!=self.info['dimensions']: raise p.PipelineError('Embedding model changed during run')
        return values


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
        with store.db:
            store.db.execute('UPDATE embeddings SET active=0')
            for item in items:
                old=store.db.execute('SELECT etag FROM embeddings WHERE id=? AND space=?',(item.key,space)).fetchone()
                if old and old['etag']!=item.etag:store.db.execute('DELETE FROM embeddings WHERE id=? AND space=?',(item.key,space))
                store.db.execute('INSERT OR IGNORE INTO embeddings(id,space,etag,path,status) VALUES (?,?,?,?,?)',(item.key,space,item.etag,item.path,'pending'))
                store.db.execute('UPDATE embeddings SET active=1,path=? WHERE id=? AND space=?',(item.path,item.key,space))
                if retry_errors:store.db.execute("UPDATE embeddings SET status='pending' WHERE id=? AND space=? AND status='error'",(item.key,space))
        observe('embedding_inventory_finished',embedding_stats=store.stats(space),embedding_space=space)
        for item in items:
            if should_stop():break
            row=store.db.execute('SELECT * FROM embeddings WHERE id=? AND space=?',(item.key,space)).fetchone()
            if row['status']!='pending':continue
            observe('stage_start',path=item.path,file_id=item.locator,stage='embedding')
            try:
                vectors=json.loads(row['vectors']) if row['vectors'] else None
                if vectors is None:
                    with p.ImageBudget(config.get('image_timeout_seconds',0)), tempfile.TemporaryDirectory(prefix='nextgallery-embedding-') as temp:
                        if p.is_video(item):
                            path=Path(temp)/('source'+Path(item.path).suffix)
                            maximum=config['max_video_download_bytes']
                            if item.size>maximum:raise p.PipelineError('Video exceeds download limit')
                            source.download_to(item,path,maximum)
                            report={}; batch,_,_=p.prepare_video(path,{'frame_count':config['embedding']['video_frames'],'max_edge':config['embedding']['max_edge']},config['max_image_pixels'],report)
                            vectors=[{'frame':i,'seconds':report['frames'][i]['sample_seconds'],'values':client.image(image[0])} for i,image in enumerate(batch.images)]
                        else:
                            if item.size>config['max_download_bytes']:raise p.PipelineError('Image exceeds download limit')
                            wire,_,_=p.prepare_image(source.read(item,config['max_download_bytes']),config['embedding']['max_edge'],config['max_image_pixels'],'embedding')
                            vectors=[{'frame':0,'seconds':0,'values':client.image(wire)}]
                    if not source.unchanged(item):raise p.PipelineError('Media changed during embedding')
                    with store.db:store.db.execute('UPDATE embeddings SET vectors=? WHERE id=? AND space=?',(json.dumps(vectors),item.key,space))
                api.call('publish-embedding',{'file_id':int(item.locator),'etag':item.etag,'space':space,'vectors':vectors})
                with store.db:
                    store.db.execute("UPDATE embeddings SET status='done',error=NULL WHERE id=? AND space=?",(item.key,space))
                    store.db.execute('DELETE FROM embeddings WHERE id=? AND space<>?',(item.key,space))
                processed+=1;observe('embedding_finished',path=item.path,file_id=item.locator,stage='embedding',frames=len(vectors),embedding_stats=store.stats(space))
            except p.ServiceUnavailable:raise
            except (p.PipelineError,ValueError,KeyError,TypeError,OSError) as error:
                with store.db:store.db.execute("UPDATE embeddings SET status='error',error=? WHERE id=? AND space=?",(str(error)[:300],item.key,space))
                errors+=1;observe('embedding_error',path=item.path,file_id=item.locator,stage='embedding',error=str(error)[:300],level='error',embedding_stats=store.stats(space))
        return {'state':'paused' if should_stop() else 'finished','processed':processed,'errors':errors,'stats':store.stats(space),'space':space,'seconds':round(time.monotonic()-started,3)}
    except (p.PipelineError,ValueError,KeyError,TypeError,OSError) as error:
        return {'state':'deferred','reason':str(error)[:300],'processed':processed,'errors':errors,'stats':store.stats(space) if space else {'pending':0,'error':0,'done':0}}
    finally:store.db.close()
