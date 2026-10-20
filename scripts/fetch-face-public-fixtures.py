#!/usr/bin/env python3
"""Fetch only the four pinned, rights-documented public People smoke fixtures."""
import argparse,hashlib,json,urllib.request
from pathlib import Path
ROOT=Path(__file__).resolve().parents[1]
p=argparse.ArgumentParser();p.add_argument('--output',type=Path,default=ROOT/'artifacts/faces-public-smoke');a=p.parse_args();a.output.mkdir(parents=True,exist_ok=True)
sources=json.loads((ROOT/'benchmarks/faces/public-sources.json').read_text())['sources']
for item in sources:
 target=a.output/item['file'];expected=item['sha256']
 if target.name!=item['file'] or target.parent!=a.output:raise ValueError('Invalid fixture filename')
 if target.is_file() and hashlib.sha256(target.read_bytes()).hexdigest()==expected:
  print(target.name+': already verified');continue
 if not item['url'].startswith('https://'):raise ValueError('Fixture requires HTTPS')
 request=urllib.request.Request(item['url'],headers={'User-Agent':'NextGallery-public-fixture-test/1'})
 with urllib.request.urlopen(request,timeout=60) as response:
  data=response.read(16*1024*1024+1)
 if len(data)>16*1024*1024 or hashlib.sha256(data).hexdigest()!=expected:raise ValueError('Fixture hash/size differs: '+target.name)
 temporary=target.with_name(target.name+'.download');temporary.write_bytes(data);temporary.replace(target)
 print(target.name+': downloaded and verified')
