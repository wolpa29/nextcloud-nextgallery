"""Actual stock Nextcloud 32 OCS files search, synthetic account on loopback only."""
import base64,json,os,time,urllib.request,urllib.parse
from pathlib import Path
ROOT=Path(__file__).resolve().parent
data=json.loads((ROOT/'fixtures.json').read_text());BASE=os.environ.get('BENCH_NC_URL','http://127.0.0.1:32771')
auth='Basic '+base64.b64encode(b'benchmark:SyntheticBenchmarkOnly2026').decode()
def call(path,method='GET',body=None,headers=None):
    r=urllib.request.Request(BASE+path,data=body,method=method,headers={'Authorization':auth,'OCS-APIRequest':'true',**(headers or {})})
    with urllib.request.urlopen(r,timeout=120) as f:return f.read()
call('/remote.php/dav/files/benchmark/SearchBenchmark',method='MKCOL')
for d in data['documents']:
    call('/remote.php/dav/files/benchmark/SearchBenchmark/'+urllib.parse.quote(d['path']),method='PUT',body=(ROOT/'images'/d['path']).read_bytes(),headers={'Content-Type':'image/png'})
providers=json.loads(call('/ocs/v2.php/search/providers?format=json'))
out={'nextcloud_version':'32.0.5','core_files_provider':True,'database':'sqlite','only_synthetic_account':True,'providers':providers['ocs']['data'],'queries':{}}
paths={d['path']:d['key'] for d in data['documents']}
for q in data['queries']:
    path='/ocs/v2.php/search/providers/files/search?'+urllib.parse.urlencode({'format':'json','term':q['text'],'limit':50,'path':'/SearchBenchmark'})
    start=time.perf_counter();result=json.loads(call(path));ms=(time.perf_counter()-start)*1000
    assert result['ocs']['meta']['status']=='ok',result
    entries=result['ocs']['data']['entries'];keys=[]
    for e in entries:
        if e['title'] in paths:keys.append(paths[e['title']])
    out['queries'][q['id']]={'keys':keys,'ms':ms,'raw_response':result}
(ROOT/'normal-nextcloud.json').write_text(json.dumps(out,ensure_ascii=False,indent=2)+'\n')
print('Stock Nextcloud OCS search completed:',len(out['queries']),'queries')
