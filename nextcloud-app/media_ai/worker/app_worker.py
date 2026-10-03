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


def atomic(path, value):
    temp=path.with_name(path.name+'.'+uuid.uuid4().hex)
    temp.write_text(json.dumps(value,ensure_ascii=False))
    temp.chmod(0o600)
    temp.replace(path)


def read(path, fallback):
    try: return json.loads(path.read_text())
    except (FileNotFoundError,ValueError): return fallback


class API:
    def __init__(self,bootstrap):
        parts=urllib.parse.urlsplit(bootstrap['url'])
        if parts.scheme != 'https' or parts.username or parts.password or parts.query or parts.fragment:
            raise p.PipelineError('Native worker requires canonical HTTPS Nextcloud URL')
        self.url=bootstrap['url'].rstrip('/')+'/index.php/apps/media_ai/worker/'
        self.headers={'Authorization':'Bearer '+bootstrap['token'],'Content-Type':'application/json','Accept':'application/json'}
        self.ca_file=bootstrap.get('ca_file')
    def wire(self,endpoint,body=None,maximum=2*1024*1024):
        return p.request(self.url+endpoint,'POST' if body is not None else 'GET',json.dumps(body,ensure_ascii=False).encode() if body is not None else None,self.headers,timeout=120,maximum=maximum,ca_file=self.ca_file)
    def call(self,endpoint,body=None):
        wire,_=self.wire(endpoint,body)
        return json.loads(wire)


class NativeSource:
    def __init__(self,api,config):
        self.api=api
        self.scope=p.digest(['native',api.url,config['user_id'],config['folders'],config['exclude_folders']])
    def inventory(self):
        cursor=0; items=[]; seen=set()
        while True:
            page=self.api.call('inventory?'+urllib.parse.urlencode({'cursor':cursor}))
            for row in page['items']:
                identifier=str(row['file_id'])
                if identifier in seen: raise p.PipelineError('Duplicate inventory identity')
                if not row['etag']: raise p.PipelineError('Image has no ETag')
                seen.add(identifier)
                items.append(p.Item(self.scope+':'+identifier,row['path'],identifier,row['etag'],int(row['size']),row['mime']))
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
        self.api=api; self.config={'url':api.url,'user_id':config['user_id']}
    def capture(self,item,source):
        target=self.api.call('resolve?file_id='+item.locator)
        if target['etag']!=item.etag: raise p.PipelineError('Image changed before analysis')
        return {'file_id':int(item.locator),'etag':item.etag}
    def send(self,row):
        metadata=json.loads(row['metadata'])
        self.api.call('publish',{**metadata['nextcloud_target'],'sha256':row['sha256'],'revision':row['revision'],'metadata':metadata,'exif':json.loads(row['exif'])})


class Reporter:
    def __init__(self,directory):
        self.directory=directory; self.directory.joinpath('logs').mkdir(mode=0o700,exist_ok=True)
        self.value=read(directory/'status.json',{})
        self.value.update(state='idle',current=None)
        self.lock=threading.RLock(); self.stop=threading.Event(); self.run_id=None; self.timezone="Europe/Vienna"
        self.thread=threading.Thread(target=self.beat,daemon=True); self.thread.start()
    def beat(self):
        while not self.stop.is_set():
            with self.lock:
                self.value['heartbeat_at']=time.time(); atomic(self.directory/'status.json',self.value)
            self.stop.wait(5)
    def update(self,**fields):
        with self.lock:
            self.value.update(fields); self.value['heartbeat_at']=time.time(); atomic(self.directory/'status.json',self.value)
    def event(self,event,**fields):
        if event == 'inference_progress':
            self.update(current={k:fields[k] for k in ['path','file_id','stage','attempt','total_attempts','reasoning_chars','content_chars']})
            return  # Live progress is status, not an additional activity-log row.
        now=datetime.now(ZoneInfo(self.timezone))
        row={'time':now.isoformat(timespec='seconds'),'run_id':self.run_id,'event':event,'level':fields.pop('level','info'),**fields}
        path=self.directory/'logs'/ (now.strftime('%Y-%m-%d')+'.jsonl')
        with path.open('a') as stream: stream.write(json.dumps(row,ensure_ascii=False)+'\n')
        changes={}
        if 'stats' in fields: changes['stats']=fields['stats']
        if event in {'file_start','stage_start'}: changes['current']={k:fields[k] for k in ['path','file_id','stage']}; changes['stage_started_at']=time.time()
        if event == 'inference_attempt_start': changes['current']={k:fields[k] for k in ['path','file_id','stage','attempt','total_attempts']}
        if event in {'file_finished','file_error'}: changes['current']=None
        if changes: self.update(**changes)
    def close(self):
        self.stop.set(); self.thread.join()
        self.value.update(state='stopped',heartbeat_at=0)
        atomic(self.directory/'status.json',self.value)


def consume(directory, request_id):
    with (directory/'control.lock').open('a') as stream:
        fcntl.flock(stream,fcntl.LOCK_EX)
        control=read(directory/'control.json',{'paused':True})
        if control.get('request_id')==request_id:
            control['requested']=False; control['retry_errors']=False; atomic(directory/'control.json',control)


def reset_ai(api,directory,reporter,control):
    api.call('reset',{})
    store=p.Store(directory/'native.sqlite3')
    try:
        with store.db:
            for table in ['files','results','search','locks']:store.db.execute('DELETE FROM '+table)
    finally:store.db.close()
    with (directory/'control.lock').open('a') as stream:
        fcntl.flock(stream,fcntl.LOCK_EX)
        current=read(directory/'control.json',{})
        if current.get('reset_request_id')==control.get('reset_request_id'):
            current['reset_requested']=False;atomic(directory/'control.json',current)
    reporter.update(stats={},last_run=None,current=None,state='paused',message='KI-Daten zurückgesetzt')
    reporter.event('ai_reset_finished')


def is_due(config,control,schedule,now=None):
    if control.get('paused',True): return False
    if control.get('requested'): return True
    if not config['enabled']: return False
    current=now or datetime.now(ZoneInfo(config['timezone']))
    return current.strftime('%H:%M')>=config['schedule_time'] and schedule.get('date')!=current.strftime('%Y-%m-%d')


def cycle(api,config,directory,reporter,control):
    store=p.Store(directory/'native.sqlite3')
    try:
        # Exclusive daemon flock makes crash recovery of its own lease safe.
        with store.db:
            store.db.execute('DELETE FROM locks')
            if control.get('retry_errors'): store.db.execute("UPDATE files SET retry_at=0 WHERE status='error'")
        source=NativeSource(api,config)
        with store.db: store.db.execute('UPDATE files SET active=0 WHERE scope<>?',(source.scope,))
        models={}
        for stage in ['vision','ocr']:
            data=dict(config[stage]); secret=data.pop('api_key',''); name='MEDIA_AI_'+stage.upper()+'_KEY'
            os.environ[name]=secret; data['api_key_env']=name
            models[stage]=p.Model(data,stage)
        reporter.run_id=uuid.uuid4().hex
        reporter.update(state='running',current=None,started_at=time.time(),message='Inventar und Analyse')
        reporter.event('run_start')
        result=p.run(config,store,source,models,limit=0,max_seconds=0,publisher=NativePublisher(api,config),observe=reporter.event,
            should_stop=lambda:read(directory/'control.json',{'paused':True}).get('paused',True))
        reporter.event('run_finished',**result)
        reporter.update(state=result['state'],last_run=result,stats=store.stats(),current=None,message=result.get('reason','Lauf abgeschlossen'))
    finally: store.db.close()


def main():
    os.umask(0o077)
    parser=argparse.ArgumentParser(); parser.add_argument('--bootstrap',required=True); parser.add_argument('--once',action='store_true'); args=parser.parse_args()
    directory=Path(args.bootstrap).resolve().parent
    with (directory/'daemon.lock').open('a') as lock:
        try: fcntl.flock(lock,fcntl.LOCK_EX|fcntl.LOCK_NB)
        except BlockingIOError: raise SystemExit('Worker already active')
        api=API(read(Path(args.bootstrap),{})); reporter=Reporter(directory)
        try:
            while True:
                try:
                    config=api.call('config'); reporter.timezone=config['timezone']; control=read(directory/'control.json',{'paused':True}); schedule=read(directory/'schedule.json',{})
                    reporter.update(paused=control.get('paused',True))
                    if control.get('reset_requested'):
                        if not control.get('paused',True):raise p.PipelineError('Reset requires paused worker')
                        reset_ai(api,directory,reporter,control)
                    elif is_due(config,control,schedule):
                        consume(directory,control.get('request_id'))
                        result_date=datetime.now(ZoneInfo(config['timezone'])).strftime('%Y-%m-%d')
                        cycle(api,config,directory,reporter,control)
                        atomic(directory/'schedule.json',{'date':result_date})
                    else: reporter.update(state='paused' if control.get('paused',True) else 'idle')
                    cutoff=datetime.now()-timedelta(days=config['log_days'])
                    for log in (directory/'logs').glob('????-??-??.jsonl'):
                        if log.stat().st_mtime<cutoff.timestamp(): log.unlink()
                except (p.PipelineError,ValueError,KeyError,TypeError,OSError) as error:
                    reporter.event('worker_error',level='error',error=str(error)[:300]); reporter.update(state='error',current=None,message=str(error)[:300])
                if args.once: break
                time.sleep(15)
        finally: reporter.close()

if __name__=='__main__': main()
