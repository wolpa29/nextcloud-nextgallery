"""Account scheduling, independent processing and preservation of unrelated caches."""
import hashlib
import importlib.util
import json
from pathlib import Path
import tempfile
import unittest
from unittest.mock import patch
import media_pipeline as p
import media_ai_embedding as embedding
spec=importlib.util.spec_from_file_location('account_worker',Path(__file__).resolve().parents[2]/'worker/app_worker.py')
w=importlib.util.module_from_spec(spec);spec.loader.exec_module(w)

class Reporter:
    def __init__(self): self.value={'analysis_stats':{'done':7},'embedding_stats':{'done':9}};self.events=[]
    def update(self,**value):self.value.update(value)
    def event(self,event,**value):self.events.append((event,value))

class API:
    url='https://synthetic.example/worker/'
    def __init__(self):self.calls=[]
    def call(self,route,body=None):self.calls.append((route,body));return {}

class Accounts(unittest.TestCase):
    def test_account_markers_are_verified_and_links_are_not_followed(self):
        with tempfile.TemporaryDirectory() as tmp:
            root=Path(tmp);parent=root/'accounts';parent.mkdir()
            good=parent/hashlib.sha256(b'alice').hexdigest();good.mkdir();(good/'account.json').write_text(json.dumps({'user_id':'alice'}))
            forged=parent/'bob';forged.mkdir();(forged/'account.json').write_text(json.dumps({'user_id':'bob'}))
            (parent/hashlib.sha256(b'linked').hexdigest()).symlink_to(good,target_is_directory=True)
            self.assertEqual(w.account_directories(root,'legacy'),[('legacy',root),('alice',good)])
    def test_worker_requests_carry_explicit_account_identity(self):
        api=w.API({'url':'https://synthetic.example/','token':'test'},'alice')
        self.assertEqual(api.headers['X-Nextgallery-User'],'alice')
        self.assertNotIn('X-Nextgallery-User',w.API({'url':'https://synthetic.example/','token':'test'}).headers)
    def test_selective_resets_preserve_other_database_and_other_account(self):
        for kind,removed,kept in [('descriptions','native.sqlite3','embeddings.sqlite3'),('embeddings','embeddings.sqlite3','native.sqlite3')]:
            with self.subTest(kind=kind),tempfile.TemporaryDirectory() as tmp:
                root=Path(tmp);a=root/'alice';a.mkdir();b=root/'bob';b.mkdir();(b/'native.sqlite3').write_bytes(b'other account untouched')
                native=p.Store(a/'native.sqlite3');native.db.close()
                dense=embedding.Store(a/'embeddings.sqlite3');dense.db.close()
                sentinel=(a/kept).read_bytes();control={'reset_kind':kind,'reset_request_id':'synthetic','reset_requested':True,'paused':True};w.atomic(a/'control.json',control)
                api=API();reporter=Reporter();w.reset_ai(api,a,reporter,control)
                self.assertEqual((a/kept).read_bytes(),sentinel)
                self.assertEqual((b/'native.sqlite3').read_bytes(),b'other account untouched')
                self.assertFalse(w.read(a/'control.json',{})['reset_requested'])
                self.assertEqual(reporter.value['stats']['done'],9 if kind=='descriptions' else 7)
                self.assertEqual(api.calls,[('reset',{})])
    def test_separate_runs_do_not_call_the_other_model(self):
        class Source:
            scope='synthetic'
            def __init__(self,*args):pass
        for kind in ['descriptions','embeddings']:
            with self.subTest(kind=kind),tempfile.TemporaryDirectory() as tmp:
                root=Path(tmp);w.atomic(root/'control.json',{'paused':False})
                config={'user_id':'alice','analysis_enabled':True,'embedding':{'enabled':True},'vision':{},'ocr':{}}
                analysis={'state':'finished','processed':1,'errors':0,'published':1,'cached_stage_results':0}
                dense={'state':'finished','processed':1,'errors':0,'stats':{'done':1,'pending':0,'error':0}}
                with patch.object(w,'NativeSource',Source),patch.object(w.p,'Model'),patch.object(w.p,'run',return_value=analysis) as vision,patch.object(w,'ensure_local_embedding'),patch.object(w.embedding,'run',return_value=dense) as vectors:
                    result=w.cycle(API(),config,root,Reporter(),{'run_kind':kind})
                    self.assertEqual(vision.call_count,1 if kind=='descriptions' else 0)
                    self.assertEqual(vectors.call_count,1 if kind=='embeddings' else 0)
                    self.assertEqual(result['kind'],kind)
                    self.assertTrue(config['analysis_enabled']);self.assertTrue(config['embedding']['enabled'])

if __name__=='__main__':unittest.main()
