"""Synthetic accounts reuse stage results by content; vectors also by embedding space."""
import json,tempfile,unittest
from pathlib import Path
from unittest.mock import patch
import sys
sys.path.insert(0,str(Path(__file__).resolve().parents[2]/'worker'))
import media_pipeline as p
import media_ai_embedding as e
import app_worker as w
from test_pipeline import Source,Model,Publisher,VISION
from test_embedding import FakeClient,API,config as embedding_config
from test_embedding import Source as EmbeddingSource


def rev(stage,revision):return p.digest([stage,revision])


class Shared:
    """Server stand-in: content-addressed rows, refused for files the account may not read.
    Descriptions/OCR prefer the requested revision, else the newest; vectors match exactly."""
    def __init__(self,rows=None,allowed=('a',)):
        self.rows={} if rows is None else rows;self.allowed=set(allowed);self.gets=[];self.puts=[];self.fail=None
    def check(self,locator):
        if self.fail:raise self.fail
        if locator not in self.allowed:raise p.PipelineError('HTTP 404 from synthetic.invalid')
    def get(self,locator,etag,sha256,stage,revision):
        self.check(locator);self.gets.append((locator,stage,revision))
        keys=[k for k in self.rows if k[:2]==(sha256,stage) and (stage!='embedding' or k[2]==revision)]
        keys.sort(key=lambda k:(k[2]!=revision,-self.rows[k]['order']))
        return {'result':self.rows[keys[0]]['result'],'revision':keys[0][2],'model':self.rows[keys[0]]['model']} if keys else {'result':None}
    def put(self,locator,etag,sha256,stage,revision,result,model=None):
        self.check(locator);self.puts.append((locator,stage,revision,model))
        stored=(sha256,stage,revision) not in self.rows
        self.rows.setdefault((sha256,stage,revision),{'result':result,'model':model,'order':len(self.rows)});return stored


class CurrentPublisher(Publisher):
    def capture(self,item,source):return {'file_id':12,'etag':item.etag}


class Account:
    def __init__(self,root,name,shared,revision='1'):
        self.store=p.Store(Path(root)/(name+'.sqlite'));self.source=Source();self.publisher=CurrentPublisher();self.shared=shared
        self.models={s:Model(s) for s in ('vision','ocr')};self.switch(revision)
    def switch(self,revision):
        """A model or prompt change: new revision and model name for future analyses."""
        for model in self.models.values():model.revision=rev(model.stage,revision);model.config['model']='fake-'+model.stage+'-'+revision
    def run(self,**kwargs):
        return p.run({},self.store,self.source,self.models,publisher=self.publisher,shared=self.shared,**kwargs)
    def calls(self):return sum(m.calls for m in self.models.values())
    def metadata(self):return json.loads(self.store.db.execute('SELECT metadata FROM files').fetchone()[0])


class SharedStageResults(unittest.TestCase):
    def setUp(self):
        self.tmp=tempfile.TemporaryDirectory();self.rows={};self.accounts=[]
    def tearDown(self):
        for account in self.accounts:account.store.db.close()
        self.tmp.cleanup()
    def account(self,name,revision='1',shared=None):
        account=Account(self.tmp.name,name,Shared(self.rows) if shared is None else shared,revision);self.accounts.append(account);return account

    def test_second_account_reuses_both_stages_without_inference(self):
        alice=self.account('alice');self.assertEqual(alice.run()['processed'],1);self.assertEqual(alice.calls(),2)
        self.assertEqual({key[1:] for key in self.rows},{('vision',rev('vision','1')),('ocr',rev('ocr','1'))})
        self.assertEqual(self.rows[next(k for k in self.rows if k[1]=='vision')]['model'],{'id':'fake-vision-1','weights':None})
        bob=self.account('bob');result=bob.run()
        self.assertEqual((result['processed'],result['cached_stage_results'],bob.calls()),(1,2,0))
        metadata=bob.metadata();self.assertEqual(metadata['vision'],VISION);self.assertEqual(metadata['ocr']['text'],'Rechnung 123')
        self.assertEqual(metadata['provenance']['output_channels'],{'vision':'shared','ocr':'shared'})
        self.assertEqual(metadata['provenance']['models']['vision']['id'],'fake-vision-1')
        self.assertEqual(len(bob.publisher.sent),1)
        # Local cache now holds them: a rerun after a lost publication needs no server round trip.
        self.assertEqual(bob.store.db.execute('SELECT COUNT(*) FROM results').fetchone()[0],2)

    def test_result_of_another_model_or_prompt_is_adopted_with_its_origin(self):
        self.account('alice').run();bob=self.account('bob',revision='2');bob.run()
        self.assertEqual(bob.calls(),0);self.assertEqual(len(self.rows),2,'Adopted result was offered again')
        models=bob.metadata()['provenance']['models']
        self.assertEqual(models['vision'],{'id':'fake-vision-1','weights':None,'revision':rev('vision','1'),'shared':True})
        self.assertEqual(models['ocr']['id'],'fake-ocr-1')
        # Cached under the origin's revision, not as if the new model had produced it.
        self.assertEqual({r[0] for r in bob.store.db.execute('SELECT revision FROM results')},{rev('vision','1'),rev('ocr','1')})

    def test_matching_revision_is_preferred_over_newer_ones(self):
        self.account('alice').run()
        sha=next(iter(self.rows))[0]
        self.rows[(sha,'vision',rev('vision','2'))]={'result':dict(VISION,description='Neueres Modell.'),'model':{'id':'fake-vision-2','weights':None},'order':99}
        bob=self.account('bob');bob.run();self.assertEqual(bob.metadata()['vision'],VISION)
        dave=self.account('dave',revision='3');dave.run();self.assertEqual(dave.metadata()['vision']['description'],'Neueres Modell.')

    def test_refused_or_unavailable_sharing_never_blocks_inference(self):
        self.account('alice').run()
        for error in (p.PipelineError('HTTP 404 from synthetic.invalid'),p.ServiceUnavailable('Connection unavailable'),OSError('reset')):
            with self.subTest(error=type(error).__name__):
                shared=Shared(self.rows);shared.fail=error;bob=self.account('bob-'+type(error).__name__,shared=shared)
                self.assertEqual(bob.run()['processed'],1);self.assertEqual(bob.calls(),2)
        outsider=self.account('outsider',shared=Shared(self.rows,allowed=()))
        self.assertEqual(outsider.run()['processed'],1);self.assertEqual(outsider.calls(),2)

    def test_invalid_shared_result_is_ignored_and_recomputed(self):
        self.account('alice').run()
        for key in list(self.rows):self.rows[key]['result']={'type':'foto'} if key[1]=='vision' else {'text':['not text']}
        bob=self.account('bob');self.assertEqual(bob.run()['processed'],1);self.assertEqual(bob.calls(),2)
        self.assertEqual(bob.metadata()['vision'],VISION)
        for key in list(self.rows):self.rows[key]['result']=dict(VISION,type='video') if key[1]=='vision' else {'text':'ok'}
        carol=self.account('carol');carol.run();self.assertEqual(carol.models['vision'].calls,1,'Video description adopted for an image')
        sha=next(iter(self.rows))[0];self.rows.clear();self.rows[(sha,'vision','not-a-revision')]={'result':VISION,'model':None,'order':0}
        dave=self.account('dave');dave.run();self.assertEqual(dave.models['vision'].calls,1,'Malformed revision adopted')

    def test_existing_results_are_offered_once_after_upgrade(self):
        alice=self.account('alice',shared=Shared(self.rows));shared=alice.shared;alice.shared=None
        alice.run();self.assertEqual(self.rows,{})
        alice.shared=shared;alice.run();self.assertEqual(len(self.rows),2);self.assertEqual(len(shared.puts),2)
        alice.run();self.assertEqual(len(shared.puts),2,'Backlog offered twice')
        self.assertEqual(alice.calls(),2)
        bob=self.account('bob');bob.run();self.assertEqual(bob.calls(),0)

    def test_backlog_never_offers_changed_files(self):
        alice=self.account('alice');alice.shared=None;alice.run();shared=Shared(self.rows)
        with alice.store.db:alice.store.db.execute("UPDATE files SET etag='v2',status='changed'")
        self.assertEqual(p.share_backlog(alice.store,shared,['vision','ocr']),0)
        with alice.store.db:alice.store.db.execute("UPDATE files SET status='done'")  # Inconsistent state stays guarded too.
        self.assertEqual(p.share_backlog(alice.store,shared,['vision','ocr']),0);self.assertEqual(self.rows,{})
        with alice.store.db:alice.store.db.execute("UPDATE files SET etag='v1'")
        self.assertEqual(p.share_backlog(alice.store,shared,['vision','ocr']),2)

    def test_backlog_offers_results_kept_after_a_model_change(self):
        alice=self.account('alice');alice.shared=None;alice.run()
        # Stale cache rows for the same bytes are not what the account uses: never offered.
        sha=alice.store.db.execute('SELECT sha256 FROM files').fetchone()[0]
        alice.store.cache(sha,'vision',rev('vision','0'),dict(VISION,description='Verworfen.'))
        alice.switch('2');alice.shared=Shared(self.rows,allowed=());alice.run();self.assertEqual(self.rows,{})
        alice.shared=Shared(self.rows);alice.run()
        self.assertEqual(alice.calls(),2,'Model change reanalysed finished files')
        self.assertEqual({k[1:] for k in self.rows},{('vision',rev('vision','1')),('ocr',rev('ocr','1'))},'Refused offer not retried or wrong result offered')
        self.assertEqual(self.rows[(sha,'vision',rev('vision','1'))]['model'],{'id':'fake-vision-1','weights':None})
        bob=self.account('bob',revision='2');bob.run();self.assertEqual(bob.calls(),0)

    def test_native_client_stops_asking_when_sharing_is_disabled(self):
        class DisabledAPI:
            def __init__(self):self.calls=0
            def call(self,endpoint,body):self.calls+=1;return {'result':None,'stored':False,'disabled':True}
        api=DisabledAPI();shared=w.NativeShared(api)
        for _ in range(3):self.assertIsNone(p.shared_call(shared,'get','1','v1','a'*64,'vision','b'*64))
        self.assertIsNone(p.shared_call(shared,'put','1','v1','a'*64,'vision','b'*64,VISION))
        self.assertEqual(api.calls,1)


class SharedEmbeddings(unittest.TestCase):
    def setUp(self):FakeClient.calls=0
    def run_account(self,root,api,setting=None):
        with patch.object(e,'Client',FakeClient):
            return e.run(setting or embedding_config(),EmbeddingSource(),api,Path(root),lambda *a,**k:None,lambda:False,shared=w.NativeShared(api))

    def test_second_account_publishes_shared_vectors_without_inference(self):
        rows={}
        with tempfile.TemporaryDirectory() as alice,tempfile.TemporaryDirectory() as bob:
            first=API(rows);self.assertEqual(self.run_account(alice,first)['processed'],1);self.assertEqual(FakeClient.calls,1);self.assertEqual(len(rows),1)
            second=API(rows);result=self.run_account(bob,second)
            self.assertEqual((result['processed'],FakeClient.calls),(1,1))
            self.assertEqual(second.published[0]['vectors'],first.published[0]['vectors'])
            self.assertEqual(second.published[0]['space'],first.published[0]['space'])

    def test_other_space_or_wrong_shape_is_computed_again(self):
        rows={}
        with tempfile.TemporaryDirectory() as alice,tempfile.TemporaryDirectory() as bob,tempfile.TemporaryDirectory() as carol:
            self.run_account(alice,API(rows))
            other=embedding_config();other['embedding']['max_edge']=1024
            self.run_account(bob,API(rows),other);self.assertEqual(FakeClient.calls,2)
            for key in rows:rows[key]=[{'frame':0,'seconds':0,'values':[1.]*64}]
            self.assertEqual(self.run_account(carol,API(rows))['processed'],1);self.assertEqual(FakeClient.calls,3)

    def test_shared_vector_shape_check(self):
        good=[{'frame':0,'seconds':0,'values':[2.]+[0.]*127}]
        self.assertEqual(e.shared_vectors(json.loads(json.dumps(good)),128,False,3)[0]['values'][0],1.)
        for bad in (None,[],good*2,[{'frame':1,'seconds':0,'values':[1.]*128}],[{'frame':0,'seconds':-1,'values':[1.]*128}],
                    [{'frame':0,'seconds':True,'values':[1.]*128}],[{'frame':0,'seconds':0,'values':[0.]*128}],[{'frame':0,'seconds':0,'values':['1']*128}]):
            self.assertIsNone(e.shared_vectors(json.loads(json.dumps(bad)),128,False,3),bad)
        video=[{'frame':i,'seconds':i,'values':[1.]*128} for i in range(3)]
        self.assertEqual(len(e.shared_vectors(video,128,True,3)),3);self.assertIsNone(e.shared_vectors(video,128,True,2))


if __name__=='__main__':
    unittest.main()
