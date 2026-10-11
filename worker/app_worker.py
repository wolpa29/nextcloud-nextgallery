"""Bundled daemon. Native Nextcloud API, private state, per-image time budgets."""
from __future__ import annotations
import argparse
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

_local_embedding_process = None
_local_embedding_signature = None
_embedding_service_check = (None, 0.0)


def atomic(path, value):
    temp=path.with_name(path.name+'.'+uuid.uuid4().hex)
    temp.write_text(json.dumps(value,ensure_ascii=False))
    temp.chmod(0o600)
    temp.replace(path)


def read(path, fallback):
    try: return json.loads(path.read_text())
    except (FileNotFoundError,ValueError): return fallback


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
        self.scope=p.digest(['native',getattr(api,'scope_url',api.url),config['user_id'],config['folders'],config['exclude_folders']])
    def inventory(self):
        cursor=0; items=[]; seen=set()
        while True:
            page=self.api.call('inventory?'+urllib.parse.urlencode({'cursor':cursor}))
            for row in page['items']:
                identifier=str(row['file_id'])
                if identifier in seen: raise p.PipelineError('Duplicate inventory identity')
                if not row['etag']: raise p.PipelineError('Image has no ETag')
                seen.add(identifier)
                items.append(p.Item(self.scope+':'+identifier,row['path'],identifier,row['etag'],int(row['size']),row['mime'],row.get('analyzed_etag') or ''))
            next_cursor=page['cursor']
            if next_cursor is None: return items
            if not isinstance(next_cursor,int) or next_cursor<=cursor: raise p.PipelineError('Invalid inventory pagination')
            cursor=next_cursor
    def read(self,item,maximum):
        wire,headers=self.api.wire('image?'+urllib.parse.urlencode({'file_id':item.locator,'etag':item.etag}),maximum=maximum)
        returned=p.etag(next((v for k,v in headers.items() if k.lower()=='etag'),''))
        if returned != item.etag: raise p.PipelineError('Image changed while downloading')
        return wire
    def download_to(self,item,destination,maximum):
        url=self.api.url+'image?'+urllib.parse.urlencode({'file_id':item.locator,'etag':item.etag})
        with p.open_stream(url,'GET',None,self.api.headers,timeout=120,ca_file=self.api.ca_file) as stream:
            if p.etag(stream.headers.get('ETag','')) != item.etag: raise p.PipelineError('Media changed while downloading')
            return p.copy_media(stream,destination,maximum)
    def unchanged(self,item): return self.api.call('resolve?file_id='+item.locator)['etag']==item.etag


class NativePublisher:
    def __init__(self,api,config):
        # Publication identity participates in the analysis revision; keep it stable across the app move.
        self.api=api; self.config={'url':getattr(api,'scope_url',api.url),'user_id':config['user_id']}
    def capture(self,item,source):
        target=self.api.call('resolve?file_id='+item.locator)
        if target['etag']!=item.etag: raise p.PipelineError('Image changed before analysis')
        return {'file_id':int(item.locator),'etag':item.etag}
    def send(self,row):
        metadata=json.loads(row['metadata'])
        self.api.call('publish',{**metadata['nextcloud_target'],'sha256':row['sha256'],'revision':row['revision'],'metadata':metadata,'exif':json.loads(row['exif'])})


class Reporter:
    def __init__(self,directory,service=False):
        self.service=service
        self.directory=directory; self.directory.joinpath('logs').mkdir(mode=0o700,exist_ok=True)
        self.value=read(directory/'status.json',{})
        self.value.update(state='idle',current=None)
        self.lock=threading.RLock(); self.stop=threading.Event(); self.run_id=None; self.timezone="Europe/Vienna"
        self.thread=threading.Thread(target=self.beat,daemon=True); self.thread.start()
    def beat(self):
        while not self.stop.is_set():
            with self.lock:
                self.value['heartbeat_at']=time.time(); atomic(self.directory/'status.json',self.value)
                if self.service:atomic(self.directory/'service.json',{'heartbeat_at':self.value['heartbeat_at'],'active_user_id':self.value.get('active_user_id')})
            self.stop.wait(5)
    def update(self,**fields):
        with self.lock:
            self.value.update(fields); self.value['heartbeat_at']=time.time(); atomic(self.directory/'status.json',self.value)
            if self.service:atomic(self.directory/'service.json',{'heartbeat_at':self.value['heartbeat_at'],'active_user_id':self.value.get('active_user_id')})
    def event(self,event,**fields):
        if event == 'inference_progress':
            self.update(current={k:fields[k] for k in ['path','file_id','stage','attempt','total_attempts','reasoning_chars','content_chars']})
            return  # Live progress is status, not an additional activity-log row.
        now=datetime.now(ZoneInfo(self.timezone))
        row={'time':now.isoformat(timespec='seconds'),'run_id':self.run_id,'event':event,'level':fields.pop('level','info'),**fields}
        path=self.directory/'logs'/ (now.strftime('%Y-%m-%d')+'.jsonl')
        with path.open('a') as stream: stream.write(json.dumps(row,ensure_ascii=False)+'\n')
        changes={}
        if 'stats' in fields: changes['stats']=fields['stats']; changes['analysis_stats']=fields['stats']
        if 'embedding_stats' in fields:
            changes['embedding_stats']=fields['embedding_stats']
            changes['stats']={key:self.value.get('analysis_stats',{}).get(key,0)+fields['embedding_stats'].get(key,0) for key in ('done','pending','error')}
        if 'embedding_space' in fields: changes['embedding_space']=fields['embedding_space']
        if event in {'file_start','stage_start'}: changes['current']={k:fields[k] for k in ['path','file_id','stage']}; changes['stage_started_at']=time.time()
        if event == 'inference_attempt_start': changes['current']={k:fields[k] for k in ['path','file_id','stage','attempt','total_attempts']}
        if event in {'file_finished','file_error','embedding_finished','embedding_error'}: changes['current']=None
        if changes: self.update(**changes)
    def close(self,preserve=False):
        self.stop.set(); self.thread.join()
        self.value.update(heartbeat_at=0)
        if not preserve:self.value['state']='stopped'
        atomic(self.directory/'status.json',self.value)


def consume(directory, request_id):
    with (directory/'control.lock').open('a') as stream:
        fcntl.flock(stream,fcntl.LOCK_EX)
        control=read(directory/'control.json',{'paused':True})
        if control.get('request_id')==request_id:
            control['requested']=False; control['retry_errors']=False; atomic(directory/'control.json',control)


def pause_unavailable(directory, reason):
    with (directory/'control.lock').open('a') as stream:
        fcntl.flock(stream,fcntl.LOCK_EX)
        control=read(directory/'control.json',{})
        control.update(paused=True,requested=False,retry_errors=False,pause_reason=reason,updated_at=time.time())
        atomic(directory/'control.json',control)


def waiting_status(control):
    paused=control.get('paused',True)
    return {'state':'paused' if paused else 'idle', 'current':None,
            'message':control.get('pause_reason','') if paused else ''}


def reset_ai(api,directory,reporter,control):
    api.call('reset',{})
    kind=control.get('reset_kind','all')
    if kind in ('all','descriptions'):
        store=p.Store(directory/'native.sqlite3')
        try:
            with store.db:
                for table in ['files','results','search','locks']:store.db.execute('DELETE FROM '+table)
        finally:store.db.close()
    if kind in ('all','embeddings'):
        embeddings=embedding.Store(directory/'embeddings.sqlite3')
        try:embeddings.reset()
        finally:embeddings.db.close()
    with (directory/'control.lock').open('a') as stream:
        fcntl.flock(stream,fcntl.LOCK_EX)
        current=read(directory/'control.json',{})
        if current.get('reset_request_id')==control.get('reset_request_id'):
            current['reset_requested']=False;atomic(directory/'control.json',current)
    remaining=reporter.value.get('embedding_stats' if kind=='descriptions' else 'analysis_stats',{}) if kind!='all' else {}
    reporter.update(stats={key:remaining.get(key,0) for key in ('done','pending','error')},**({'analysis_stats':{}} if kind=='descriptions' else {'embedding_stats':{}} if kind=='embeddings' else {'analysis_stats':{},'embedding_stats':{}}),last_run=None,current=None,state='paused',message='KI-Daten zurückgesetzt')
    reporter.event('ai_reset_finished',kind=kind)


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
    config['analysis_enabled']=config.get('analysis_enabled',True) and kind!='embeddings'
    config.setdefault('embedding',{'enabled':False})
    config['embedding']['enabled']=config['embedding'].get('enabled',False) and kind!='descriptions'
    store=p.Store(directory/'native.sqlite3')
    started=time.monotonic()
    try:
        with store.db:
            store.db.execute('DELETE FROM locks')
            if control.get('retry_errors'):store.db.execute("UPDATE files SET retry_at=0 WHERE status='error'")
        source=NativeSource(api,config)
        with store.db:store.db.execute('UPDATE files SET active=0 WHERE scope<>?',(source.scope,))
        reporter.run_id=uuid.uuid4().hex
        reporter.update(state='running',current=None,started_at=time.time(),message='Ausgewählte Verarbeitungsschritte',analysis_enabled=config.get('analysis_enabled',True),embedding_enabled=config.get('embedding',{}).get('enabled',False),analysis_stats={} if not config.get('analysis_enabled',True) else store.stats())
        reporter.event('run_start',kind=kind)
        stop=lambda:read(directory/'control.json',{'paused':True}).get('paused',True) or read(Path(config.get('_model_directory',directory))/'processing-admin.json',{}).get('paused',False)
        analysis={'state':'disabled','processed':0,'errors':0,'published':0,'cached_stage_results':0}
        if config.get('analysis_enabled',True):
            models={}
            for stage in ['vision','ocr']:
                data=dict(config[stage]);secret=data.pop('api_key','');name='MEDIA_AI_'+stage.upper()+'_KEY'
                os.environ[name]=secret;data['api_key_env']=name;models[stage]=p.Model(data,stage)
            analysis=p.run(config,store,source,models,limit=0,max_seconds=0,publisher=NativePublisher(api,config),observe=reporter.event,should_stop=stop)
        reporter.update(analysis_stats=store.stats() if config.get('analysis_enabled',True) else {})
        dense={'state':'disabled','processed':0,'errors':0,'stats':{'done':0,'pending':0,'error':0}}
        if config.get('embedding',{}).get('enabled') and not stop():
            reporter.update(current=None,message='Bildsuche vorbereiten')
            try:
                ensure_local_embedding(config,Path(config.get('_model_directory',directory)))
                dense=embedding.run(config,source,api,directory,reporter.event,stop,bool(control.get('retry_errors')))
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
            result['reason']='; '.join(reasons);pause_unavailable(directory,result['reason']);reporter.update(paused=True)
        reporter.event('run_finished',**result)
        reporter.update(state=state,last_run=result,stats=stats,analysis_stats=analysis_stats,embedding_stats=dense['stats'],
                        current=None,message=result.get('reason','Bildsuche wartet auf die Gemma-Installation.' if dense['state']=='preparing' else 'Lauf abgeschlossen'))
        return result
    finally:store.db.close()



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
        old=json.loads(mismatched[0][1]);dimensions=len(old[0]['values']) if old else None
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
    parser=argparse.ArgumentParser();parser.add_argument('--bootstrap',required=True);parser.add_argument('--once',action='store_true');args=parser.parse_args()
    directory=Path(args.bootstrap).resolve().parent
    with (directory/'daemon.lock').open('a') as lock:
        try:fcntl.flock(lock,fcntl.LOCK_EX|fcntl.LOCK_NB)
        except BlockingIOError:raise SystemExit('Worker already active')
        bootstrap=read(Path(args.bootstrap),{});api=API(bootstrap);reporter=Reporter(directory,service=True)
        try:
            while True:
                try:
                    global_config=api.call('config');reporter.timezone=global_config['timezone']
                    reporter.update(embedding_setup_supported=True,embedding_setup_profiles=['lite','full'],embedding_remove_supported=True)
                    embedding_ready=maintain_embedding_service(global_config,directory,reporter)
                    candidates=account_directories(directory,global_config['user_id'])
                    candidates.sort(key=lambda item:(not read(item[1]/'control.json',{}).get('reset_requested'),not read(item[1]/'control.json',{}).get('requested'),read(item[1]/'control.json',{}).get('updated_at',0),item[0]))
                    active=False
                    if read(directory/'processing-admin.json',{}).get('paused',False):
                        candidates=[]
                    for uid,account_directory in candidates:
                        scoped_api=API(bootstrap,uid)
                        control=read(account_directory/'control.json',{'paused':True});schedule=read(account_directory/'schedule.json',{})
                        if not control.get('reset_requested') and control.get('paused',True):
                            if account_directory==directory:reporter.update(**waiting_status(control))
                            continue
                        child=None;processing_lock=None
                        try:
                            processing_lock=(directory/'processing.lock').open('a')
                            fcntl.flock(processing_lock,fcntl.LOCK_SH|fcntl.LOCK_NB)
                            if read(directory/'processing-admin.json',{}).get('paused',False):continue
                            control=read(account_directory/'control.json',{'paused':True})
                            if not control.get('reset_requested') and control.get('paused',True):continue
                            config=scoped_api.call('config');config['_model_directory']=str(directory)
                            if not control.get('reset_requested') and not is_due(config,control,schedule):continue
                            if schedule.get('embedding_pending') and not embedding_ready and not control.get('requested') and not control.get('reset_requested'):continue
                            child=reporter if account_directory==directory else Reporter(account_directory)
                            child.timezone=config['timezone']
                            if control.get('reset_requested'):
                                if not control.get('paused',True):raise p.PipelineError('Reset requires paused worker')
                                reset_ai(scoped_api,account_directory,child,control)
                            elif control.get('run_kind','both')!='descriptions' and detect_embedding_rebuild(config,account_directory,child):
                                child.update(state='paused',current=None,message='Modellwechsel: Bitte zuerst den Neuaufbau der Bildvektoren bestätigen.')
                                continue
                            else:
                                reporter.update(active_user_id=uid)
                                consume(account_directory,control.get('request_id'))
                                result_date=datetime.now(ZoneInfo(config['timezone'])).strftime('%Y-%m-%d')
                                result=cycle(scoped_api,config,account_directory,child,control)
                                atomic(account_directory/'schedule.json',{'date':result_date,'embedding_pending':result['embedding']['state']=='preparing'})
                                if not config['enabled'] and result['embedding']['state']!='preparing':
                                    with (account_directory/'control.lock').open('a') as account_lock:
                                        fcntl.flock(account_lock,fcntl.LOCK_EX)
                                        current=read(account_directory/'control.json',{})
                                        if not current.get('requested') and current.get('request_id')==control.get('request_id'):
                                            current['paused']=True;atomic(account_directory/'control.json',current)
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
                except (p.PipelineError,ValueError,KeyError,TypeError,OSError) as error:
                    reporter.event('worker_error',level='error',error=str(error)[:300]);reporter.update(state='error',current=None,message=str(error)[:300])
                if args.once:break
                time.sleep(5)
        finally:
            embedding_setup.stop()
            if _local_embedding_process is not None and _local_embedding_process.poll() is None:_local_embedding_process.terminate()
            reporter.close()
            atomic(directory/'service.json',{'heartbeat_at':0,'active_user_id':None})

if __name__=='__main__':main()
