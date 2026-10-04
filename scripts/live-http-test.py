#!/usr/bin/env python3
"""HTTP tests against only the disposable account and generated fixtures."""
import requests,json,re,html,time,statistics
from pathlib import Path
credentials=json.loads(Path('artifacts/test-auth.json').read_text())
origin='https://10.1.10.186';base=origin+'/index.php/apps/chronicle';ca='../nextcloud-media-ai-pipeline/nextcloud-local-ca.crt'
s=requests.Session();s.verify=ca;s.auth=(credentials['user'],credentials['password'])
r=s.get(base+'/api/settings',timeout=20)
if 'application/json' not in r.headers.get('Content-Type',''):
 s.auth=None;r=s.get(origin+'/index.php/login',timeout=20)
 token=html.unescape(re.search(r'data-requesttoken="([^"]+)"',r.text).group(1))
 r=s.post(origin+'/index.php/login',data={'user':credentials['user'],'password':credentials['password'],'requesttoken':token,'redirect_url':'/index.php/apps/chronicle/'},timeout=30)
 r=s.get(base+'/api/settings',timeout=20)
assert r.status_code==200 and 'settings'in r.json(),(r.status_code,r.headers.get('content-type'))
page=s.get(base+'/',timeout=20)
token=html.unescape(re.search(r'data-requesttoken="([^"]+)"',page.text).group(1))
headers={'requesttoken':token}
results=[]
def record(name,ok,detail=None):
 assert ok,(name,detail);results.append({'test':name,'pass':True,'detail':detail})
def get(path,**kw):return s.get(base+path,timeout=30,**kw)
def put(settings,valid=True):
 r=s.put(base+'/api/settings',json={'settings':settings},headers=headers,timeout=30)
 assert r.status_code==(200 if valid else 400),(r.status_code,r.text[:180]);return r
# Account scope, current read access, root allowlist and CSRF.
summary=get('/api/summary').json();record('four selected media only',summary['total']==4,summary['total'])
items=get('/api/items').json()['items'];record('index contains only selected IDs',set(i['id'] for i in items)==set(credentials['ids'].values()))
r=get('/preview/'+str(credentials['blocked']));record('unselected preview denied',r.status_code==404,r.status_code)
r=get('/original/'+str(credentials['blocked']));record('unselected original denied',r.status_code==404,r.status_code)
for name,value in [('home root',{'roots':['/']}),('traversal',{'roots':['/../Unselected']}),('invalid date mode',{'mode':'invalid'})]:
 r=put(value,False);record('reject '+name,r.status_code==400)
r=s.put(base+'/api/settings',json={'settings':{'grouping':'year'}},timeout=20);record('mutation without CSRF token denied',r.status_code in [403,412],r.status_code)
for mode,expected in [('folder','2024-08'),('metadata','2025-01-15'),('filename','2025-01-15')]:
 put({'mode':mode})
 while s.post(base+'/api/scan',json={},headers=headers,timeout=30).json().get('running'):pass
 values=get('/api/items').json()['items'];conflict=next(i for i in values if i['name']=='conflict_DEMO.jpg')
 record('date priority '+mode,conflict['date']['date']==expected,conflict['date'])
put({'mode':'folder','grouping':'month','order':'desc'})
while s.post(base+'/api/scan',json={},headers=headers,timeout=30).json().get('running'):pass
values=get('/api/items').json()['items'];vid=next(i for i in values if i['kind']=='video');record('video duration and size metadata',vid['metadata']['duration']==3 and vid['metadata']['width']==960,vid['metadata'])
# Preview cache, video byte range and unsupported/malformed ranges.
photo=credentials['ids']['IMG_20261013_193000_DEMO.jpg'];video=credentials['ids']['VID_20261012_120000_DEMO.mp4']
r=get('/preview/'+str(photo));record('JPEG preview generated',r.status_code==200 and r.headers.get('content-type','').startswith('image/'),{'status':r.status_code,'bytes':len(r.content)})
etag=r.headers['ETag'];cached=get('/preview/'+str(photo),headers={'If-None-Match':etag});record('unchanged preview returns 304',cached.status_code==304,cached.status_code)
r=get('/original/'+str(video),headers={'Range':'bytes=0-1023'});record('video range 206',r.status_code==206 and len(r.content)==1024 and r.headers.get('Content-Range','').startswith('bytes 0-1023/'),{'status':r.status_code,'range':r.headers.get('Content-Range')})
r=get('/original/'+str(video),headers={'Range':'bytes=999999999-'});record('invalid video range 416',r.status_code==416,r.status_code)
r=get('/original/'+str(video),headers={'Range':'bytes=-128'});record('suffix video range',r.status_code==206 and len(r.content)==128)
# Zero coordinates must not be filtered out: GPS test exercises the PHP resolver separately.
geo=get('/api/map').json();record('GPS media returned',len(geo['items'])>0,len(geo['items']))
offline=get('/offline');record('independent authenticated offline page',offline.status_code==200 and 'core-common' not in offline.text and 'data-context' in offline.text)
nonce=re.search(r'<script nonce="([^"]+)"',offline.text).group(1)
record('offline script nonce matches CSP',"'nonce-"+nonce+"'" in offline.headers.get('Content-Security-Policy',''))
sw=get('/sw.js');record('scoped service worker',sw.status_code==200 and 'javascript' in sw.headers['Content-Type'] and sw.headers.get('Service-Worker-Allowed','').endswith('/apps/chronicle/'))
# Verify actual Android capability and link payload, without claiming a device test.
r=s.get(origin+'/ocs/v2.php/cloud/capabilities?format=json',headers={'OCS-APIRequest':'true'},timeout=20);caps=r.json()['ocs']['data']['capabilities'];record('external links capability',bool(caps.get('external',{})))
r=s.get(origin+'/ocs/v2.php/apps/external/api/v1?format=json',headers={'OCS-APIRequest':'true','User-Agent':'Mozilla/5.0 (Android) Nextcloud-android/3.35.0'},timeout=20);links=r.json()['ocs']['data'];record('Android Chronik menu link',any(x['name']=='Chronik' and x['url']==base+'/' and not x['redirect'] for x in links),[{'name':x['name'],'redirect':x['redirect']} for x in links])
timings=[]
for _ in range(8):
 begin=time.perf_counter();r=get('/api/items');assert r.status_code==200;timings.append((time.perf_counter()-begin)*1000)
results.append({'test':'warm HTTP items latency on 4 synthetic records','pass':True,'median_ms':round(statistics.median(timings),1),'max_ms':round(max(timings),1)})
Path('artifacts/http-validation.json').write_text(json.dumps(results,indent=2));print(json.dumps(results,indent=2))
