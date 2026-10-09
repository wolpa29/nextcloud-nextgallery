#!/usr/bin/env python3
import json,hashlib,subprocess
from pathlib import Path
expected=json.loads(Path('artifacts/fixture-hashes.json').read_text())
command="python3 - <<'PY'\nimport pathlib,hashlib,json\np=pathlib.Path('/var/www/nextcloud-data/paul/files/NextGallery-Demo')\nprint(json.dumps({str(f.relative_to(p)):hashlib.sha256(f.read_bytes()).hexdigest() for f in p.rglob('*') if f.is_file()}))\nPY"
r=subprocess.run(['ssh','-S','/tmp/media-ai-deploy-paul/control','root@10.1.10.186',command],check=True,text=True,capture_output=True)
actual=json.loads(r.stdout);assert actual==expected,'Demo originals differ';print(f'{len(actual)} / {len(expected)} synthetic originals byte-identical');Path('artifacts/original-preservation.json').write_text(json.dumps({'files':len(actual),'unchanged':True}))
