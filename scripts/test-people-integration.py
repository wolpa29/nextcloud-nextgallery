#!/usr/bin/env python3
"""Public image / synthetic People integration on an explicitly disposable Nextcloud."""
import argparse,base64,json,os,shutil,subprocess,sys,time,urllib.request,urllib.error
from pathlib import Path
ROOT=Path(__file__).resolve().parents[1]
parser=argparse.ArgumentParser();parser.add_argument('--seed-only',action='store_true');parser.add_argument('--container',default='nextgallery-faces34');parser.add_argument('--url',default='http://127.0.0.1:8784');parser.add_argument('--runtime',type=Path,required=True);args=parser.parse_args()
if not args.container.startswith('nextgallery-faces') or not args.url.startswith('http://127.0.0.1:'):raise SystemExit('Disposable test guard failed')
out=ROOT/'artifacts/faces-integration'
if not (out/'ISOLATED_SYNTHETIC_TEST').is_file():raise SystemExit('Synthetic fixture marker missing')
sys.path.insert(0,str(ROOT/'worker'))
import media_ai_faces as faces
from app_worker import NativeSource

def docker(*parts):return subprocess.check_output(['docker','exec','-u','www-data',args.container,*parts],text=True)
def fixture(phase,*extra):return docker('php','/test-scripts/people-fixture.php',phase,*map(str,extra))
fixture_data=json.loads((out/'people-fixture.json').read_text());uid=fixture_data['uid'];gallery=fixture_data['gallery']
code="define('OC_CONSOLE',true);require '/var/www/html/lib/base.php';echo \\OCP\\Server::get(\\OCA\\Nextgallery\\AI\\Service\\Settings::class)->workerToken();"
token=docker('php','-r',code).strip()
basic='Basic '+base64.b64encode(b'people-synthetic:PeopleSyntheticOnly2026!').decode()
checks=[]
def check(value,label):
 if not value:raise AssertionError(label)
 checks.append(label)
def request(path,body=None,authorization=basic,expect=200,headers=None):
 req=urllib.request.Request(args.url+path,data=json.dumps(body).encode() if body is not None else None,headers={'Authorization':authorization,'OCS-APIRequest':'true','Content-Type':'application/json','Accept':'application/json','X-Nextgallery-User':uid,**(headers or {})})
 try:
  with urllib.request.urlopen(req,timeout=120) as r:status,raw=r.status,r.read()
 except urllib.error.HTTPError as e:status,raw=e.code,e.read()
 if status!=expect:raise AssertionError(f'{path}: expected {expect}, got {status}: {raw[:700]!r}')
 return raw

def user(endpoint,body=None,expect=200):
 raw=request('/index.php/apps/nextgallery/api/v1/'+endpoint,body,expect=expect)
 return json.loads(raw)
class API:
 url=args.url+'/index.php/apps/nextgallery/ai/worker/'
 scope_url='isolated-people-integration'
 def call(self,endpoint,body=None):
  return json.loads(request('/index.php/apps/nextgallery/ai/worker/'+endpoint,body,authorization='Bearer '+token))
 def wire(self,endpoint,body=None,maximum=2*1024*1024):
  req=urllib.request.Request(self.url+endpoint,data=json.dumps(body).encode() if body is not None else None,headers={'Authorization':'Bearer '+token,'X-Nextgallery-User':uid,'Content-Type':'application/json'})
  with urllib.request.urlopen(req,timeout=120) as r:
   raw=r.read(maximum+1)
   if len(raw)>maximum:raise ValueError('Oversized fixture response')
   return raw,dict(r.headers)
api=API()
config=api.call('config');config['_pipeline']='faces';config['_model_directory']=str(args.runtime);config['_inference_directory']=str(out)
cache=out/'worker-cache';cache.mkdir(exist_ok=True)
# This directory contains only the test account's disposable derived cache.
for p in cache.glob('faces.sqlite3*'):p.unlink()
events=[]
result=faces.run(config,NativeSource(api,config),api,cache,lambda name,**data:events.append({'event':name,**data}),lambda:False)
check(result['error']==0,'public images processed without errors')
check(result['done']==6,'including zero-face image and second-gallery photo')
state=api.call('faces-state');check(len(state['items'])==5,'five detections in canonical state')
if args.seed_only:
 print(json.dumps({'seeded':True,'runtime_result':result}));sys.exit(0)
listing=user('subjects?gallery='+gallery)
check(len(listing['items'])==2,'two identities in main gallery')
check(sorted(s['photo_count'] for s in listing['items'])==[2,2],'counts scoped to requested gallery')
check('vector' not in json.dumps(listing),'user subject API exposes no vectors')
other=user('subjects?gallery='+fixture_data['other_gallery']+'&filter=single')
check(len(other['items'])==1 and other['items'][0]['photo_count']==1,'single detections and separate gallery scope')
first,second=listing['items'];first_id=first['id'];second_id=second['id']
media=user('subjects/'+first_id+'/media?gallery='+gallery)
check(len(media['items'])==2,'subject media contains two current authorized files')
preview='/index.php/apps/nextgallery/api/v1/subjects/preview/'+first['cover_id']+'?gallery='+gallery
raw=request(preview);check(raw[:2]==b'\xff\xd8','authorized on-demand face crop is JPEG')
request('/index.php/apps/nextgallery/api/v1/subjects/preview/'+other['items'][0]['cover_id']+'?gallery='+gallery,expect=404)
check(True,'preview rejects wrong gallery scope')
# OCS app-password/basic contract is independently versioned and wrapped.
ocs=json.loads(request('/ocs/v2.php/apps/nextgallery/api/v1/subjects?format=json&gallery='+gallery))
check(len(ocs['ocs']['data']['items'])==2,'Android OCS list envelope')
request('/ocs/v2.php/apps/nextgallery/api/v1/subjects?format=json',headers={'OCS-APIRequest':''},expect=403)
check(True,'OCS endpoints require explicit API header')
# Incremental and a removed working cache cannot trigger repeated inference.
for p in cache.glob('faces.sqlite3*'):p.unlink()
with __import__('unittest.mock',fromlist=['patch']).patch.object(faces.FaceDetector,'process_image',side_effect=AssertionError('Should use canonical processed etags')):
 again=faces.run(config,NativeSource(api,config),api,cache,lambda *a,**k:None,lambda:False)
check(again['error']==0 and again['done']==6,'cache deletion does not re-infer current or zero-face files')
# Immediate rules, stale operation rejection, split and merge.
revision=listing['rules_revision'];user('subjects/'+first_id+'/action',{'action':'hide','rules_revision':revision})
check(len(user('subjects?gallery='+gallery)['items'])==1,'hide applies immediately')
user('subjects/'+first_id+'/action',{'action':'show','rules_revision':revision},expect=409)
hidden=user('subjects?gallery='+gallery+'&filter=hidden');check(len(hidden['items'])==1,'hidden groups can be listed')
revision=hidden['rules_revision'];user('subjects/'+first_id+'/action',{'action':'show','rules_revision':revision})
listing=user('subjects?gallery='+gallery);revision=listing['rules_revision']
det=media['items'][0]['face_detection_ids'][0]
user('subjects/'+first_id+'/action',{'action':'split','detection_ids':[det],'rules_revision':revision})
singles=user('subjects?gallery='+gallery+'&filter=single');check(len(singles['items'])==2,'split immediately creates separately viewable people')
source,target=singles['items'][0]['id'],singles['items'][1]['id']
user('subjects/'+source+'/action',{'action':'merge','target_id':target,'rules_revision':singles['rules_revision']})
check(len(user('subjects?gallery='+gallery)['items'])==2,'merge immediately restores two complete groups')
# Fresh requests must discard faces as soon as the source image changes or disappears.
current=api.call('faces-state')
main_ids={x['id'] for name,x in fixture_data['files'].items() if name!='out-of-gallery.jpg'}
changed=next(d for d in current['items'] if d['file_id'] in main_ids)
fixture('change-file',changed['file_id'])
request('/index.php/apps/nextgallery/api/v1/subjects/preview/'+changed['id']+'?gallery='+gallery,expect=404)
changed_media=user('subjects/'+changed['group_id']+'/media?gallery='+gallery)
check(all(x['id']!=changed['file_id'] for x in changed_media['items']),'changed source is immediately excluded from groups and previews')
deleted=next(d for d in api.call('faces-state')['items'] if d['file_id'] in main_ids)
fixture('delete-file',deleted['file_id'])
request('/index.php/apps/nextgallery/api/v1/subjects/preview/'+deleted['id']+'?gallery='+gallery,expect=404)
check(all(d['file_id']!=deleted['file_id'] for d in api.call('faces-state')['items']),'deleted source disappears from worker state and previews')
# Stale generation cannot republish, even while original files still exist.
old=api.call('faces-state');d=old['items'][0]
body={'file_id':d['file_id'],'etag':d['etag'],'epoch':old['epoch'],'generation':old['generation'],'rules_revision':old['rules_revision'],'space':old['model_space'],'detections':[{'bbox':d['bbox'],'vector':d['vector'],'score':d['score'],'group_id':d['group_id']}]}
user('subjects/reset',{'confirmation':'wrong'},expect=400)
fixture('make-cache')
user('subjects/reset',{'confirmation':'faces'})
fixture('cache-cleared')
check(True,'direct reset erases all account face cache files')
request('/index.php/apps/nextgallery/ai/worker/publish-faces',body,authorization='Bearer '+token,expect=409)
check(not api.call('faces-state')['items'],'reset rejects old publication and removes vectors/groups')
# Populate current-generation face data before deleting the account.
fresh=api.call('faces-state');body.update(epoch=fresh['epoch'],generation=fresh['generation'],rules_revision=fresh['rules_revision'])
api.call('publish-faces',body)
check(len(api.call('faces-state')['items'])==1,'account deletion starts with stored face data')
# Both user and worker access are withdrawn on account deletion; recreated uid has fresh epoch.
fixture('make-cache')
fixture('delete-account')
fixture('cache-cleared')
check(True,'account deletion erases all account face cache files')
request('/index.php/apps/nextgallery/ai/worker/faces-state',authorization='Bearer '+token,expect=404)
fixture('recreate-account')
new=api.call('faces-state');check(new['epoch']!=old['epoch'] and not new['items'],'recreated account cannot inherit old identity data')
request('/index.php/apps/nextgallery/ai/worker/publish-faces',body,authorization='Bearer '+token,expect=404)
report={'checks':checks,'passed':len(checks),'runtime_result':result,'events':events,'public_data_only':True}
(out/'people-integration-result.json').write_text(json.dumps(report,indent=2))
print(json.dumps({'passed':len(checks),'checks':checks,'runtime_result':result},indent=2))
