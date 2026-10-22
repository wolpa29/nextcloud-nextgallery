#!/usr/bin/env python3
"""New and revoked shares in disposable Nextcloud; no production or private data."""
import argparse,base64,json,os,subprocess,urllib.request,urllib.error
from pathlib import Path
p=argparse.ArgumentParser();p.add_argument('--case',choices=['fresh35','upgrade32','rename32'],default='fresh35');a=p.parse_args()
root=Path(__file__).resolve().parents[1];out=Path(os.environ.get('NEXTGALLERY_TEST_OUTPUT',str(root/'artifacts/integration'/a.case)))
assert (out/'ISOLATED_SYNTHETIC_TEST').is_file(),'Synthetic guard missing'
name=os.environ.get('NEXTGALLERY_TEST_CONTAINER','nextgallery-merge-'+a.case);port=int(os.environ.get('NEXTGALLERY_TEST_PORT',{'fresh35':8782,'upgrade32':8781,'rename32':8783}[a.case]))
def phase(value):subprocess.run(['docker','exec','-u','www-data',name,'php','/test-scripts/sql-share-contract.php',value],check=True)
def request(body):
 req=urllib.request.Request(f'http://127.0.0.1:{port}/ocs/v2.php/apps/nextgallery/api/v1/search?format=json',data=json.dumps(body).encode(),headers={'Authorization':'Basic '+base64.b64encode(b'sql-share-reader:SyntheticShareOnly2026!').decode(),'OCS-APIRequest':'true','Content-Type':'application/json','Accept':'application/json'})
 try:
  with urllib.request.urlopen(req,timeout=35) as r:return r.status,json.load(r)
 except urllib.error.HTTPError as e:return e.code,json.load(e)
try:
 phase('prepare');fixture=json.loads((out/'sql-share.json').read_text());body={'query':'SQSHARED','roots':['/'],'phase':'text','limit':20}
 code,data=request(body);assert code==200,(code,data)
 assert data['ocs']['data']['index_state']=='updating',data
 phase('refresh');code,data=request(body);assert code==200,(code,data)
 page=data['ocs']['data'];assert [it['id'] for it in page['items']]==[fixture['file_id']],data
 assert page['search_backend']=='sqlite' and page['items'][0]['path'].endswith('/SQSHARED-car.png')
 token=page['search_token'];phase('revoke')
 code,data=request(body|{'search_token':token});assert code==200 and not data['ocs']['data']['items'],data
 code,data=request(body);assert code==200 and not data['ocs']['data']['items'],data
 code,data=request(body|{'roots':['/SqlShare']});assert code==400,data
 print(json.dumps({'checks':5,'synthetic':True,'case':a.case,'new_share_background_refresh':True,'revoked_snapshot_denied':True,'revoked_search_denied':True,'revoked_scope_denied':True}))
finally:phase('cleanup')
