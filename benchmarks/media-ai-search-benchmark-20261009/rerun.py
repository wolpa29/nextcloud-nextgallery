"""Repeat using frozen data, original PHP snapshot, pinned model and disposable DB."""
import json, os, subprocess, sys
from pathlib import Path
from huggingface_hub import hf_hub_download

root=Path(__file__).resolve().parent
name='media-ai-search-benchmark-rerun'
database_image='mariadb@sha256:6422478cb8e159f080fb1d8ccf65101e26fe51385787fde7d16c3b165a331f15'
php_image='nextcloud@sha256:aa9bb9bbde6e6afc756f7f101d65fbd57526165184737a85e31cc98dfbaaa2e2'
def run(args,**kw):return subprocess.run(args,check=True,cwd=root,**kw)
model_api=json.loads((root/'model-api.json').read_text())
hf_hub_download('litert-community/embeddinggemma-2-text-270m-litert-lm','embeddinggemma-2-text-270m.litertlm',
                revision=model_api['sha'],local_dir=root/'model',token=False)
run(['docker','run','-d','--name',name,'--label','purpose=media-ai-search-benchmark',
     '-e','MARIADB_ALLOW_EMPTY_ROOT_PASSWORD=1','-e','MARIADB_DATABASE=benchmark',
     '-p','127.0.0.1::3306',database_image])
try:
    import time
    for _ in range(60):
        ready=subprocess.run(['docker','exec',name,'mariadb','--protocol=tcp','-h127.0.0.1',
                              '-uroot','benchmark','-e','SELECT 1'],capture_output=True)
        if ready.returncode==0:break
        time.sleep(.5)
    else:raise RuntimeError('Benchmark database did not become ready')
    port=subprocess.check_output(['docker','inspect',name,'--format',
        '{{(index (index .NetworkSettings.Ports "3306/tcp") 0).HostPort}}'],text=True).strip()
    with (root/'baseline-mariadb.json').open('w') as out:
        run(['docker','run','--rm','--network','host','--entrypoint','php','-e','BENCH_DB_PORT='+port,
             '-v',str(root/'source-snapshot')+':/source:ro','-v',str(root)+':/bench:ro',
             php_image,'/bench/baseline.php'],stdout=out)
    env=os.environ.copy();env.update(OPENBLAS_NUM_THREADS='4',OMP_NUM_THREADS='4')
    with (root/'benchmark-summary.json').open('w') as out,(root/'benchmark-runtime.log').open('w') as err:
        run([sys.executable,'benchmark.py'],stdout=out,stderr=err,env=env)
    run([sys.executable,'create_report.py'])
finally:
    run(['docker','rm','-f','-v',name])
