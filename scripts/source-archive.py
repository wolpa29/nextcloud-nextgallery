#!/usr/bin/env python3
"""Package tracked server sources and build dependencies; never include ignored data or Git history."""
import argparse,tarfile,subprocess,json
from pathlib import Path
root=Path(__file__).resolve().parents[1]
p=argparse.ArgumentParser();p.add_argument('output',type=Path);args=p.parse_args();args.output.parent.mkdir(parents=True,exist_ok=True)
version=json.loads((root/'package.json').read_text())['version'];prefix='nextgallery-server-'+version
tracked=subprocess.check_output(['git','ls-files','-z'],cwd=root).decode().split('\0')
def safe(info):
 parts=Path(info.name).parts
 if any(p in {'.git','.venv','__pycache__','.cache','.bin','state','artifacts'} for p in parts):return None
 if info.issym() or info.islnk():return None
 if parts[-1] in {'config.json','pipeline.env','.env'} or parts[-1].startswith('.env.'):return None
 if info.name.endswith(('.pyc','.sqlite','.sqlite3','.log','.jsonl','.pem','.key','.crt')):return None
 return info
with tarfile.open(args.output,'w:gz') as archive:
 for rel in sorted(set(tracked)):
  path=root/rel
  if rel and path.is_file():archive.add(path,arcname=prefix+'/'+rel,recursive=False,filter=safe)
 assert (root/'node_modules/leaflet/package.json').exists(),'Run npm ci first'
 archive.add(root/'node_modules',arcname=prefix+'/third-party/npm',filter=safe)
 sdk=root/'worker/lmstudio/node_modules'
 if sdk.exists():archive.add(sdk,arcname=prefix+'/third-party/lmstudio',filter=safe)
print('Server corresponding source:',args.output,args.output.stat().st_size,'bytes')
