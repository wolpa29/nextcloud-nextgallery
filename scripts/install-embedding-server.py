#!/usr/bin/env python3
"""Configure a private Docker Compose service on Linux or Docker Desktop.

No Nextcloud credentials are needed. Generated keys are written only to private
configuration files, never included in commands or container logs.
"""
import argparse
import hashlib
import json
import os
from pathlib import Path
import secrets
import subprocess
import sys

def private_write(path,text):
    temporary=path.with_name(path.name+'.tmp')
    with temporary.open('w',encoding='utf-8') as file:file.write(text)
    temporary.chmod(0o600);temporary.replace(path)

def configure(directory,repository,profile,port,threads):
    directory.mkdir(mode=0o700,parents=True,exist_ok=True)
    if directory.is_symlink():raise ValueError('Use a real configuration directory')
    directory.chmod(0o700)
    key_file=directory/'api-key.txt'
    if key_file.exists():
        if key_file.is_symlink():raise ValueError('Linked key file is not supported')
        key=key_file.read_text().strip()
        if len(key)!=64 or any(c not in '0123456789abcdef' for c in key):raise ValueError('Invalid saved API key')
    else:key=secrets.token_hex(32);private_write(key_file,key+'\n')
    model='embeddinggemma-2-full' if profile=='full' else 'embeddinggemma-2'
    project='nextgallery-embedding-'+hashlib.sha256(str(directory).encode()).hexdigest()[:10]
    config={'name':project,'services':{'embedding':{'build':{'context':str(repository),'dockerfile':'docker/embedding-server/Dockerfile'},
        'ports':[f'{port}:9379'],'environment':{'NEXTGALLERY_EMBEDDING_PROFILE':profile,'NEXTGALLERY_EMBEDDING_KEY':'${NEXTGALLERY_EMBEDDING_KEY}',
        'NEXTGALLERY_EMBEDDING_THREADS':str(threads)},'volumes':['models:/data'],'restart':'unless-stopped',
        'read_only':True,'tmpfs':['/tmp'],'security_opt':['no-new-privileges:true'],'cap_drop':['ALL']}},'volumes':{'models':{}}}
    private_write(directory/'compose.json',json.dumps(config,indent=2)+'\n')
    private_write(directory/'.env','NEXTGALLERY_EMBEDDING_KEY='+key+'\n')
    private_write(directory/'connection.json',json.dumps({'url':f'http://PC-IP:{port}/v1','family':'gemma2','model':model,'api_key':key},indent=2)+'\n')
    return key,model

def main(argv=None):
    parser=argparse.ArgumentParser(description='Install and start the external NextGallery embedding service using Docker.')
    parser.add_argument('--directory',type=Path,default=Path.home()/'.nextgallery-embedding-server')
    parser.add_argument('--profile',choices=['lite','full'],default='lite')
    parser.add_argument('--port',type=int,default=9379);parser.add_argument('--threads',type=int,default=4)
    parser.add_argument('--action',choices=['install','start','stop','uninstall'],default='install')
    parser.add_argument('--delete-models',action='store_true',help='Also remove this service’s downloaded models when uninstalling.')
    parser.add_argument('--prepare-only',action='store_true',help='Write configuration only; do not run Docker or download models.')
    args=parser.parse_args(argv)
    if not 1<=args.port<=65535 or not 1<=args.threads<=64:parser.error('Invalid port or CPU thread count')
    repository=Path(__file__).resolve().parents[1];directory=args.directory.expanduser()
    if directory.is_symlink():parser.error('Use a real configuration directory')
    directory=directory.resolve()
    if args.action!='install' and not (directory/'compose.json').is_file():parser.error('Service has not been configured yet')
    if args.delete_models and args.action!='uninstall':parser.error('--delete-models only applies to uninstall')
    if args.action=='install':key,model=configure(directory,repository,args.profile,args.port,args.threads)
    if args.prepare_only:print('Configuration prepared. No model downloaded.');return 0
    command=['docker','compose','--env-file',str(directory/'.env'),'-f',str(directory/'compose.json')]
    try:
        subprocess.run(['docker','info'],check=True,stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL)
        action=['up','-d','--build'] if args.action=='install' else ['up','-d'] if args.action=='start' else ['stop'] if args.action=='stop' else ['down']+(['--volumes'] if args.delete_models else [])
        subprocess.run(command+action,check=True)
        if args.action in ('install','start'):
            print('Service started. The first model download can take several minutes.')
            print('Nextcloud connection settings: '+str(directory/'connection.json'))
            print('Check progress: docker compose --env-file '+str(directory/'.env')+' -f '+str(directory/'compose.json')+' logs -f')
        else:print('Service '+('stopped.' if args.action=='stop' else 'uninstalled.'))
        return 0
    except (FileNotFoundError,subprocess.CalledProcessError):
        print('Docker must be installed and running. See docs/EXTERNAL_EMBEDDING_SERVER.md.',file=sys.stderr);return 1

if __name__=='__main__':raise SystemExit(main())
