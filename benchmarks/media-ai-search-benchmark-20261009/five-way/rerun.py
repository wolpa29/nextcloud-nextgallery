"""Repeat frozen five-way retrieval with temporary stock Nextcloud and MariaDB."""
import hashlib,json,os,subprocess,sys,time,urllib.request
from pathlib import Path
from huggingface_hub import hf_hub_download
ROOT=Path(__file__).resolve().parent
DB='media-ai-five-way-rerun-db';NC='media-ai-five-way-rerun-nextcloud'
DB_IMAGE='mariadb@sha256:6422478cb8e159f080fb1d8ccf65101e26fe51385787fde7d16c3b165a331f15'
NC_IMAGE='nextcloud@sha256:aa9bb9bbde6e6afc756f7f101d65fbd57526165184737a85e31cc98dfbaaa2e2'
env=os.environ.copy();env.update(OPENBLAS_NUM_THREADS='4',OMP_NUM_THREADS='4')
def run(args,**kw):return subprocess.run(args,cwd=ROOT,check=True,**kw)
def port(name,internal):return subprocess.check_output(['docker','inspect',name,'--format','{{(index (index .NetworkSettings.Ports "'+str(internal)+'/tcp") 0).HostPort}}'],text=True).strip()
def logged(script,label):
 with (ROOT/(label+'.log')).open('w') as out:run([sys.executable,script],stdout=out,stderr=subprocess.STDOUT,env=env)
provenance=json.loads((ROOT/'provenance.json').read_text())
assert hashlib.sha256((ROOT/'fixtures.json').read_bytes()).hexdigest()==provenance['fixtures_sha256']
assert {f.name:hashlib.sha256(f.read_bytes()).hexdigest() for f in (ROOT/'images').iterdir()}==provenance['images']
textmodel=json.loads((ROOT.parent/'model-api.json').read_text());imagemodel=json.loads((ROOT/'model-api.json').read_text())
hf_hub_download('litert-community/embeddinggemma-2-text-270m-litert-lm','embeddinggemma-2-text-270m.litertlm',revision=textmodel['sha'],local_dir=ROOT.parent/'model',token=False)
hf_hub_download('litert-community/embeddinggemma-2-text-vision-440m-litert-lm','embeddinggemma-2-text-vision-440m.litertlm',revision=imagemodel['sha'],local_dir=ROOT/'model',token=False)
created=[]
try:
 run(['docker','run','-d','--name',DB,'--label','purpose=media-ai-five-way','-e','MARIADB_ALLOW_EMPTY_ROOT_PASSWORD=1','-e','MARIADB_DATABASE=benchmark','-p','127.0.0.1::3306',DB_IMAGE]);created.append(DB)
 run(['docker','run','-d','--name',NC,'--label','purpose=media-ai-five-way','-e','SQLITE_DATABASE=benchmark','-e','NEXTCLOUD_ADMIN_USER=benchmark','-e','NEXTCLOUD_ADMIN_PASSWORD=SyntheticBenchmarkOnly2026','-e','NEXTCLOUD_TRUSTED_DOMAINS=127.0.0.1','-p','127.0.0.1::80',NC_IMAGE]);created.append(NC)
 for _ in range(120):
  if subprocess.run(['docker','exec',DB,'mariadb','--protocol=tcp','-h127.0.0.1','-uroot','benchmark','-e','SELECT 1'],capture_output=True).returncode==0:break
  time.sleep(.5)
 else:raise RuntimeError('Database not ready')
 env['BENCH_NC_URL']='http://127.0.0.1:'+port(NC,80)
 for _ in range(120):
  try:
   with urllib.request.urlopen(env['BENCH_NC_URL']+'/status.php',timeout=3) as f:
    if json.load(f).get('installed'):break
  except Exception:pass
  time.sleep(.5)
 else:raise RuntimeError('Nextcloud not installed')
 logged('normal_nextcloud.py','normal-nextcloud')
 with (ROOT/'baseline-mariadb.json').open('w') as out:run(['docker','run','--rm','--network','host','--entrypoint','php','-e','BENCH_DB_PORT='+port(DB,3306),'-v',str(ROOT/'source-snapshot')+':/source:ro','-v',str(ROOT)+':/bench:ro',NC_IMAGE,'/bench/baseline.php'],stdout=out)
 logged('image_vectors.py','image-embedding');logged('compare.py','comparison')
 run([sys.executable,'create_report.py'],env=env)
finally:
 for name in created:subprocess.run(['docker','rm','-f','-v',name],check=True)
