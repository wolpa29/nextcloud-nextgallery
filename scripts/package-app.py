#!/usr/bin/env python3
"""Build installable app archives from explicit runtime inputs; include worker SDK runtime; exclude secrets/build dependencies/state."""
import argparse,tarfile,hashlib,json,subprocess
from pathlib import Path
root=Path(__file__).resolve().parents[1]
p=argparse.ArgumentParser();p.add_argument('output',type=Path);args=p.parse_args();args.output.mkdir(parents=True,exist_ok=True)
version=json.loads((root/'package.json').read_text())['version']
sdk=root/'worker/lmstudio'
if not (sdk/'node_modules/@lmstudio/sdk/package.json').is_file():
 p.error('Missing worker runtime. Run npm ci --ignore-scripts --prefix worker/lmstudio before packaging.')
subprocess.run(['node','--input-type=module','-e','await import("@lmstudio/sdk")'],cwd=sdk,check=True)
def excluded(path):
 bundled_sdk=path.parts[:3]==('worker','lmstudio','node_modules')
 return any(part in {'__pycache__','.venv','.git','state'} for part in path.parts) or ('node_modules' in path.parts and not bundled_sdk) or path.suffix=='.pyc'
def package(name,source,parts,version):
 target=args.output/f'{name}-{version}.tar.gz'
 with tarfile.open(target,'w:gz') as archive:
  for part in parts:
   path=source/part
   if path.is_file():archive.add(path,arcname=name+'/'+part,recursive=False)
   elif path.is_dir():
    for file in sorted(path.rglob('*')):
     if file.is_file() and not excluded(file.relative_to(source)):archive.add(file,arcname=name+'/'+str(file.relative_to(source)),recursive=False)
 manifest={'file':target.name,'bytes':target.stat().st_size,'sha256':hashlib.sha256(target.read_bytes()).hexdigest()};print(json.dumps(manifest));return manifest
main=package('nextgallery',root,['appinfo','lib','templates','css','js','img','worker','LICENSE','NOTICE','docs/licenses','docs/EMBEDDINGS.md','docs/EXTERNAL_EMBEDDING_SERVER.md','docs/EXTERNAL_EMBEDDING_SERVER.en.md','docs/examples'],version)
(args.output/'manifest.json').write_text(json.dumps([main],indent=2)+'\n')
