#!/usr/bin/env python3
"""Exercise the SDK from an extracted install archive without media or a server."""
import argparse
import json
from pathlib import Path
import subprocess
import tarfile
import tempfile
import sys

parser=argparse.ArgumentParser()
parser.add_argument('archive',type=Path)
args=parser.parse_args()
with tempfile.TemporaryDirectory(prefix='nextgallery-package-check-') as temporary:
    with tarfile.open(args.archive) as archive:
        archive.extractall(temporary,filter='data')
    runtime=Path(temporary)/'nextgallery/worker/lmstudio'
    worker=runtime.parent
    assert (worker/'requirements-embeddings.txt').is_file(), 'Automatic setup requirements missing'
    assert (worker/'requirements-embeddings-full.txt').is_file() and (worker/'embedding-full-model.json').is_file(), 'Full Gemma installer inputs missing'
    subprocess.run([sys.executable,str(worker/'embedding_setup.py'),'--help'],check=True,stdout=subprocess.DEVNULL)
    subprocess.run(['node','--input-type=module','-e','await import("@lmstudio/sdk")'],cwd=runtime,check=True)
    # A closed local port must produce the bridge's structured error event.
    # An absent SDK instead crashes before that event and falsely looks like a disconnect.
    request={'base_url':'ws://127.0.0.1:1','payload':{'model':'synthetic','messages':[]}}
    result=subprocess.run(['node',str(runtime/'stream.mjs')],input=json.dumps(request),
                          text=True,capture_output=True,timeout=30)
    assert result.returncode==1, 'Expected unavailable synthetic endpoint'
    events=[json.loads(line[6:]) for line in result.stdout.splitlines() if line.startswith('data: ')]
    assert len(events)==1 and events[0].get('error',{}).get('code')=='service_unavailable', 'SDK bridge failed before emitting its structured diagnostic'
    print('Extracted worker package: automatic setup entrypoint, SDK import and structured transport error passed')
