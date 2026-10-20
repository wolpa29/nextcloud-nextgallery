#!/usr/bin/env python3
"""Full worker/HTTP reuse checks on a guarded disposable Nextcloud, synthetic inference only."""
import argparse,json,subprocess,sys,tempfile,urllib.request,urllib.error
from pathlib import Path
from unittest.mock import Mock,patch
ROOT=Path(__file__).resolve().parents[1];sys.path.insert(0,str(ROOT/'worker'))
import media_ai_faces as faces
from app_worker import NativeSource
p=argparse.ArgumentParser();p.add_argument('--runtime',type=Path,required=True);args=p.parse_args()
CONTAINER='nextgallery-faces34';BASE='http://127.0.0.1:8784/index.php/apps/nextgallery/ai/worker/'
def docker(*args):return subprocess.check_output(['docker','exec','-u','www-data',CONTAINER,*args],text=True).strip()
def fixture(phase):return docker('php','/test-scripts/face-artifacts-http-fixture.php',phase)
if not (ROOT/'artifacts/faces-integration/ISOLATED_SYNTHETIC_TEST').is_file():raise SystemExit('Synthetic guard missing')
token=docker('php','-r',"define('OC_CONSOLE',true);require '/var/www/html/lib/base.php';echo \\OCP\\Server::get(\\OCA\\Nextgallery\\AI\\Service\\Settings::class)->workerToken();")
class API:
 url=BASE;scope_url='face-cache-http-fixture'
 def __init__(self,uid):self.uid=uid;self.lookups=[]
 def wire(self,endpoint,body=None,maximum=2*1024*1024):
  req=urllib.request.Request(BASE+endpoint,data=json.dumps(body).encode() if body is not None else None,headers={'Authorization':'Bearer '+token,'X-Nextgallery-User':self.uid,'Content-Type':'application/json'})
  with urllib.request.urlopen(req,timeout=120) as response:
   raw=response.read(maximum+1)
   if len(raw)>maximum:raise AssertionError('Oversized response')
   return raw,dict(response.headers)
 def call(self,endpoint,body=None):
  raw,_=self.wire(endpoint,body);data=json.loads(raw)
  if endpoint=='face-artifacts':self.lookups.append(data)
  return data
checks=[]
def check(value,label):
 if not value:raise AssertionError(label)
 checks.append(label)
def denied(api,body):
 try:api.call('face-artifacts',body)
 except urllib.error.HTTPError as e:return e.code
 return 200
fixture_data=json.loads(fixture('prepare'))
try:
 owner,reader,outsider=[API(uid) for uid in fixture_data['uids']]
 first=fixture_data['files'][0];body={'file_id':first['id'],'etag':first['etag']}
 check(denied(outsider,body)==404,'unshared file denied before cache use')
 with tempfile.TemporaryDirectory() as temp:
  def execute(api,dir_name,detector,block_download=False):
   config=api.call('config');config.update(_pipeline='faces',_model_directory=str(args.runtime),_inference_directory=temp)
   cache=Path(temp)/dir_name;cache.mkdir();source=NativeSource(api,config);items=source.inventory();allowed={item.locator for item in items};check(str(first['id']) in allowed,'same shared physical file is in account inventory')
   events=[]
   with patch.object(faces,'FaceDetector',return_value=detector):
    if block_download:
     with patch.object(source,'read',side_effect=AssertionError('cache hit must not download')):result=faces.run(config,source,api,cache,lambda e,**data:events.append(e),lambda:False)
    else:result=faces.run(config,source,api,cache,lambda e,**data:events.append(e),lambda:False)
   check(result['error']==0 and result['done']==3,'three files published without errors')
   return api.call('faces-state'),events
  detector=Mock();detector.process_image.side_effect=[[{'bbox':[.1,.1,.3,.3],'score':.95,'sharpness':150.0,'vector':[1.0]+[0.0]*127}],[],[{'bbox':[.1,.1,.02,.02],'score':.95,'sharpness':5.0,'vector':[1.0]+[0.0]*127}]]
  state_owner,events_owner=execute(owner,'owner',detector)
  check(detector.process_image.call_count==3,'first account infers each file once')
  hit=reader.call('face-artifacts',body);check(len(hit['detections'])==1 and set(hit['detections'][0])=={'bbox','score','vector','sharpness'},'cache contains only raw face inference')
  check(denied(outsider,body)==404,'populated cache does not grant access')
  never=Mock();never.process_image.side_effect=AssertionError('cache hit must not infer')
  state_reader,events_reader=execute(reader,'reader',never,True)
  check(never.process_image.call_count==0,'second account performs zero inference')
  check(events_reader.count('faces_cache_hit')==3,'good, zero-face and weak files all produce hits')
  check(sum(not d['eligible'] for d in state_owner['items'])==1,'weak face remains inspectable and excluded from anchors')
  a,b=state_owner['items'][0],state_reader['items'][0]
  check(a['id']!=b['id'] and a['group_id']!=b['group_id'],'account detection IDs and groups are independent')
  fixture('revoke');check(denied(reader,body)==404,'new HTTP request denies revoked share immediately')
 print(json.dumps({'checks':len(checks),'passed':checks,'private_media_accessed':False,'inference':'mocked synthetic detections; real worker, HTTP, Nextcloud sharing and database'},indent=2))
finally:fixture('cleanup')
