#!/usr/bin/env python3
"""Build installable app archives from explicit runtime inputs; exclude secrets/dependencies/state."""
import argparse,tarfile,hashlib,json
from pathlib import Path
root=Path(__file__).resolve().parents[1]
p=argparse.ArgumentParser();p.add_argument('output',type=Path);args=p.parse_args();args.output.mkdir(parents=True,exist_ok=True)
version=json.loads((root/'package.json').read_text())['version']
def excluded(path):return any(part in {'node_modules','__pycache__','.venv','.git','state'} for part in path.parts) or path.suffix=='.pyc'
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
main=package('nextgallery',root,['appinfo','lib','templates','css','js','img','worker','LICENSE','NOTICE','docs/licenses'],version)
(args.output/'manifest.json').write_text(json.dumps([main],indent=2)+'\n')
