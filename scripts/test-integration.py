#!/usr/bin/env python3
"""Disposable Nextcloud migration smoke test. Synthetic files only, no host Nextcloud access."""
import argparse, json, os, shutil, subprocess, time, urllib.request, urllib.error, base64
from pathlib import Path
root=Path(__file__).resolve().parents[1]
p=argparse.ArgumentParser();p.add_argument('--case',choices=['upgrade32','fresh35'],required=True);p.add_argument('--keep',action='store_true');a=p.parse_args()
case=a.case;name='nextgallery-merge-'+case;port=8781 if case=='upgrade32' else 8782
out=root/'artifacts/integration'/case;out.mkdir(parents=True,exist_ok=True);(out/'ISOLATED_SYNTHETIC_TEST').touch();os.chmod(out,0o777)
runtime=out/'runtime';runtime.mkdir(exist_ok=True)
def run(*args,check=True):return subprocess.run(args,check=check,text=True,stdout=subprocess.PIPE,stderr=subprocess.STDOUT).stdout
def docker(*args):return run('docker',*args)
def occ(*args):return docker('exec','-u','www-data',name,'php','occ',*args)
def copy_app(source,target):
 target.mkdir(exist_ok=True)
 for child in target.iterdir():
  if child.is_dir():shutil.rmtree(child)
  else:child.unlink()
 for rel in ['appinfo','lib','templates','css','js','img','worker']:
  if (source/rel).exists():shutil.copytree(source/rel,target/rel,ignore=shutil.ignore_patterns('node_modules','__pycache__','*.pyc'))
chronicle=runtime/'chronicle';legacy=runtime/'media_ai';samples=out/'samples';samples.mkdir(exist_ok=True)
# Small, unmistakably artificial colored PNG fixtures, generated without reading any media.
import struct,zlib
def png(color):
 def chunk(kind,data):return struct.pack('>I',len(data))+kind+data+struct.pack('>I',zlib.crc32(kind+data)&0xffffffff)
 width=height=80;rows=b''.join(b'\x00'+bytes(color)*width for _ in range(height))
 return b'\x89PNG\r\n\x1a\n'+chunk(b'IHDR',struct.pack('>IIBBBBB',width,height,8,2,0,0,0))+chunk(b'IDAT',zlib.compress(rows))+chunk(b'IEND',b'')
for dest,color in [('dog.png',(230,190,70)),('bike.png',(60,150,180)),('invoice.png',(180,190,170))]:(samples/dest).write_bytes(png(color))
containers=[];network=name+'-net'
try:
 if case=='upgrade32':
  # Extract the exact two predecessor commits from the unified repository history.
  for target,commit,prefix in [(chronicle,'bd8e034',''),(legacy,'5f0fe18','nextcloud-app/media_ai/')]:
   temp=out/('old-'+target.name);temp.mkdir(exist_ok=True)
   archive=subprocess.Popen(['git','archive',commit],cwd=root,stdout=subprocess.PIPE)
   subprocess.run(['tar','-x','-C',str(temp)],stdin=archive.stdout,check=True);archive.stdout.close();assert archive.wait()==0
   copy_app(temp/prefix,target)
  docker('network','create',network)
  dbname=name+'-db';docker('run','-d','--name',dbname,'--network',network,'-e','MARIADB_ROOT_PASSWORD=SyntheticRootOnly2026','-e','MARIADB_DATABASE=nextcloud','-e','MARIADB_USER=nextcloud','-e','MARIADB_PASSWORD=SyntheticDbOnly2026','mariadb:10.11');containers.append(dbname)
  redisname=name+'-redis';docker('run','-d','--name',redisname,'--network',network,'redis:8.2-alpine');containers.append(redisname)
  env=['-e','REDIS_HOST='+redisname,'-e','MYSQL_HOST='+dbname,'-e','MYSQL_DATABASE=nextcloud','-e','MYSQL_USER=nextcloud','-e','MYSQL_PASSWORD=SyntheticDbOnly2026'];image='nextcloud:32.0.5-apache'
 else:
  copy_app(root,chronicle);copy_app(root/'compat/media_ai',legacy);env=['-e','SQLITE_DATABASE=nextcloud'];image='nextcloud:35.0.1-apache';docker('network','create',network)
 docker('run','-d','--name',name,'--network',network,'-p',f'127.0.0.1:{port}:80','-v',f'{chronicle}:/var/www/html/custom_apps/chronicle:ro','-v',f'{legacy}:/var/www/html/custom_apps/media_ai:ro','-v',f'{root}/tests:/test-scripts:ro','-v',f'{out}:/test-output','-v',f'{samples}:/samples:ro','-e','NEXTCLOUD_ADMIN_USER=synthetic-admin','-e','NEXTCLOUD_ADMIN_PASSWORD=SyntheticAdminOnly2026','-e','NEXTCLOUD_TRUSTED_DOMAINS=127.0.0.1 localhost',*env,image);containers.insert(0,name)
 ready=False
 for _ in range(120):
  try:
   data=json.load(urllib.request.urlopen(f'http://127.0.0.1:{port}/status.php',timeout=2))
   if data.get('installed'):ready=True;break
  except Exception:pass
  time.sleep(1)
 assert ready,docker('logs',name)[-3000:]
 print(occ('app:enable','chronicle'),flush=True);print(occ('app:enable','media_ai'),flush=True)
 print(docker('exec','-u','www-data',name,'php','/test-scripts/integration.php','seed' if case=='upgrade32' else 'fresh'),flush=True)
 if case=='upgrade32':
  copy_app(root,chronicle);copy_app(root/'compat/media_ai',legacy)
  print(occ('upgrade'),flush=True)
 print(docker('exec','-u','www-data',name,'php','/test-scripts/integration.php','verify'),flush=True)
 # CLI app enable/upgrade uses a separate APCu cache from Apache; let core AppConfig expire.
 time.sleep(4)
 # Real HTTP routes, legacy OCS and worker-token authentication.
 pre=json.loads((out/'pre.json').read_text());base=f'http://127.0.0.1:{port}'
 credentials=base64.b64encode(b'merge-synthetic:SyntheticMergeOnly2026!').decode()
 def request(path,body=None,headers=None):
  req=urllib.request.Request(base+path,data=json.dumps(body).encode() if body is not None else None,headers={'Authorization':'Basic '+credentials,'OCS-APIRequest':'true','Content-Type':'application/json',**(headers or {})})
  try:
   with urllib.request.urlopen(req,timeout=15) as response:return response.status,response.read()
  except urllib.error.HTTPError as e:return e.code,e.read()
 http=[]
 for app,path in [('chronicle','/ai'),('media_ai','')]:
  url='/ocs/v2.php/apps/'+app+path+'/api/v1/metadata?format=json&file_id='+str(pre['ids'][2]);status,body=request(url);assert status==200,(url,status,body[-1000:]);metadata=json.loads(body)['ocs']['data'];assert metadata['available'];assert metadata['metadata']==json.loads(pre['metadata'][2]['metadata_json']);http.append(url)
  status,body=request('/ocs/v2.php/apps/'+app+path+'/api/v1/statuses?format=json',{'file_ids':pre['ids']});assert status==200,(status,body);statuses=json.loads(body)['ocs']['data']['statuses'];assert str(pre['ids'][1]) not in statuses;assert statuses[str(pre['ids'][0])]['status']=='changed';http.append('statuses:'+app)
 for route in ['/index.php/apps/media_ai/','/ocs/v2.php/search/providers/media_ai/search?term=RECHNUNG&format=json','/index.php/apps/chronicle/api/settings','/index.php/apps/chronicle/api/search?query=RECHNUNG','/index.php/apps/chronicle/ai','/index.php/apps/chronicle/ai/preferences','/index.php/apps/chronicle/ai/admin/config','/index.php/apps/chronicle/']:
  status,body=request(route);assert status==200,(route,status,body[-1000:]);http.append(route)
 for app,path in [('chronicle','/ai'),('media_ai','')]:
  route='/index.php/apps/'+app+path+'/worker/config';status,body=request(route,headers={'Authorization':'Bearer incorrect'});assert status==401,(route,status,body)
  # Decrypt only within the test server. The token is never printed or written to host output.
  code="require '/var/www/html/lib/base.php';echo \\OCP\\Server::get(\\OCP\\Security\\ICrypto::class)->decrypt(\\OCP\\Server::get(\\OCP\\IConfig::class)->getAppValue('chronicle','ai_worker_token'));"
  token=docker('exec','-u','www-data',name,'php','-r',code).strip();status,body=request(route,headers={'Authorization':'Bearer '+token});assert status==200,(route,status,body[-1000:]);assert json.loads(body)['user_id']=='merge-synthetic';http.append('worker:'+app)
 # The compatibility adapter forwards only while its owner app is enabled.
 print(occ('app:disable','chronicle'),flush=True)
 # Core AppConfig caches enabled flags in web-process APCu for three seconds; occ has its own cache.
 time.sleep(4)
 status,body=request('/ocs/v2.php/apps/media_ai/api/v1/metadata?format=json&file_id='+str(pre['ids'][2]));assert status==503,(status,body[-1200:])
 print(occ('app:enable','chronicle'),flush=True)
 print(occ('app:disable','media_ai'),flush=True)
 time.sleep(4)
 status,body=request('/ocs/v2.php/apps/chronicle/ai/api/v1/metadata?format=json&file_id='+str(pre['ids'][2]));assert status==200,(status,body[-1200:])
 print(occ('app:enable','media_ai'),flush=True)
 time.sleep(4)
 http.extend(['disabled-owner-refused','canonical-without-adapter'])
 result={'case':case,'http_checks':len(http),'routes':http,'nextcloud':data['versionstring'],'url':base,'cli':json.loads((out/'verified.json').read_text())};(out/'result.json').write_text(json.dumps(result,indent=2));print(json.dumps(result),flush=True)
 if a.keep:print('Disposable containers retained for UI verification:',name,flush=True)
finally:
 if not a.keep:
  for container in containers:run('docker','rm','-fv',container,check=False)
  run('docker','network','rm',network,check=False)
