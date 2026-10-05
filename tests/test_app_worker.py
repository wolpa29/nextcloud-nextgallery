import importlib.util
from pathlib import Path
import tempfile
import unittest
from datetime import datetime
from unittest.mock import patch
import sys
from zoneinfo import ZoneInfo
import media_pipeline as p

root=Path(__file__).resolve().parents[1]/'nextcloud-app/media_ai/worker'
spec=importlib.util.spec_from_file_location('app_worker',root/'app_worker.py')
w=importlib.util.module_from_spec(spec);spec.loader.exec_module(w)
from test_pipeline import Source,Model,Publisher

class WorkerTests(unittest.TestCase):
    def test_service_outage_pauses_daemon_and_survives_restart_until_explicit_resume(self):
        class API:
            url='https://example.test/native/'
        class Reporter:
            def __init__(self): self.value={}; self.events=[]
            def update(self, **fields): self.value.update(fields)
            def event(self, event, **fields): self.events.append((event,fields))
        config={'user_id':'paul','folders':['/'],'exclude_folders':[],
                'vision':{'url':'http://localhost/v1','model':'vision'},
                'ocr':{'url':'http://localhost/v1','model':'ocr'},
                'enabled':True,'schedule_time':'00:00','timezone':'Europe/Vienna'}
        with tempfile.TemporaryDirectory() as d:
            directory=Path(d); reporter=Reporter()
            control={'paused':False,'requested':False,'request_id':'old','reset_requested':False}
            w.atomic(directory/'control.json',control)
            unavailable={'state':'deferred','reason':'LM Studio offline','processed':0,'errors':0}
            with patch.object(p, 'run', return_value=unavailable):
                result=w.cycle(API(),config,directory,reporter,control)
            saved=w.read(directory/'control.json',{})
            self.assertTrue(saved['paused']); self.assertFalse(saved['requested'])
            self.assertEqual(result['state'],'paused'); self.assertEqual(reporter.value['message'],'LM Studio offline')
            self.assertEqual(w.waiting_status(saved)['message'],'LM Studio offline')
            self.assertFalse(w.is_due(config,saved,{}))  # No daily automatic restart while paused.
            restarted=w.Reporter(directory)
            try:
                restarted.update(**w.waiting_status(w.read(directory/'control.json',{})))
                self.assertEqual(restarted.value['state'],'paused')
                self.assertEqual(restarted.value['message'],'LM Studio offline')
            finally: restarted.close()
            resumed={**saved,'paused':False,'requested':True}; resumed.pop('pause_reason')
            self.assertEqual(w.waiting_status(resumed)['message'],'')
            self.assertTrue(w.is_due(config,resumed,{}))

    def test_unlimited_scan_processes_more_than_former_default_batch(self):
        with tempfile.TemporaryDirectory() as d:
            store=p.Store(Path(d)/'state.sqlite3');source=Source()
            source.items=[p.Item(str(n),f'Camera/{n:04d}.jpg',str(n),'v1',100) for n in range(105)]
            result=p.run({},store,source,{s:Model(s) for s in ['vision','ocr']},limit=0,max_seconds=0)
            self.assertEqual(result['processed'],105);self.assertEqual(result['stats'],{'done':105})
            store.db.close()

    def test_bounded_runs_resume_remaining_images_without_reanalyzing_done(self):
        with tempfile.TemporaryDirectory() as d:
            store=p.Store(Path(d)/'state.sqlite3');source=Source()
            source.items.extend([p.Item('2','b.jpg','b','v1',100),p.Item('3','c.jpg','c','v1',100)])
            models={s:Model(s) for s in ['vision','ocr']};events=[]
            first=p.run({},store,source,models,limit=1,observe=lambda event,**fields:events.append((event,fields)))
            self.assertEqual(first['stats'],{'done':1,'pending':2})
            events.clear();second=p.run({},store,source,models,limit=10,observe=lambda event,**fields:events.append((event,fields)))
            self.assertEqual(second['processed'],2);self.assertEqual(second['stats'],{'done':3})
            self.assertEqual(sum(e[0]=='stage_start' for e in events),4)
            store.db.close()

    def test_run_time_budget_finishes_current_image_and_resumes_next(self):
        with tempfile.TemporaryDirectory() as d:
            store=p.Store(Path(d)/'state.sqlite3');source=Source();source.items.append(p.Item('2','b.jpg','b','v1',100))
            clock=[0];events=[];models={s:Model(s) for s in ['vision','ocr']}
            def observe(event,**fields):
                events.append((event,fields))
                if event=='stage_finished':clock[0]=60
            with patch.object(p.time,'monotonic',side_effect=lambda:clock[0]):
                first=p.run({},store,source,models,max_seconds=30,observe=observe)
            self.assertEqual(first['processed'],1);self.assertEqual(first['stats'],{'done':1,'pending':1})
            self.assertEqual(sum(e[0]=='stage_finished' for e in events),2)
            second=p.run({},store,source,models)
            self.assertEqual(second['processed'],1);self.assertEqual(second['stats'],{'done':2});store.db.close()

    def test_pause_finishes_current_file_and_stops_next(self):
        with tempfile.TemporaryDirectory() as d:
            store=p.Store(Path(d)/'state.sqlite3'); source=Source(); source.items.append(p.Item('2','b.jpg','b','v1',100)); events=[]
            report=p.run({},store,source,{s:Model(s) for s in ['vision','ocr']},observe=lambda event,**fields:events.append((event,fields)),should_stop=lambda:any(e[0]=='file_finished' for e in events))
            self.assertEqual(report['processed'],1);self.assertEqual(report['stats'],{'done':1,'pending':1});self.assertEqual(sum(e[0]=='stage_finished' for e in events),2);store.db.close()
    def test_results_published_before_completion_event(self):
        with tempfile.TemporaryDirectory() as d:
            store=p.Store(Path(d)/'state.sqlite3');publisher=Publisher();events=[]
            def observe(event,**fields):
                if event=='file_finished':events.append(len(publisher.sent))
            report=p.run({},store,Source(),{s:Model(s) for s in ['vision','ocr']},publisher=publisher,observe=observe)
            self.assertEqual(events,[1]);self.assertEqual(report['published'],1);store.db.close()

    def test_daily_once_manual_override_and_pause(self):
        config={'enabled':True,'schedule_time':'03:00','timezone':'Europe/Vienna'}; now=datetime(2026,10,2,4,tzinfo=ZoneInfo('Europe/Vienna'))
        self.assertFalse(w.is_due(config,{'paused':True,'requested':True},{},now))
        self.assertTrue(w.is_due(config,{'paused':False},{},now))
        self.assertFalse(w.is_due(config,{'paused':False},{'date':'2026-10-02'},now))
        self.assertTrue(w.is_due(config,{'paused':False,'requested':True},{'date':'2026-10-02'},now))
        config['enabled']=False; self.assertFalse(w.is_due(config,{'paused':False},{},now))
    def test_request_consumption_preserves_newer_start(self):
        with tempfile.TemporaryDirectory() as d:
            directory=Path(d); value={'request_id':'new','requested':True,'paused':True};w.atomic(directory/'control.json',value)
            w.consume(directory,'old');self.assertEqual(w.read(directory/'control.json',{}),value)
            w.consume(directory,'new');self.assertFalse(w.read(directory/'control.json',{})['requested']);self.assertTrue(w.read(directory/'control.json',{})['paused'])
    def test_native_pagination_empty_filtered_page_continues(self):
        class API:
            url='https://example.test/native/'
            def call(self,endpoint):
                if endpoint=='inventory?cursor=0':return {'items':[],'cursor':1000}
                return {'items':[{'file_id':1001,'path':'Camera/a.jpg','etag':'v1','size':10,'mime':'image/jpeg'}],'cursor':None}
        source=w.NativeSource(API(),{'user_id':'paul','folders':['/'],'exclude_folders':[]});self.assertEqual(source.inventory()[0].locator,'1001')
    def test_native_repeated_cursor_refused(self):
        class API:
            url='https://example.test/native/'
            def call(self,endpoint):return {'items':[],'cursor':0}
        with self.assertRaises(p.PipelineError):w.NativeSource(API(),{'user_id':'paul','folders':['/'],'exclude_folders':[]}).inventory()
    def test_custom_prompts_affect_incremental_revision(self):
        first=p.Model({'prompt':'One','url':'http://localhost'},'vision');second=p.Model({'prompt':'Two','url':'http://localhost'},'vision');self.assertEqual(first.prompt,'One');self.assertNotEqual(first.revision,second.revision)
    def test_ai_reset_clears_only_own_store_and_preserves_original(self):
        class API:
            def call(self,endpoint,body):self.endpoint=endpoint
        class Reporter:
            def update(self,**fields):self.fields=fields
            def event(self,event):self.event_name=event
        with tempfile.TemporaryDirectory() as d:
            directory=Path(d); original=directory/'original.jpg';original.write_bytes(b'unchanged')
            store=p.Store(directory/'native.sqlite3');source=Source();p.run({},store,source,{s:Model(s) for s in ['vision','ocr']});store.db.close()
            control={'reset_requested':True,'reset_request_id':'one','paused':True};w.atomic(directory/'control.json',control)
            api=API();reporter=Reporter();w.reset_ai(api,directory,reporter,control)
            store=p.Store(directory/'native.sqlite3');self.assertEqual(store.stats(),{});self.assertEqual(store.db.execute('SELECT COUNT(*) FROM results').fetchone()[0],0);store.db.close()
            self.assertEqual(original.read_bytes(),b'unchanged');self.assertEqual(api.endpoint,'reset');self.assertFalse(w.read(directory/'control.json',{})['reset_requested'])

    def test_stream_progress_updates_current_without_flooding_logs(self):
        with tempfile.TemporaryDirectory() as directory:
            reporter=w.Reporter(Path(directory))
            try:
                reporter.event('inference_attempt_start',path='a.jpg',file_id='1',stage='ocr',attempt=2,total_attempts=4)
                reporter.event('inference_progress',path='a.jpg',file_id='1',stage='ocr',attempt=2,total_attempts=4,reasoning_chars=12000,content_chars=0)
                self.assertEqual(reporter.value['current']['attempt'],2)
                self.assertEqual(reporter.value['current']['reasoning_chars'],12000)
                logs=list(Path(directory).joinpath('logs').glob('*.jsonl'))
                self.assertEqual(len(logs),1);self.assertEqual(len(logs[0].read_text().splitlines()),1)
            finally:reporter.close()

    def test_bootstrap_refuses_http_and_url_credentials(self):
        for url in ['http://10.1.10.186','https://secret@10.1.10.186','https://10.1.10.186?token=x']:
            with self.assertRaises(p.PipelineError):w.API({'url':url,'token':'x'})

if __name__=='__main__':unittest.main()
