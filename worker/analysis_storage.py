"""Split the legacy analysis cache without downloading or reanalysing media."""
import fcntl,json,os,sqlite3,uuid
from pathlib import Path


def path(directory,kind):
    if kind not in ('descriptions','ocr'):raise ValueError('Invalid analysis pipeline')
    return Path(directory)/('native-'+kind+'.sqlite3')


def initialize(directory):
    directory=Path(directory);marker=directory/'analysis-storage.json'
    with (directory/'analysis-storage.lock').open('a') as lock:
        fcntl.flock(lock,fcntl.LOCK_EX)
        if marker.exists():return
        legacy=directory/'native.sqlite3'
        if legacy.is_symlink():raise ValueError('Unsafe legacy analysis cache')
        if legacy.exists():
            source=sqlite3.connect('file:'+str(legacy)+'?mode=ro',uri=True)
            try:
                for kind,stage in [('descriptions','vision'),('ocr','ocr')]:
                    target=path(directory,kind);temporary=target.with_name(target.name+'.'+uuid.uuid4().hex)
                    db=sqlite3.connect(temporary)
                    try:
                        source.backup(db)
                        db.row_factory=sqlite3.Row
                        with db:
                            db.execute('DELETE FROM locks')
                            db.execute('DELETE FROM results WHERE stage<>?',(stage,))
                            for row in db.execute('SELECT id,mime,status,metadata FROM files').fetchall():
                                metadata=json.loads(row['metadata']) if row['metadata'] else {}
                                result=metadata.get(stage)
                                complete=(isinstance(result,dict) and (bool(result.get('description')) if stage=='vision' else isinstance(result.get('text'),str) and result.get('source')!='skipped_video'))
                                if kind=='ocr' and row['mime'].startswith('video/'):
                                    db.execute('UPDATE files SET active=0 WHERE id=?',(row['id'],));continue
                                if row['status']=='done' and complete:
                                    value={k:v for k,v in metadata.items() if k not in ('vision','ocr')};value[stage]=result
                                    if 'provenance' in value:
                                        value['provenance']=dict(value['provenance']);value['provenance']['models']={stage:value['provenance'].get('models',{}).get(stage,{})}
                                    db.execute('UPDATE files SET metadata=? WHERE id=?',(json.dumps(value,ensure_ascii=False),row['id']))
                                else:
                                    db.execute("UPDATE files SET status='pending',error=NULL,attempts=0,retry_at=0,published_revision=NULL WHERE id=?",(row['id'],))
                            db.execute("UPDATE search SET ocr=''" if stage=='vision' else "UPDATE search SET description='',tags=''")
                        db.execute('PRAGMA wal_checkpoint(TRUNCATE)')
                    finally:db.close()
                    temporary.chmod(0o600);temporary.replace(target)
            finally:source.close()
        for kind in ('descriptions','ocr'):
            cache=path(directory,kind)
            if not cache.exists():continue
            db=sqlite3.connect('file:'+str(cache)+'?mode=ro',uri=True)
            try:stats=dict(db.execute('SELECT status,COUNT(*) FROM files WHERE active=1 GROUP BY status'))
            finally:db.close()
            status=directory/('status-'+kind+'.json')
            try:value=json.loads(status.read_text())
            except (FileNotFoundError,ValueError):value={}
            if not isinstance(value,dict):value={}
            value['stats']=stats;value['ocr_stats' if kind=='ocr' else 'analysis_stats']=stats
            temp=status.with_name(status.name+'.'+uuid.uuid4().hex);temp.write_text(json.dumps(value,ensure_ascii=False));temp.chmod(0o600);temp.replace(status)
        temporary=marker.with_name(marker.name+'.'+uuid.uuid4().hex);temporary.write_text(json.dumps({'version':1,'legacy_preserved':legacy.exists()}));temporary.chmod(0o600);temporary.replace(marker)


def _reset_legacy(directory,kind):
    """Explicit stage deletion also removes that stage from the retained old cache."""
    legacy=Path(directory)/'native.sqlite3'
    if not legacy.exists():return
    db=sqlite3.connect(legacy)
    try:
        with db:
            if kind=='all':
                for table in ('files','results','search','locks'):db.execute('DELETE FROM '+table)
            else:
                stage='vision' if kind=='descriptions' else 'ocr'
                db.execute('DELETE FROM results WHERE stage=?',(stage,))
                for identifier,wire in db.execute('SELECT id,metadata FROM files WHERE metadata IS NOT NULL').fetchall():
                    value=json.loads(wire);value.pop(stage,None)
                    db.execute('UPDATE files SET metadata=? WHERE id=?',(json.dumps(value,ensure_ascii=False),identifier))
                db.execute("UPDATE search SET description='',tags=''" if stage=='vision' else "UPDATE search SET ocr=''")
    finally:db.close()


def reset_legacy(directory,kind):
    with (Path(directory)/'analysis-storage.lock').open('a') as lock:
        fcntl.flock(lock,fcntl.LOCK_EX)
        _reset_legacy(directory,kind)
