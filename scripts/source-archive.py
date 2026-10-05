#!/usr/bin/env python3
"""Package the server's exact corresponding source; never package Android/secrets."""
import argparse, tarfile
from pathlib import Path

root = Path(__file__).resolve().parents[1]
parser = argparse.ArgumentParser()
parser.add_argument('output', type=Path)
args = parser.parse_args()
args.output.parent.mkdir(parents=True, exist_ok=True)
version = __import__('json').loads((root/'package.json').read_text())['version']
prefix = 'nextgallery-server-' + version
paths = ['LICENSE','NOTICE','README.md','package.json','package-lock.json',
         'appinfo','lib','templates','css','js','img','frontend','scripts','tests','docs/licenses']
with tarfile.open(args.output, 'w:gz') as archive:
    for rel in paths:
        archive.add(root/rel, arcname=prefix+'/'+rel, recursive=True)
    for name in ['leaflet', 'leaflet.markercluster']:
        dep = root/'node_modules'/name
        assert (dep/'package.json').exists(), 'Run npm ci first'
        for rel in ['src','dist','LICENSE','MIT-LICENCE.txt','package.json','README.md']:
            if (dep/rel).exists():
                archive.add(dep/rel,arcname=prefix+'/third-party/'+name+'/'+rel)
print('Server-only corresponding source:', args.output, args.output.stat().st_size, 'bytes')
