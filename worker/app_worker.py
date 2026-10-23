"""Bundled daemon. Native Nextcloud API, private state, per-image time budgets."""
from __future__ import annotations
import argparse
from contextlib import contextmanager
import signal
import stat
from datetime import datetime, timedelta
import fcntl
import json
import os
from pathlib import Path
import threading
import time
import urllib.parse
import uuid
from zoneinfo import ZoneInfo
import media_pipeline as p
import media_ai_embedding as embedding
import subprocess
import sys
import embedding_setup
import analysis_storage

_shutdown = threading.Event()
PIPELINES = ('descriptions', 'ocr', 'embeddings', 'faces')

_local_embedding_process = None
_local_embedding_signature = None
_embedding_service_check = (None, 0.0)


def atomic(path, value):
    temp=path.with_name(path.name+'.'+uuid.uuid4().hex)
    temp.write_text(json.dumps(value,ensure_ascii=False))
    temp.chmod(0o600)
    temp.replace(path)


def read(path, fallback):
    try:
        value=json.loads(path.read_text())
        return value if isinstance(value,dict) else fallback
    except (FileNotFoundError,ValueError): return fallback


def lane_file(directory, name, kind=None):
    return directory/(name.replace('.json','-'+kind+'.json') if kind else name)


def admin_paused(directory, kind=None):
    return read(lane_file(directory,'processing-admin.json',kind),read(directory/'processing-admin.json',{})).get('paused',False)


def initialize_pipelines(directory):
    """One-time split, shared with PHP; leave native/vector databases untouched."""
    if read(directory/'pipelines.json',{}).get('version',0)>=3:return
    with (directory/'control.lock').open('a') as lock:
        fcntl.flock(lock,fcntl.LOCK_EX)
        if read(directory/'pipelines.json',{}).get('version',0)>=3:return
        version=read(directory/'pipelines.json',{}).get('version',0)
        if version<1:
            old=read(directory/'control.json',{'paused':True,'requested':False})
            status=read(directory/'status.json',{});schedule=read(directory/'schedule.json',{})
            for kind in ('descriptions','embeddings'):
                selected=old.get('run_kind','both') in ('both',kind)
                control={**old,'run_kind':kind} if selected else {'paused':True,'requested':False,'run_kind':kind}
                if old.get('reset_requested'):
                    control.update(reset_requested=old.get('reset_kind','all') in ('all',kind),reset_kind=kind)
                    if control['reset_requested']:control.update(paused=True,requested=False)
                key='analysis_stats' if kind=='descriptions' else 'embedding_stats'
                stats=status.get(key,status.get('stats',{}) if kind=='descriptions' else {})
                lane={'state':'paused','current':None,'stats':stats,key:stats}
                if (status.get('last_run') or {}).get('kind',old.get('run_kind','both'))==kind:lane['last_run']=status.get('last_run')
                if kind=='embeddings':
                    for field in ('embedding_space','embedding_models','embedding_service','embedding_setup_supported','embedding_setup_profiles','embedding_remove_supported'):
                        if field in status:lane[field]=status[field]
                atomic(lane_file(directory,'control.json',kind),control)
                atomic(lane_file(directory,'status.json',kind),lane)
                next_schedule=dict(schedule)
                if kind=='descriptions':next_schedule.pop('embedding_pending',None)
                atomic(lane_file(directory,'schedule.json',kind),next_schedule)
        if version<2:
            description=read(directory/'control-descriptions.json',{'paused':True,'requested':False})
            control={**description,'run_kind':'ocr'}
            if control.get('reset_requested'):control['reset_kind']='ocr'
            atomic(directory/'control-ocr.json',control)
            atomic(directory/'status-ocr.json',{'state':'paused','current':None,'stats':{},'ocr_stats':{}})
            atomic(directory/'schedule-ocr.json',read(directory/'schedule-descriptions.json',{}))
            if (directory/'processing-admin-descriptions.json').exists():atomic(directory/'processing-admin-ocr.json',read(directory/'processing-admin-descriptions.json',{}))
        atomic(directory/'control-faces.json',{'paused':True,'requested':False,'run_kind':'faces'})
        atomic(directory/'status-faces.json',{'state':'paused','current':None,'stats':{'done':0,'pending':0,'error':0}})
        atomic(directory/'schedule-faces.json',{})
        atomic(directory/'pipelines.json',{'version':3})



class API:
    def __init__(self,bootstrap,user_id=None):
        parts=urllib.parse.urlsplit(bootstrap['url'])
        if parts.scheme != 'https' or parts.username or parts.password or parts.query or parts.fragment:
            raise p.PipelineError('Native worker requires canonical HTTPS Nextcloud URL')
        app_id=bootstrap.get('app_id','nextgallery')
        if app_id != 'nextgallery':
            raise p.PipelineError('Unsupported Nextcloud worker application')
        base=bootstrap['url'].rstrip('/')
        self.url=base+'/index.php/apps/nextgallery/ai/worker/'
        # Opaque publication identity survives transport changes, avoiding re-inference.
        self.scope_url=bootstrap.get('scope_identity',self.url)
        self.headers={'Authorization':'Bearer '+bootstrap['token'],'Content-Type':'application/json','Accept':'application/json'}
        self.ca_file=bootstrap.get('ca_file')
        if user_id is not None:self.headers['X-Nextgallery-User']=user_id
    def wire(self,endpoint,body=None,maximum=2*1024*1024):
        return p.request(self.url+endpoint,'POST' if body is not None else 'GET',json.dumps(body,ensure_ascii=False).encode() if body is not None else None,self.headers,timeout=120,maximum=maximum,ca_file=self.ca_file)
    def call(self,endpoint,body=None):
        wire,_=self.wire(endpoint,body)
        return json.loads(wire)


class NativeSource:
    def __init__(self,api,config):
        self.api=api
        self.kind=config.get('_pipeline','descriptions')
        self.scope=p.digest(['native',getattr(api,'scope_url',api.url),config['user_id'],config['folders'],config['exclude_folders']])
    def inventory(self):
        return self._inventory()[0]
    def embedding_inventory(self,space):
        """Reconcile with published results; folder selection is not a model revision."""
        return self._inventory(space)
    def _inventory(self,embedding_space=None):
        cursor=0; items=[]; seen=set(); completed=set()
        while True:
            query={'cursor':cursor,'kind':self.kind}
            if embedding_space is not None:query['embedding_space']=embedding_space
            page=self.api.call('inventory?'+urllib.parse.urlencode(query))
            for row in page['items']:
                identifier=str(row['file_id'])
                if identifier in seen: raise p.PipelineError('Duplicate inventory identity')
                if not row['etag']: raise p.PipelineError('Image has no ETag')
                seen.add(identifier)
                items.append(p.Item(self.scope+':'+identifier,row['path'],identifier,row['etag'],int(row['size']),row['mime'],row.get('analyzed_etag') or ''))
                # Old servers do not acknowledge the space: never mistake their
                # description/OCR completion for a published image vector.
                if self.kind=='embeddings' and embedding_space is not None and page.get('embedding_space')==embedding_space and row.get('embedding_etag')==row['etag']:
                    completed.add(items[-1].key)
            next_cursor=page['cursor']
            if next_cursor is None: return items,completed
            if not isinstance(next_cursor,int) or next_cursor<=cursor: raise p.PipelineError('Invalid inventory pagination')
            cursor=next_cursor
    def read(self,item,maximum):
        wire,headers=self.api.wire('image?'+urllib.parse.urlencode({'file_id':item.locator,'etag':item.etag}),maximum=maximum)
        returned=p.etag(next((v for k,v in headers.items() if k.lower()=='etag'),''))
        if returned != item.etag: raise p.PipelineError('Image changed while downloading')
        if self.kind == 'embeddings' and len(wire) != item.size: raise p.PipelineError('Incomplete source image download; retry this file')
        return wire
    def download_to(self,item,destination,maximum):
        url=self.api.url+'image?'+urllib.parse.urlencode({'file_id':item.locator,'etag':item.etag})
        with p.open_stream(url,'GET',None,self.api.headers,timeout=120,ca_file=self.api.ca_file) as stream:
            if p.etag(stream.headers.get('ETag','')) != item.etag: raise p.PipelineError('Media changed while downloading')
            return p.copy_media(stream,destination,maximum)
    def unchanged(self,item): return self.api.call('resolve?file_id='+item.locator)['etag']==item.etag

    @contextmanager
    def embedding_video(self, item, destination, maximum):
        """Use the server-authorized local video without copying a large original."""
        target = self.api.call('resolve?'+urllib.parse.urlencode({
            'file_id':item.locator, 'etag':item.etag, 'local_media':'true'}))
        if target.get('etag') != item.etag or target.get('file_id') != int(item.locator) or target.get('size') != item.size:
            raise p.PipelineError('Media changed before frame extraction')
        local = target.get('local_path')
        if isinstance(local,str) and Path(local).is_absolute():
            try:
                original = Path(local).stat()
            except FileNotFoundError:
                original = None  # Worker on another host: use the bounded stream.
            if original is not None:
                if not stat.S_ISREG(original.st_mode) or original.st_size != item.size:
                    raise p.PipelineError('Local media is not the authorized current file')
                fingerprint = lambda value:(value.st_dev,value.st_ino,value.st_size,value.st_mtime_ns,value.st_ctime_ns)
                yield Path(local)
                if fingerprint(Path(local).stat()) != fingerprint(original) or not self.unchanged(item):
                    raise p.PipelineError('Media changed during frame extraction')
                return
        if item.size > maximum:
            raise p.PipelineError('Large video requires a worker on the Nextcloud server with readable local storage; remote download limit exceeded')
        self.download_to(item,destination,maximum)
        yield destination


class NativeShared:
    """Content-addressed results of other accounts, offered only for files this account may read."""
    def __init__(self,api): self.api=api; self.available=True
    def call(self,endpoint,body):
        if not self.available: raise p.PipelineError('Result sharing disabled')
        data=self.api.call(endpoint,body)
        if data.get('disabled'):
            self.available=False  # Administrator choice: stop asking for this run.
            raise p.PipelineError('Result sharing disabled')
        return data
    def get(self,locator,etag,sha256,stage,revision):
        """{'result', 'revision', 'model'}; the revision may differ for descriptions and OCR."""
        return self.call('shared-result/lookup',{'file_id':int(locator),'etag':etag,'sha256':sha256,'kind':stage,'revision':revision})
    def put(self,locator,etag,sha256,stage,revision,result,model=None):
        return self.call('shared-result',{'file_id':int(locator),'etag':etag,'sha256':sha256,'kind':stage,'revision':revision,'result':result,'model':model}).get('stored')


class NativePublisher:
    def __init__(self,api,config):
        # Publication identity participates in the analysis revision; keep it stable across the app move.
        self.api=api; self.pipeline=config.get('_pipeline',''); self.config={'url':getattr(api,'scope_url',api.url),'user_id':config['user_id']}
    def capture(self,item,source):
        target=self.api.call('resolve?file_id='+item.locator)
        if target['etag']!=item.etag: raise p.PipelineError('Image changed before analysis')
        return {'file_id':int(item.locator),'etag':item.etag}
    def send(self,row):
        metadata=json.loads(row['metadata'])
        self.api.call('publish',{'stage':self.pipeline if self.pipeline!='both' else '',**metadata['nextcloud_target'],'sha256':row['sha256'],'revision':row['revision'],'metadata':metadata,'exif':json.loads(row['exif'])})


class Reporter:
    def __init__(self,directory,service=False,kind=None):
        self.kind=kind
        self.service=service
        self.directory=directory; self.directory.joinpath('logs').mkdir(mode=0o700,exist_ok=True)
        self.value=read(lane_file(directory,'status.json',kind),{})
        self.value.update(state='idle',current=None)
        self.lock=threading.RLock(); self.stop=threading.Event(); self.run_id=None; self.timezone="Europe/Vienna"
        self.thread=threading.Thread(target=self.beat,daemon=True); self.thread.start()
    def sync_cache_reset(self):
        if self.kind!='embeddings':return
        marker=read(self.directory/'embedding-cache-reset.json',{})
        revision=marker.get('revision')
        if revision and revision!=self.value.get('embedding_cache_revision'):
            self.value.update(embedding_cache_revision=revision,stats={'done':0,'pending':0,'error':0},
                              embedding_stats={'done':0,'pending':0,'error':0},last_run=None,current=None,state='paused')
            self.value.pop('embedding_space',None)
    def beat(self):
        while not self.stop.is_set():
            with self.lock:
                self.sync_cache_reset()
                self.value['heartbeat_at']=time.time(); atomic(lane_file(self.directory,'status.json',self.kind),self.value)
                if self.service:atomic(lane_file(self.directory,'service.json',self.kind),{'heartbeat_at':self.value['heartbeat_at'],'active_user_id':self.value.get('active_user_id')})
            self.stop.wait(5)
    def update(self,**fields):
        with self.lock:
            self.sync_cache_reset()
            self.value.update(fields); self.value['heartbeat_at']=time.time(); atomic(lane_file(self.directory,'status.json',self.kind),self.value)
            if self.service:atomic(lane_file(self.directory,'service.json',self.kind),{'heartbeat_at':self.value['heartbeat_at'],'active_user_id':self.value.get('active_user_id')})
    def event(self,event,**fields):
        if event == 'inference_progress':
            with self.lock:
                current=dict(self.value.get('current') or {})
                current.update({k:fields[k] for k in ['path','file_id','stage','attempt','total_attempts','reasoning_chars','content_chars'] if k in fields})
                self.update(current=current)
            return  # Live progress is status, not an additional activity-log row.
        now=datetime.now(ZoneInfo(self.timezone))
        row={'time':now.isoformat(timespec='seconds'),'run_id':self.run_id,'event':event,'kind':self.kind,'level':fields.pop('level','info'),**fields}
        path=self.directory/'logs'/ (now.strftime('%Y-%m-%d')+'.jsonl')
        with path.open('a') as stream:
            fcntl.flock(stream,fcntl.LOCK_EX);stream.write(json.dumps(row,ensure_ascii=False)+'\n')
        changes={}
        if 'stats' in fields:
            changes['stats']=fields['stats'];changes['analysis_stats']=fields['stats']
            if self.kind=='ocr':changes['ocr_stats']=fields['stats']
        if 'embedding_stats' in fields:
            changes['embedding_stats']=fields['embedding_stats']
            changes['stats']=dict(fields['embedding_stats']) if self.kind=='embeddings' else {key:self.value.get('analysis_stats',{}).get(key,0)+fields['embedding_stats'].get(key,0) for key in ('done','pending','error')}
        if 'embedding_space' in fields: changes['embedding_space']=fields['embedding_space']
        if event in {'file_start','stage_start','embedding_batch_start'}:
            changes['stage_started_at']=time.time()
            changes['current']={k:fields[k] for k in ['path','file_id','stage','files'] if k in fields}
            changes['current']['started_at']=changes['stage_started_at']
        if event == 'inference_attempt_start':
            changes['current']=dict(self.value.get('current') or {})
            changes['current'].update({k:fields[k] for k in ['path','file_id','stage','attempt','total_attempts'] if k in fields})
        if event in {'file_finished','file_error','embedding_finished','embedding_error','file_deferred','service_unavailable','embedding_deferred','run_finished','embedding_batch_finished'}: changes['current']=None
        if changes: self.update(**changes)
    def close(self,preserve=False):
        self.stop.set(); self.thread.join()
        self.value.update(heartbeat_at=0)
        if not preserve:self.value['state']='stopped'
        atomic(lane_file(self.directory,'status.json',self.kind),self.value)


def consume(directory, request_id, kind=None):
    with (directory/'control.lock').open('a') as stream:
        fcntl.flock(stream,fcntl.LOCK_EX)
        control=read(lane_file(directory,'control.json',kind),{'paused':True})
        if control.get('request_id')==request_id:
            control['requested']=False; control['retry_errors']=False; atomic(lane_file(directory,'control.json',kind),control)


def pause_unavailable(directory, reason, kind=None):
    with (directory/'control.lock').open('a') as stream:
        fcntl.flock(stream,fcntl.LOCK_EX)
        control=read(lane_file(directory,'control.json',kind),{})
        control.update(paused=True,requested=False,retry_errors=False,pause_reason=reason,updated_at=time.time())
        atomic(lane_file(directory,'control.json',kind),control)


def waiting_status(control):
    paused=control.get('paused',True)
    return {'state':'paused' if paused else 'idle', 'current':None,
            'message':control.get('pause_reason','') if paused else ''}


def reset_ai(api,directory,reporter,control):
    kind=control.get('reset_kind','all')
    lane=getattr(reporter,'kind',None)
    api.call('reset',{'kind':kind})
    if kind in ('all','descriptions','ocr'):
      analysis_storage.reset_legacy(directory,kind)
      for analysis_kind in (('descriptions','ocr') if kind=='all' else (kind,)):
        store=p.Store(analysis_storage.path(directory,analysis_kind))
        try:
            with store.db:
                for table in ['files','results','shared','search','locks']:store.db.execute('DELETE FROM '+table)
        finally:store.db.close()
    if kind in ('all','embeddings'):
        embeddings=embedding.Store(directory/'embeddings.sqlite3')
        try:embeddings.reset()
        finally:embeddings.db.close()
    if kind in ('all','faces'):
        for suffix in ('','-wal','-shm'):
            cache=directory/('faces.sqlite3'+suffix)
            if cache.is_file() and not cache.is_symlink():cache.unlink()
    with (directory/'control.lock').open('a') as stream:
        fcntl.flock(stream,fcntl.LOCK_EX)
        current=read(lane_file(directory,'control.json',lane),{})
        if current.get('reset_request_id')==control.get('reset_request_id'):
            current['reset_requested']=False;atomic(lane_file(directory,'control.json',lane),current)
    fields={'analysis_stats':{}} if kind in ('descriptions','ocr') else {'embedding_stats':{}} if kind=='embeddings' else {'analysis_stats':{},'embedding_stats':{}}
    if kind=='ocr':fields['ocr_stats']={}
    reporter.update(stats={'done':0,'pending':0,'error':0},last_run=None,current=None,state='paused',message='KI-Daten zurückgesetzt',**fields)
    reporter.event('ai_reset_finished',kind=kind)


def faces_cycle(api,config,directory,reporter,control):
    """Independent face processing. The PHP database owns results and corrections."""
    import media_ai_faces
    kind='faces';config=dict(config);config['_pipeline']=kind
    config['_inference_directory']=config.get('_model_directory')
    def stop():
        return _shutdown.is_set() or admin_paused(Path(config.get('_model_directory',directory)),kind) or read(lane_file(directory,'control.json',kind),{'paused':True}).get('paused',True)
    reporter.run_id=uuid.uuid4().hex
    reporter.update(state='running',current=None,message='Personenerkennung läuft')
    started=time.monotonic()
    progress={'done':0,'pending':0,'error':0}
    def observe(event,**fields):
        if event=='faces_inventory':
            progress.update(done=fields.get('done',0),pending=fields.get('pending',0),error=0)
            reporter.update(stats=dict(progress),faces_stats=dict(progress))
        elif event in ('faces_processed','faces_error'):
            progress['pending']=max(0,progress['pending']-1)
            progress['error' if event=='faces_error' else 'done']+=1
            reporter.update(stats=dict(progress),faces_stats=dict(progress))
            fields.pop('level',None)
            reporter.update(current=None)
            reporter.event('file_error' if event=='faces_error' else 'file_finished',**fields,level='error' if event=='faces_error' else 'info')
        else:
            if event=='file_start':reporter.update(current={'path':fields.get('path',''),'stage':'faces','file_id':fields.get('file_id'),'started_at':time.time()})
            reporter.event(event,**fields)
    try:
        reporter.event('run_start',kind=kind)
        result=media_ai_faces.run(config,NativeSource(api,config),api,directory,observe,stop,retry_errors=bool(control.get('retry_errors')))
        stats=result.get('stats',{'done':result.get('done',0),'pending':result.get('pending',0),'error':result.get('error',0)})
        result={**result,'kind':kind,'processed':result.get('processed',result.get('done',0)),'seconds':round(time.monotonic()-started,3)}
        reporter.update(state='paused' if stop() else result.get('state','finished'),current=None,stats=stats,faces_stats=stats,last_run=result,message='')
        return {'faces':result,'embedding':{'state':'disabled'},'kind':kind}
    except Exception as error:
        raise p.PipelineError(str(error)) from error
    finally:
        reporter.run_id=None


def is_due(config,control,schedule,now=None):
    if control.get('paused',True): return False
    if control.get('requested'): return True
    if schedule.get('embedding_pending') and config.get('embedding',{}).get('enabled'): return True
    if not config['enabled']: return False
    current=now or datetime.now(ZoneInfo(config['timezone']))
    return current.strftime('%H:%M')>=config['schedule_time'] and schedule.get('date')!=current.strftime('%Y-%m-%d')


class EmbeddingSetupPending(p.PipelineError):
    def __init__(self, progress):
        super().__init__(progress.get('message','Lokale Bildsuche wird eingerichtet …'))
        self.progress=progress


def ensure_local_embedding(config, directory):
    global _local_embedding_process, _local_embedding_signature
    data=config['embedding']
    profile=data.get('local_profile','lite')
    if data['location']!='local':return
    probe_data=dict(data,timeout_seconds=3)
    python=sys.executable
    if data.get('local_auto_setup') and data['family']=='gemma2':
        progress,python,model=embedding_setup.poll(directory,data.get('local_profile','lite'))
        if python is None:raise EmbeddingSetupPending(progress)
    else:
        embedding_setup.stop()
        embedding_setup.clear_request(directory)
        model=Path(data['local_model_path'])
    signature=p.digest({**{k:data.get(k) for k in ('model','family','local_qwen_source','model_revision','api_key')},'model_path':str(model),'python':str(python),'profile':profile})
    if _local_embedding_process is not None and _local_embedding_process.poll() is None and signature!=_local_embedding_signature:
        _local_embedding_process.terminate();_local_embedding_process.wait(timeout=10)
    _local_embedding_signature=signature
    full=data['family']=='gemma2' and data.get('local_auto_setup') and profile=='full'
    present=model.is_dir() if full or data['family']=='qwen3_vl' else model.is_file()
    if not present:raise p.PipelineError('Lokales Embedding-Modell fehlt. Einrichtung im Modellbereich prüfen.')
    expected=None
    if data['family']=='gemma2' and not full:
        sha=p.hashlib.sha256()
        with model.open('rb') as stream:
            for block in iter(lambda:stream.read(1024*1024),b''):sha.update(block)
        expected=sha.hexdigest()
        if data.get('local_auto_setup') and expected!=embedding_setup.MODEL_SHA256:
            (directory/'embedding-local/ready.json').unlink(missing_ok=True)
            raise EmbeddingSetupPending({'phase':'verifying','message':'Modellpaket wird geprüft …'})
    if full:
        from embedding_server import checkpoint_digest
        expected=checkpoint_digest(model)
    def ready():
        info=embedding.Client(probe_data).health()
        matches=info.get('model_sha256')==expected if expected else info.get('model_revision')==data.get('model_revision')
        if not matches:raise p.PipelineError('Lokaler Dienst verwendet ein anderes Modellpaket')
    try:ready();return
    except (p.PipelineError,ValueError,KeyError,OSError):pass
    if _local_embedding_process is not None and _local_embedding_process.poll() is None:
        raise p.PipelineError('Lokaler Embedding-Dienst startet noch oder verwendet ein anderes Modell')
    env=dict(os.environ,NEXTGALLERY_EMBEDDING_KEY=data.get('api_key',''))
    args=[str(python),str(Path(__file__).with_name('embedding_server.py')),'--family',data['family'],'--model',data['model'],'--model-path',str(model),'--profile',profile if data['family']=='gemma2' and data.get('local_auto_setup') else 'lite']
    if data.get('local_qwen_source'):args+=['--qwen-source',data['local_qwen_source']]
    if data.get('model_revision'):args+=['--model-revision',data['model_revision']]
    with (directory/'embedding-service.log').open('a') as log:
        _local_embedding_process=subprocess.Popen(args,env=env,stdin=subprocess.DEVNULL,stdout=log,stderr=log)
    deadline=time.monotonic()+(180 if full else 60)
    while time.monotonic()<deadline:
        if _local_embedding_process.poll() is not None:raise p.PipelineError('Lokaler Embedding-Dienst konnte nicht starten. Runtime und Modellpaket prüfen.')
        try:ready();return
        except (p.PipelineError,ValueError,KeyError,OSError):time.sleep(1)
    raise p.PipelineError('Lokaler Embedding-Dienst startet noch. Lauf später erneut starten.')


def maintain_embedding_service(config, directory, reporter):
    """Keep query inference available even while media processing is paused."""
    global _embedding_service_check, _local_embedding_process
    data=config.get('embedding',{})
    removal=read(directory/'embedding-remove.json',{})
    if removal.get('requested'):
        try:
            if _local_embedding_process is not None and _local_embedding_process.poll() is None:
                _local_embedding_process.terminate();_local_embedding_process.wait(timeout=10)
            embedding_setup.remove(directory,removal['profile'])
            removal.update(requested=False,phase='removed',message='Modell und Laufzeit wurden entfernt. Gespeicherte KI-Daten bleiben erhalten.')
            _embedding_service_check=(None,0.)
        except (OSError,ValueError,KeyError) as error:
            removal.update(requested=False,phase='error',message='Modell konnte nicht entfernt werden. Worker-Rechte prüfen.')
        atomic(directory/'embedding-remove.json',removal)
    reporter.update(embedding_models=embedding_setup.installations(directory),embedding_remove=removal)
    if data.get('location')!='local':
        embedding_setup.stop()
        embedding_setup.clear_request(directory)
        if _local_embedding_process is not None and _local_embedding_process.poll() is None:
            _local_embedding_process.terminate();_local_embedding_process.wait(timeout=10)
        _embedding_service_check=(None,0.)
        reporter.update(embedding_service={'ready':False,'phase':'disabled','location':data.get('location','local'),'setup_supported':True})
        return True
    if not data.get('enabled'):
        if _local_embedding_process is not None and _local_embedding_process.poll() is None:
            _local_embedding_process.terminate();_local_embedding_process.wait(timeout=10)
        _embedding_service_check=(None,0.)
        if data.get('local_auto_setup') and data.get('family')=='gemma2':
            progress,_,_=embedding_setup.poll(directory,data.get('local_profile','lite'))
            reporter.update(embedding_service={'ready':False,'location':'local','setup_supported':True,'managed':True,**progress})
        else:
            embedding_setup.stop();embedding_setup.clear_request(directory)
            reporter.update(embedding_service={'ready':False,'phase':'disabled','location':'local'})
        return True
    signature=p.digest(data)
    previous,checked=_embedding_service_check
    if previous==signature and time.monotonic()-checked<60:return reporter.value.get('embedding_service',{}).get('ready',False)
    _embedding_service_check=(signature,time.monotonic())
    try:
        ensure_local_embedding(config,directory)
        reporter.update(embedding_service={'ready':True,'installed':True,'setup_supported':True,'managed':data.get('local_auto_setup') and data.get('family')=='gemma2','phase':'ready','profile':data.get('local_profile','lite'),'location':'local','message':'Bereit für Bilder, Video-Standbilder und Suchanfragen.'})
        return True
    except EmbeddingSetupPending as pending:
        _embedding_service_check=(None,0.)
        reporter.update(embedding_service={'ready':False,'location':'local','setup_supported':True,'managed':True,**pending.progress})
        return False
    except (p.PipelineError,OSError,ValueError) as error:
        managed=data.get('local_auto_setup') and data.get('family')=='gemma2'
        reporter.update(embedding_service={'ready':False,'phase':'error','location':'local','setup_supported':True,'managed':managed,'profile':data.get('local_profile','lite'),'installed':bool(managed and embedding_setup.cached(directory,data.get('local_profile','lite'))),'message':str(error)[:300]})
        return False


def cycle(api,config,directory,reporter,control):
    config=json.loads(json.dumps(config))
    kind=control.get('run_kind','both')
    config['analysis_enabled']=(config.get('ocr_enabled',config.get('analysis_enabled',True)) if kind=='ocr' else config.get('analysis_enabled',True)) and kind in ('descriptions','ocr','both')
    config.setdefault('embedding',{'enabled':False})
    config['embedding']['enabled']=config['embedding'].get('enabled',False) and kind in ('embeddings','both')
    lane=getattr(reporter,'kind',None)
    analysis_kind='ocr' if kind=='ocr' else 'descriptions'
    config['_pipeline']=kind
    config['_inference_directory']=config.get('_model_directory')
    config['embedding']['_inference_directory']=config.get('_model_directory')
    store=p.Store(directory/'native.sqlite3' if kind=='both' else analysis_storage.path(directory,analysis_kind)) if config['analysis_enabled'] else None
    started=time.monotonic()
    try:
        if store:
            with store.db:
                store.db.execute('DELETE FROM locks')
                if control.get('retry_errors'):store.db.execute("UPDATE files SET retry_at=0 WHERE status='error'")
        source=NativeSource(api,config)
        if store:
            with store.db:store.db.execute('UPDATE files SET active=0 WHERE scope<>?',(source.scope,))
        reporter.run_id=uuid.uuid4().hex
        reporter.update(state='running',current=None,started_at=time.time(),message='Ausgewählte Verarbeitungsschritte',analysis_enabled=config.get('analysis_enabled',True),embedding_enabled=config.get('embedding',{}).get('enabled',False),analysis_stats={} if not config.get('analysis_enabled',True) else store.stats())
        reporter.event('run_start',kind=kind)
        stop=lambda:_shutdown.is_set() or read(lane_file(directory,'control.json',lane),{'paused':True}).get('paused',True) or admin_paused(Path(config.get('_model_directory',directory)),lane)
        analysis={'state':'disabled','processed':0,'errors':0,'published':0,'cached_stage_results':0}
        if config.get('analysis_enabled',True):
            models={}
            for stage in (['vision','ocr'] if kind=='both' else ['ocr'] if kind=='ocr' else ['vision']):
                data=dict(config[stage]);secret=data.pop('api_key','');name='MEDIA_AI_'+stage.upper()+'_KEY'
                os.environ[name]=secret;data['api_key_env']=name;models[stage]=p.Model(data,stage)
                models[stage].inference_directory=config.get('_model_directory')
            analysis=p.run(config,store,source,models,limit=0,max_seconds=0,publisher=NativePublisher(api,config),observe=reporter.event,should_stop=stop,shared=NativeShared(api))
        reporter.update(analysis_stats=store.stats() if config.get('analysis_enabled',True) else {})
        dense={'state':'disabled','processed':0,'errors':0,'stats':{'done':0,'pending':0,'error':0}}
        if config.get('embedding',{}).get('enabled') and not stop():
            reporter.update(current=None,message='Bildsuche vorbereiten')
            try:
                ensure_local_embedding(config,Path(config.get('_model_directory',directory)))
                dense=embedding.run(config,source,api,directory,reporter.event,stop,bool(control.get('retry_errors')),NativeShared(api))
            except EmbeddingSetupPending as pending:
                dense.update(state='preparing')
                reporter.update(embedding_service={'ready':False,'location':'local','setup_supported':True,'managed':True,**pending.progress})
            except (p.PipelineError,OSError,ValueError) as error:dense.update(state='deferred',reason=str(error)[:300])
        analysis_stats=store.stats() if config.get('analysis_enabled',True) else {'done':0,'pending':0,'error':0}
        stats={key:analysis_stats.get(key,0)+dense['stats'].get(key,0) for key in ('done','pending','error')}
        reasons=[r.get('reason','') for r in (analysis,dense) if r['state']=='deferred']
        state='paused' if stop() or reasons else 'finished'
        result={'state':state,'processed':analysis['processed']+dense['processed'],'errors':analysis['errors']+dense['errors'],
                'published':analysis.get('published',0)+dense['processed'],'cached_stage_results':analysis.get('cached_stage_results',0),
                'analysis':analysis,'embedding':dense,'kind':kind,'seconds':round(time.monotonic()-started,3)}
        if reasons:
            result['reason']='; '.join(reasons);pause_unavailable(directory,result['reason'],lane);reporter.update(paused=True)
        reporter.event('run_finished',**result)
        reporter.update(state=state,last_run=result,stats=stats,analysis_stats=analysis_stats,embedding_stats=dense['stats'],
                        current=None,message=result.get('reason','Bildsuche wartet auf die Gemma-Installation.' if dense['state']=='preparing' else 'Lauf abgeschlossen'))
        return result
    finally:
        if store:store.db.close()



def detect_embedding_rebuild(config,directory,reporter):
    """Bind approval to the actual vector space, including a changed runtime/model."""
    if not config.get('embedding',{}).get('enabled'):return False
    marker=read(directory/'embedding-rebuild.json',{})
    try:
        info=embedding.Client(dict(config['embedding'],timeout_seconds=3)).health()
        space=embedding.space_id(info,config['embedding'])
    except (p.PipelineError,ValueError,KeyError,OSError):return bool(marker.get('required'))
    with (directory/'control.lock').open('a') as lock:
        fcntl.flock(lock,fcntl.LOCK_EX)
        marker=read(directory/'embedding-rebuild.json',{})
        signature=config.get('embedding_config_id') or p.digest(config['embedding'])
        if marker.get('required'):
            if marker.get('target_space') not in (None,space):marker['request_id']=uuid.uuid4().hex
            marker.update(target_space=space,new_dimensions=info['dimensions']);atomic(directory/'embedding-rebuild.json',marker)
            return True
        if marker.get('approved') and marker.get('signature')==signature:
            if marker.get('approved_space') is None:
                marker['approved_space']=space;atomic(directory/'embedding-rebuild.json',marker)
            if marker.get('approved_space')==space:return False
        database=directory/'embeddings.sqlite3'
        if not database.is_file():return False
        import sqlite3
        connection=sqlite3.connect(database)
        try:rows=connection.execute("SELECT space,vectors FROM embeddings WHERE active=1 AND status='done'").fetchall()
        finally:connection.close()
        mismatched=[row for row in rows if row[0]!=space]
        if not mismatched:return False
        old=json.loads(mismatched[0][1]) if mismatched[0][1] else None;dimensions=len(old[0]['values']) if old else None
        marker={'required':True,'approved':False,'signature':signature,'target_space':space,'request_id':uuid.uuid4().hex,
            'file_count':len(mismatched),'old_dimensions':dimensions,'new_dimensions':info['dimensions'],'model':config['embedding']['model'],'updated_at':time.time()}
        atomic(directory/'embedding-rebuild.json',marker)
        return True


def account_directories(directory, legacy_uid):
    """Do not follow linked directories or trust an account marker's path."""
    result=[(legacy_uid,directory)]
    parent=directory/'accounts'
    if not parent.is_dir() or parent.is_symlink():return result
    for path in parent.iterdir():
        if not path.is_dir() or path.is_symlink():continue
        marker=path/'account.json'
        if marker.is_symlink() or not marker.is_file() or marker.stat().st_size>4096:continue
        uid=read(marker,{}).get('user_id')
        if isinstance(uid,str) and uid!=legacy_uid and p.hashlib.sha256(uid.encode()).hexdigest()==path.name:
            result.append((uid,path))
    return result


def main():
    os.umask(0o077)
    parser=argparse.ArgumentParser();parser.add_argument('--bootstrap',required=True);parser.add_argument('--once',action='store_true');parser.add_argument('--pipeline',choices=PIPELINES);args=parser.parse_args()
    directory=Path(args.bootstrap).resolve().parent
    if args.pipeline is None:
        return supervise(args,directory)
    kind=args.pipeline
    with (directory/('daemon-'+kind+'.lock')).open('a') as lock:
        try:fcntl.flock(lock,fcntl.LOCK_EX|fcntl.LOCK_NB)
        except BlockingIOError:raise SystemExit('Worker already active')
        initialize_pipelines(directory)
        if kind in ('descriptions','ocr'):analysis_storage.initialize(directory)
        bootstrap=read(Path(args.bootstrap),{});api=API(bootstrap);reporter=Reporter(directory,service=True,kind=kind)
        try:
            while not _shutdown.is_set():
                try:
                    global_config=api.call('config');reporter.timezone=global_config['timezone']
                    embedding_ready=True
                    if kind=='embeddings':
                        reporter.update(embedding_setup_supported=True,embedding_setup_profiles=['lite','full'],embedding_remove_supported=True)
                        with (directory/'processing-embeddings.lock').open('a') as maintenance_lock:
                            fcntl.flock(maintenance_lock,fcntl.LOCK_SH|fcntl.LOCK_NB)
                            global_config=api.call('config')
                            embedding_ready=maintain_embedding_service(global_config,directory,reporter)
                    if kind=='faces':
                        import faces_setup
                        request=read(directory/'faces-install.json',{})
                        setup=faces_setup.tick(directory,requested=bool(request.get('requested')))
                        reporter.update(faces_setup=setup)
                        if setup.get('installed'):
                            if request.get('requested'):atomic(directory/'faces-install.json',{'requested':False})
                            runtime=Path(faces_setup.python_path(directory))
                            if Path(sys.executable).absolute()!=runtime.absolute():
                                # Re-exec just this lane into its isolated OpenCV runtime.
                                reporter.close()
                                os.execv(str(runtime),[str(runtime),str(Path(__file__).resolve()),*sys.argv[1:]])
                    candidates=account_directories(directory,global_config['user_id'])
                    for _,account_directory in candidates:
                        initialize_pipelines(account_directory)
                        if kind in ('descriptions','ocr'):analysis_storage.initialize(account_directory)
                    candidates.sort(key=lambda item:(not read(lane_file(item[1],'control.json',kind),{}).get('reset_requested'),not read(lane_file(item[1],'control.json',kind),{}).get('requested'),read(lane_file(item[1],'control.json',kind),{}).get('updated_at',0),item[0]))
                    active=False
                    if admin_paused(directory,kind):
                        candidates=[]
                    for uid,account_directory in candidates:
                        scoped_api=API(bootstrap,uid)
                        control=read(lane_file(account_directory,'control.json',kind),{'paused':True});schedule=read(lane_file(account_directory,'schedule.json',kind),{})
                        if not control.get('reset_requested') and control.get('paused',True):
                            if account_directory==directory:reporter.update(**waiting_status(control))
                            continue
                        child=None;processing_lock=None
                        try:
                            processing_lock=(directory/('processing-'+kind+'.lock')).open('a')
                            fcntl.flock(processing_lock,fcntl.LOCK_SH|fcntl.LOCK_NB)
                            if admin_paused(directory,kind):continue
                            control=read(lane_file(account_directory,'control.json',kind),{'paused':True})
                            if not control.get('reset_requested') and control.get('paused',True):continue
                            config=scoped_api.call('config');config['_model_directory']=str(directory)
                            if kind=='faces' and not control.get('reset_requested') and (not config.get('faces',{}).get('enabled') or not setup.get('installed')):continue
                            config['_pipeline']=kind
                            config['analysis_enabled']=(config.get('ocr_enabled',config.get('analysis_enabled',True)) if kind=='ocr' else config.get('analysis_enabled',True)) and kind in ('descriptions','ocr','both')
                            config.setdefault('embedding',{})['enabled']=config.get('embedding',{}).get('enabled',False) and kind=='embeddings'
                            if not control.get('reset_requested') and not is_due(config,control,schedule):continue
                            if schedule.get('embedding_pending') and not embedding_ready and not control.get('requested') and not control.get('reset_requested'):continue
                            child=reporter if account_directory==directory else Reporter(account_directory,kind=kind)
                            child.timezone=config['timezone']
                            if control.get('reset_requested'):
                                if not control.get('paused',True):raise p.PipelineError('Reset requires paused worker')
                                reset_ai(scoped_api,account_directory,child,control)
                            elif kind=='embeddings' and detect_embedding_rebuild(config,account_directory,child):
                                child.update(state='paused',current=None,message='Modellwechsel: Bitte zuerst den Neuaufbau der Bildvektoren bestätigen.')
                                continue
                            else:
                                reporter.update(active_user_id=uid)
                                consume(account_directory,control.get('request_id'),kind)
                                result_date=datetime.now(ZoneInfo(config['timezone'])).strftime('%Y-%m-%d')
                                result=(faces_cycle if kind=='faces' else cycle)(scoped_api,config,account_directory,child,control)
                                atomic(lane_file(account_directory,'schedule.json',kind),{'date':result_date,'embedding_pending':result['embedding']['state']=='preparing'})
                                if not config['enabled'] and result['embedding']['state']!='preparing':
                                    with (account_directory/'control.lock').open('a') as account_lock:
                                        fcntl.flock(account_lock,fcntl.LOCK_EX)
                                        current=read(lane_file(account_directory,'control.json',kind),{})
                                        if not current.get('requested') and current.get('request_id')==control.get('request_id'):
                                            current['paused']=True;atomic(lane_file(account_directory,'control.json',kind),current)
                            active=True
                        except (p.PipelineError,ValueError,KeyError,TypeError,OSError) as error:
                            if child:child.event('worker_error',level='error',error=str(error)[:300]);child.update(state='error',current=None,message=str(error)[:300])
                        finally:
                            if processing_lock is not None:
                                fcntl.flock(processing_lock,fcntl.LOCK_UN);processing_lock.close()
                            reporter.update(active_user_id=None)
                            if child is not None and child is not reporter:child.close(preserve=True)
                        if active:break  # One account at a time; explicit requests precede schedules.
                    cutoff=datetime.now()-timedelta(days=global_config['log_days'])
                    for _,account_directory in candidates:
                        for log in (account_directory/'logs').glob('????-??-??.jsonl'):
                            if not log.is_symlink() and log.stat().st_mtime<cutoff.timestamp():log.unlink()
                except BlockingIOError:
                    reporter.update(state='idle',current=None,message='Modellwechsel wird abgeschlossen.')
                except (p.PipelineError,ValueError,KeyError,TypeError,OSError) as error:
                    reporter.event('worker_error',level='error',error=str(error)[:300]);reporter.update(state='error',current=None,message=str(error)[:300])
                if args.once:break
                _shutdown.wait(5)
        finally:
            if kind=='embeddings':embedding_setup.stop()
            if kind=='faces':
                import faces_setup
                faces_setup.stop()
            if _local_embedding_process is not None and _local_embedding_process.poll() is None:_local_embedding_process.terminate()
            reporter.close()
            atomic(lane_file(directory,'service.json',kind),{'heartbeat_at':0,'active_user_id':None})


def supervise(args,directory):
    """Keep independent processes alive; systemd still manages one service."""
    with (directory/'daemon.lock').open('a') as lock:
        try:fcntl.flock(lock,fcntl.LOCK_EX|fcntl.LOCK_NB)
        except BlockingIOError:raise SystemExit('Worker already active')
        children={};completed=set()
        try:
            while not _shutdown.is_set():
                for kind in PIPELINES:
                    child=children.get(kind)
                    if child is not None and child.poll() is not None:
                        if args.once:
                            if child.returncode:raise p.PipelineError('Pipeline worker failed: '+kind)
                            completed.add(kind)
                        del children[kind]
                    if kind not in children and kind not in completed:
                        command=[sys.executable,str(Path(__file__).resolve()),'--bootstrap',args.bootstrap,'--pipeline',kind]
                        if args.once:command.append('--once')
                        children[kind]=subprocess.Popen(command)
                if args.once and len(completed)==len(PIPELINES):break
                _shutdown.wait(5)
        finally:
            for child in children.values():
                if child.poll() is None:child.terminate()
            for child in children.values():
                try:child.wait(timeout=15)
                except subprocess.TimeoutExpired:child.kill();child.wait()

if __name__=='__main__':
    for sig in (signal.SIGTERM,signal.SIGINT):signal.signal(sig,lambda *_:_shutdown.set())
    main()
