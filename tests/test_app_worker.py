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
from test_pipeline import Source,Model

class WorkerTests(unittest.TestCase):
    def test_pause_finishes_current_file_and_stops_next(self):
        with tempfile.TemporaryDirectory() as d:
            store=p.Store(Path(d)/'state.sqlite3'); source=Source(); source.items.append(p.Item('2','b.jpg','b','v1',100)); events=[]
            report=p.run({},store,source,{s:Model(s) for s in ['vision','ocr']},observe=lambda event,**fields:events.append((event,fields)),should_stop=lambda:any(e[0]=='file_finished' for e in events))
            self.assertEqual(report['processed'],1);self.assertEqual(report['stats'],{'done':1,'pending':1});self.assertEqual(sum(e[0]=='stage_finished' for e in events),2);store.db.close()
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
    def test_bootstrap_refuses_http_and_url_credentials(self):
        for url in ['http://10.1.10.186','https://secret@10.1.10.186','https://10.1.10.186?token=x']:
            with self.assertRaises(p.PipelineError):w.API({'url':url,'token':'x'})

if __name__=='__main__':unittest.main()
