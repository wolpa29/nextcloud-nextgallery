"""Explicit external-service startup; only the selected Docker volume is writable."""
import os
from pathlib import Path
import embedding_setup

os.umask(0o077)
profile=os.environ.get('NEXTGALLERY_EMBEDDING_PROFILE','lite')
if profile not in ('lite','full'):raise SystemExit('Invalid Gemma profile')
if not os.environ.get('NEXTGALLERY_EMBEDDING_KEY'):raise SystemExit('API key is required')
root=Path('/data')/profile
embedding_setup.install(root,profile)
model=root/(embedding_setup.MODEL_NAME if profile=='lite' else 'model')
python=root/'runtime/bin/python'
os.execv(str(python),[str(python),'/service/embedding_server.py','--profile',profile,'--family','gemma2',
    '--model','embeddinggemma-2-full' if profile=='full' else 'embeddinggemma-2','--model-path',str(model),
    '--threads',os.environ.get('NEXTGALLERY_EMBEDDING_THREADS','4'),'--host','0.0.0.0','--port','9379'])
