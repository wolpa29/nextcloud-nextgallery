"""Synthetic stage-cache migration, actual worker processes and independent inference."""
import hashlib,json,multiprocessing,tempfile,time,unittest
from pathlib import Path
from unittest.mock import patch
import sys
sys.path.insert(0,str(Path(__file__).resolve().parents[2]/'worker'))
import app_worker as w
import analysis_storage as storage
import inference_gate
import media_pipeline as p
from test_independent_pipelines import synthetic_worker
from test_pipeline import Source,Model,Publisher,VISION


def gate_worker(directory,url,entered,release):
    with inference_gate.hold(directory,url):
        entered.set();release.wait(10)


class ThreePipelines(unittest.TestCase):
    def test_migrate_legacy_results_once_without_changing_original_cache(self):
        with tempfile.TemporaryDirectory() as tmp:
            root=Path(tmp);old=p.Store(root/'native.sqlite3')
            image=p.Item('image','Synthetic/image.png','1','v1',1,'image/png');video=p.Item('video','Synthetic/video.mp4','2','v1',1,'video/mp4')
            old.discover([image,video],'synthetic','legacy')
            old.finish(image,'a'*64,{'vision':VISION,'ocr':{'text':''},'provenance':{'models':{'vision':{},'ocr':{}}}},{});old.finish(video,'b'*64,{'vision':{**VISION,'type':'video'},'ocr':{'text':'','source':'skipped_video'}},{})
            old.cache('a'*64,'vision','v',VISION);old.cache('a'*64,'ocr','o',{'text':''});old.db.close()
            original=hashlib.sha256((root/'native.sqlite3').read_bytes()).hexdigest()
            storage.initialize(root)
            self.assertEqual(original,hashlib.sha256((root/'native.sqlite3').read_bytes()).hexdigest())
            description=p.Store(storage.path(root,'descriptions'));ocr=p.Store(storage.path(root,'ocr'))
            self.assertEqual(description.stats(),{'done':2});self.assertEqual(ocr.stats(),{'done':1})
            self.assertEqual(description.db.execute('SELECT DISTINCT stage FROM results').fetchall()[0][0],'vision');self.assertEqual(ocr.db.execute('SELECT DISTINCT stage FROM results').fetchall()[0][0],'ocr')
            self.assertNotIn('ocr',json.loads(description.db.execute("SELECT metadata FROM files WHERE id='image'").fetchone()[0]));self.assertNotIn('vision',json.loads(ocr.db.execute("SELECT metadata FROM files WHERE id='image'").fetchone()[0]))
            description.db.close();ocr.db.close();before=storage.path(root,'ocr').read_bytes();storage.initialize(root);self.assertEqual(before,storage.path(root,'ocr').read_bytes())

    def test_v1_control_migrates_ocr_without_rewriting_active_embeddings(self):
        with tempfile.TemporaryDirectory() as tmp:
            root=Path(tmp);w.atomic(root/'pipelines.json',{'version':1});w.atomic(root/'control-descriptions.json',{'paused':False,'requested':True,'request_id':'description'});w.atomic(root/'control-embeddings.json',{'paused':False,'requested':False,'request_id':'embedding'});old=(root/'control-embeddings.json').read_bytes()
            w.initialize_pipelines(root)
            self.assertTrue(w.read(root/'control-ocr.json',{})['requested']);self.assertEqual(w.read(root/'control-ocr.json',{})['run_kind'],'ocr');self.assertEqual(old,(root/'control-embeddings.json').read_bytes())
            w.atomic(root/'control-ocr.json',{'paused':True});w.initialize_pipelines(root);self.assertTrue(w.read(root/'control-ocr.json',{})['paused'])

    def test_three_processes_run_and_ocr_pause_leaves_both_other_lanes_running(self):
        ctx=multiprocessing.get_context('fork')
        with tempfile.TemporaryDirectory() as tmp:
            root=Path(tmp);w.atomic(root/'control.json',{'paused':False,'requested':True,'run_kind':'both'});w.atomic(root/'bootstrap.json',{})
            started={k:ctx.Event() for k in w.PIPELINES};release=ctx.Event();progress={k:ctx.Value('i',0) for k in w.PIPELINES}
            workers={k:ctx.Process(target=synthetic_worker,args=(tmp,k,started[k],release,progress[k])) for k in w.PIPELINES}
            try:
                for process in workers.values():process.start()
                for kind in w.PIPELINES:self.assertTrue(started[kind].wait(10),kind+' did not start')
                before={kind:progress[kind].value for kind in ('descriptions','embeddings')}
                w.atomic(root/'processing-admin-ocr.json',{'paused':True});workers['ocr'].join(5);self.assertEqual(workers['ocr'].exitcode,0)
                time.sleep(.15)
                for kind in ('descriptions','embeddings'):self.assertGreater(progress[kind].value,before[kind]);self.assertTrue(workers[kind].is_alive())
            finally:
                release.set()
                for process in workers.values():process.join(5)
                for process in workers.values():
                    if process.is_alive():process.terminate();process.join()

    def test_single_stage_run_publishes_without_waiting_for_the_other_model(self):
        for stage in ('vision','ocr'):
            with self.subTest(stage=stage),tempfile.TemporaryDirectory() as tmp:
                store=p.Store(Path(tmp)/'native.sqlite3');source=Source();model=Model(stage);publisher=Publisher()
                result=p.run({'image_timeout_seconds':10},store,source,{stage:model},publisher=publisher)
                self.assertEqual(result['processed'],1);metadata=json.loads(store.db.execute('SELECT metadata FROM files').fetchone()[0]);self.assertIn(stage,metadata);self.assertNotIn('ocr' if stage=='vision' else 'vision',metadata)
                self.assertEqual(len(publisher.sent),1);store.db.close()

    def test_host_gate_serializes_shared_gpu_and_allows_other_hosts(self):
        ctx=multiprocessing.get_context('fork')
        with tempfile.TemporaryDirectory() as tmp:
            events=[ctx.Event() for _ in range(3)];release=ctx.Event();urls=['http://same.example:1234/v1','http://same.example:9379','http://other.example/v1']
            workers=[ctx.Process(target=gate_worker,args=(tmp,url,events[i],release)) for i,url in enumerate(urls)]
            try:
                workers[0].start();self.assertTrue(events[0].wait(5));workers[1].start();workers[2].start();self.assertTrue(events[2].wait(5));self.assertFalse(events[1].wait(.2));release.set();self.assertTrue(events[1].wait(5))
            finally:
                release.set()
                for process in workers:
                    if process.pid:process.join(5)
                    if process.is_alive():process.terminate();process.join()

    def test_paused_pipeline_leaves_a_busy_host_without_waiting_for_other_run(self):
        import threading
        with tempfile.TemporaryDirectory() as tmp:
            entered=threading.Event();release=threading.Event()
            def owner():
                with inference_gate.hold(tmp,'http://same.example/v1'):
                    entered.set();release.wait(5)
            thread=threading.Thread(target=owner);thread.start();self.assertTrue(entered.wait(2))
            stop=threading.Event();timer=threading.Timer(.15,stop.set);timer.start();started=time.monotonic()
            try:
                with self.assertRaises(inference_gate.InferenceCancelled):
                    with inference_gate.hold(tmp,'http://same.example/other',stop.is_set):self.fail('Acquired busy host')
                self.assertLess(time.monotonic()-started,1)
                self.assertFalse(release.is_set())
            finally:release.set();thread.join(2);timer.cancel()

    def test_embedding_host_wait_is_outside_image_budget_and_pause_keeps_pending_file(self):
        import threading
        import media_ai_embedding as embedding
        from test_embedding import FakeClient,Source as EmbeddingSource,API as EmbeddingAPI,config as embedding_config
        for paused in (False,True):
            with self.subTest(paused=paused),tempfile.TemporaryDirectory() as tmp:
                entered=threading.Event();release=threading.Event();stop=threading.Event()
                def owner():
                    with inference_gate.hold(tmp,'http://127.0.0.1:9379/v1'):
                        entered.set();release.wait(3)
                thread=threading.Thread(target=owner);thread.start();self.assertTrue(entered.wait(2))
                timer=threading.Timer(.3,stop.set if paused else release.set);timer.start()
                settings=embedding_config();settings['image_timeout_seconds']=.1;settings['embedding']['_inference_directory']=tmp
                try:
                    with patch.object(embedding,'Client',FakeClient):
                        result=embedding.run(settings,EmbeddingSource(),EmbeddingAPI(),Path(tmp),lambda *a,**k:None,stop.is_set)
                    self.assertEqual(result['errors'],0)
                    self.assertEqual(result['processed'],0 if paused else 1)
                    if paused:self.assertEqual(result['stats']['pending'],1)
                finally:release.set();thread.join(2);timer.cancel()

    def test_ocr_deletion_removes_only_ocr_from_legacy_cache(self):
        with tempfile.TemporaryDirectory() as tmp:
            root=Path(tmp);store=p.Store(root/'native.sqlite3');item=p.Item('1','Synthetic/image.png','1','v1',1)
            store.discover([item],'synthetic','legacy');store.finish(item,'a'*64,{'vision':VISION,'ocr':{'text':'SYNTHETIC'}},{})
            store.cache('a'*64,'vision','v',VISION);store.cache('a'*64,'ocr','o',{'text':'SYNTHETIC'});store.db.close();storage.initialize(root)
            storage.reset_legacy(root,'ocr');store=p.Store(root/'native.sqlite3');metadata=json.loads(store.db.execute('SELECT metadata FROM files').fetchone()[0]);self.assertEqual(metadata['vision'],VISION);self.assertNotIn('ocr',metadata);self.assertEqual(store.db.execute('SELECT DISTINCT stage FROM results').fetchone()[0],'vision');store.db.close()

if __name__=='__main__':unittest.main()
