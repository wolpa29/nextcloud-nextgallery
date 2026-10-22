#!/usr/bin/env python3
"""Concurrent partial publishing against disposable synthetic Nextcloud only."""
import concurrent.futures,json,os,subprocess,urllib.request,urllib.error
name=os.environ.get('NEXTGALLERY_TEST_CONTAINER','nextgallery-merge-fresh35')
port=int(os.environ.get('NEXTGALLERY_TEST_PORT','8782'))
def php(code):
 return subprocess.check_output(['docker','exec','-u','www-data',name,'php','-r','require "/var/www/html/lib/base.php";if(!is_file("/test-output/ISOLATED_SYNTHETIC_TEST"))exit(1);'+code],text=True).strip()
uid='three-pipeline-synthetic'
data=json.loads(php('$u="three-pipeline-synthetic";$h=\\OCP\\Server::get(\\OCP\\Files\\IRootFolder::class)->getUserFolder($u)->get("Synthetic");$f=$h->nodeExists("concurrent.png")?$h->get("concurrent.png"):$h->newFile("concurrent.png",file_get_contents("/samples/dog.png"));\\OCP\\Server::get(\\OCA\\Nextgallery\\AI\\Service\\Metadata::class)->reset($u);echo json_encode(["id"=>$f->getId(),"etag"=>$f->getEtag(),"token"=>\\OCP\\Server::get(\\OCA\\Nextgallery\\AI\\Service\\Settings::class)->workerToken()]);'))
vision={'type':'foto','description':'SYNTHETICCONCURRENTDESCRIPTION','tags':[],'objects':[],'uncertain_details':[]}
def publish(stage,user=uid):
 payload={'file_id':data['id'],'etag':data['etag'],'sha256':'a'*64,'revision':'b'*64,'stage':stage,'metadata':{'vision':vision} if stage=='descriptions' else {'ocr':{'text':'SYNTHETICCONCURRENTOCR'}},'exif':{}}
 request=urllib.request.Request(f'http://127.0.0.1:{port}/index.php/apps/nextgallery/ai/worker/publish',data=json.dumps(payload).encode(),headers={'Authorization':'Bearer '+data['token'],'X-Nextgallery-User':user,'Content-Type':'application/json','OCS-APIRequest':'true'})
 try:
  with urllib.request.urlopen(request,timeout=45) as response:return response.status
 except urllib.error.HTTPError as error:return error.code
with concurrent.futures.ThreadPoolExecutor(max_workers=8) as executor:
 statuses=list(executor.map(publish,['ocr','descriptions']*8))
assert statuses==[200]*16,statuses
row=json.loads(php('$m=\\OCP\\Server::get(\\OCA\\Nextgallery\\AI\\Service\\Metadata::class);$r=$m->get("three-pipeline-synthetic",'+str(data['id'])+');echo $r["metadata_json"];'))['analysis']
assert row['vision']==vision and row['ocr']['text']=='SYNTHETICCONCURRENTOCR'
denied=publish('ocr','account-bob');print('Other-account publication HTTP status:',denied);assert denied in (403,404)
print('18 concurrent HTTP partial-publication/account-isolation assertions passed')
