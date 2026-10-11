"""Current unmodified pipeline, its default prompts/transport, artificial images only."""
import hashlib,json,sys,time
from pathlib import Path
ROOT=Path(__file__).resolve().parent
sys.path.insert(0,str(ROOT/'source-snapshot'))
import media_pipeline as p
config=json.loads((ROOT/'source-snapshot/config.example.json').read_text())
config.update(database=str(ROOT/'state/media.sqlite3'),source={'kind':'directory','directory':str(ROOT/'images')},nextcloud={'enabled':False})
# Use the app defaults unchanged; only source, DB and publication target differ.
for stage in ['vision','ocr']:
    config[stage].pop('api_key_env',None)
(ROOT/'pipeline-config.json').write_text(json.dumps(config,indent=2)+'\n')
def observe(event,**fields):
    if event in ['file_start','file_done','file_error','inference_attempt_start','inference_attempt_finished','inference_attempt_error','image_prepared']:
        print(json.dumps({'event':event,**fields},ensure_ascii=False,default=str),flush=True)
store=p.Store(config['database']);start=time.monotonic()
try:
    result=p.run(config,store,p.DirectorySource(config['source']),{s:p.Model(config[s],s) for s in ['vision','ocr']},limit=20,max_seconds=7200,observe=observe)
    result['wall_seconds']=time.monotonic()-start
    rows=[dict(r) for r in store.db.execute('SELECT path,status,metadata FROM files WHERE active=1 ORDER BY path')]
    for r in rows:
        if r['metadata']:r['metadata']=json.loads(r['metadata'])
    result['files']=rows
    (ROOT/'inference-results.json').write_text(json.dumps(result,ensure_ascii=False,indent=2)+'\n')
    print(json.dumps({k:v for k,v in result.items() if k!='files'},ensure_ascii=False),flush=True)
    assert result['processed']==20 and all(r['status']=='done' for r in rows), 'Inference incomplete; retain and inspect log'
    frozen=json.loads((ROOT/'fixtures.json').read_text()); bypath={r['path']:r['metadata'] for r in rows}
    for d in frozen['documents']:
        m=bypath[d['path']];d.update(kind=m['vision']['type'],description=m['vision']['description'],tags=m['vision']['tags'],objects=m['vision']['objects'],ocr=m['ocr']['text'],metadata=m)
    (ROOT/'dataset.json').write_text(json.dumps(frozen,ensure_ascii=False,indent=2)+'\n')
    before=json.loads((ROOT/'provenance.json').read_text())['images']
    assert before=={f.name:hashlib.sha256(f.read_bytes()).hexdigest() for f in (ROOT/'images').iterdir()}
finally:store.db.close()
