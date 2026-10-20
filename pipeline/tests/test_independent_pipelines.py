"""Synthetic independent worker processes, scoped outages and legacy-state preservation."""
import json
import multiprocessing
from pathlib import Path
import sys
import tempfile
import time
import unittest
from unittest.mock import patch
sys.path.insert(0,str(Path(__file__).resolve().parents[2]/'worker'))
import app_worker as w


def synthetic_worker(directory,kind,started,release,progress):
    root=Path(directory)
    config={'user_id':'synthetic','folders':['/Synthetic'],'exclude_folders':[],
            'analysis_enabled':True,'embedding':{'enabled':True},'vision':{},'ocr':{},
            'faces':{'enabled':True},'enabled':False,'schedule_time':'00:00','timezone':'Europe/Vienna','log_days':1}
    class API:
        url='https://synthetic.invalid/worker/'
        def __init__(self,*args):pass
        def call(self,endpoint,body=None):return dict(config)
    class Source:
        scope='synthetic'
        def __init__(self,*args):pass
    def run(*args,should_stop=None,**kwargs):
        started.set();count=0
        while not release.is_set() and not should_stop():
            count+=1;progress.value=count;time.sleep(.02)
        result={'state':'finished','processed':count,'errors':0,'stats':{'done':count,'pending':0,'error':0},'published':count}
        return result
    w.initialize_pipelines(root)
    if kind=='faces':w.atomic(root/'control-faces.json',{'paused':False,'requested':True,'run_kind':'faces','request_id':'synthetic'})
    from types import SimpleNamespace
    def faces_cycle(api,config,directory,reporter,control):
        reporter.update(state='running')
        result=run(should_stop=lambda: w.admin_paused(root,'faces') or w.read(root/'control-faces.json',{}).get('paused',False))
        return {'faces':result,'embedding':{'state':'disabled'}}
    fake_setup=SimpleNamespace(tick=lambda *args,**kwargs:{'installed':True},python_path=lambda directory:Path(sys.executable),stop=lambda:None)
    with patch.dict(sys.modules,{'faces_setup':fake_setup}),patch.object(w,'faces_cycle',faces_cycle),patch.object(w.sys,'argv',['worker','--bootstrap',str(root/'bootstrap.json'),'--pipeline',kind,'--once']),patch.object(w,'API',API),patch.object(w,'NativeSource',Source),patch.object(w.p,'Model'),patch.object(w.p,'run',run),patch.object(w.embedding,'run',side_effect=lambda *args:run(should_stop=args[5])),patch.object(w,'ensure_local_embedding'),patch.object(w,'maintain_embedding_service',return_value=True),patch.object(w,'detect_embedding_rebuild',return_value=False):
        w.main()


class IndependentPipelines(unittest.TestCase):
    def test_real_processes_overlap_embedding_pause_leaves_description_progressing(self):
        context=multiprocessing.get_context('fork')
        with tempfile.TemporaryDirectory() as temporary:
            directory=Path(temporary);w.atomic(directory/'bootstrap.json',{});w.atomic(directory/'control.json',{'paused':False,'requested':True,'request_id':'synthetic','run_kind':'both'})
            started={kind:context.Event() for kind in w.PIPELINES};release=context.Event();progress={kind:context.Value('i',0) for kind in w.PIPELINES}
            workers={kind:context.Process(target=synthetic_worker,args=(temporary,kind,started[kind],release,progress[kind])) for kind in w.PIPELINES}
            try:
                for worker in workers.values():worker.start()
                for kind in w.PIPELINES:self.assertTrue(started[kind].wait(8),'Pipeline failed to enter inference: '+kind)
                self.assertTrue(all(worker.is_alive() for worker in workers.values()))
                self.assertEqual(w.read(directory/'status-descriptions.json',{})['state'],'running')
                self.assertEqual(w.read(directory/'status-embeddings.json',{})['state'],'running')
                w.atomic(directory/'processing-admin-embeddings.json',{'paused':True})
                workers['embeddings'].join(8);self.assertEqual(workers['embeddings'].exitcode,0)
                before=progress['descriptions'].value
                deadline=time.monotonic()+3
                while progress['descriptions'].value<=before and time.monotonic()<deadline:time.sleep(.02)
                self.assertGreater(progress['descriptions'].value,before)
                self.assertTrue(workers['descriptions'].is_alive())
                self.assertFalse(w.read(directory/'control-descriptions.json',{})['paused'])
                # A model transition may acquire the embedding gate while descriptions retain theirs.
                import fcntl
                with (directory/'processing-embeddings.lock').open('a') as gate:fcntl.flock(gate,fcntl.LOCK_EX|fcntl.LOCK_NB)
                with (directory/'processing-descriptions.lock').open('a') as gate:
                    with self.assertRaises(BlockingIOError):fcntl.flock(gate,fcntl.LOCK_EX|fcntl.LOCK_NB)
                release.set()
                for kind,worker in workers.items():
                    worker.join(8);self.assertEqual(worker.exitcode,0,'Pipeline did not finish cleanly: '+kind)
            finally:
                release.set()
                for worker in workers.values():
                    worker.join(3)
                    if worker.is_alive():worker.terminate();worker.join(3)

    def test_outage_pause_is_scoped_and_keeps_other_request_byte_for_byte(self):
        with tempfile.TemporaryDirectory() as temporary:
            directory=Path(temporary)
            for kind in w.PIPELINES:w.atomic(w.lane_file(directory,'control.json',kind),{'paused':False,'requested':True,'request_id':kind})
            other=(directory/'control-embeddings.json').read_bytes()
            w.pause_unavailable(directory,'LM Studio offline','descriptions')
            self.assertTrue(w.read(directory/'control-descriptions.json',{})['paused'])
            self.assertEqual((directory/'control-embeddings.json').read_bytes(),other)
            w.pause_unavailable(directory,'Embedding offline','embeddings')
            self.assertEqual(w.read(directory/'control-descriptions.json',{})['pause_reason'],'LM Studio offline')

    def test_migration_is_idempotent_and_preserves_database_schedule_and_selected_request(self):
        with tempfile.TemporaryDirectory() as temporary:
            directory=Path(temporary)
            w.atomic(directory/'control.json',{'paused':False,'requested':True,'run_kind':'descriptions','request_id':'old'})
            w.atomic(directory/'status.json',{'stats':{'done':9},'last_run':None});w.atomic(directory/'schedule.json',{'date':'2026-10-10','embedding_pending':True})
            for name in ('native.sqlite3','embeddings.sqlite3'):(directory/name).write_bytes(b'untouched cache')
            w.initialize_pipelines(directory)
            self.assertTrue(w.read(directory/'control-descriptions.json',{})['requested'])
            self.assertFalse(w.read(directory/'control-embeddings.json',{})['requested'])
            self.assertTrue(w.read(directory/'control-embeddings.json',{})['paused'])
            self.assertEqual(w.read(directory/'schedule-descriptions.json',{}),{'date':'2026-10-10'})
            self.assertEqual(w.read(directory/'status-descriptions.json',{})['stats'],{'done':9})
            w.atomic(directory/'control-embeddings.json',{'requested':True,'paused':False,'request_id':'new'})
            w.initialize_pipelines(directory)
            self.assertEqual(w.read(directory/'control-embeddings.json',{})['request_id'],'new')
            for name in ('native.sqlite3','embeddings.sqlite3'):self.assertEqual((directory/name).read_bytes(),b'untouched cache')

    def test_embedding_cycle_never_opens_or_rewrites_native_queue(self):
        class Reporter:
            kind='embeddings'
            def __init__(self):self.value={}
            def update(self,**fields):self.value.update(fields)
            def event(self,*args,**kwargs):pass
        with tempfile.TemporaryDirectory() as temporary:
            directory=Path(temporary);(directory/'native.sqlite3').write_bytes(b'untouched description cache')
            w.atomic(directory/'control-embeddings.json',{'paused':False})
            config={'analysis_enabled':True,'embedding':{'enabled':True}}
            result={'state':'finished','processed':1,'errors':0,'stats':{'done':1,'pending':0,'error':0}}
            with patch.object(w,'NativeSource'),patch.object(w,'ensure_local_embedding'),patch.object(w.embedding,'run',return_value=result),patch.object(w.p,'Store',side_effect=AssertionError('Embedding worker touched native queue')):
                w.cycle(None,config,directory,Reporter(),{'run_kind':'embeddings'})
            self.assertEqual((directory/'native.sqlite3').read_bytes(),b'untouched description cache')

    def test_idle_embedding_reporter_does_not_restore_counters_after_admin_reset(self):
        with tempfile.TemporaryDirectory() as temporary:
            directory=Path(temporary)
            reporter=w.Reporter(directory,kind='embeddings')
            try:
                reporter.update(embedding_stats={'done':8},stats={'done':8},embedding_space='old',last_run={'processed':8})
                w.atomic(directory/'embedding-cache-reset.json',{'revision':'admin-model-reset'})
                reporter.update(embedding_service={'ready':True})
                self.assertEqual(reporter.value['stats']['done'],0)
                self.assertIsNone(reporter.value['last_run'])
                self.assertNotIn('embedding_space',reporter.value)
                reporter.update(stats={'done':1})
                self.assertEqual(reporter.value['stats']['done'],1)  # Marker applies only once.
            finally:reporter.close()

    def test_supervisor_restarts_failed_embedding_process_without_restarting_descriptions(self):
        from types import SimpleNamespace
        class Shutdown:
            ticks=0
            def is_set(self):return self.ticks>=2
            def wait(self,seconds):self.ticks+=1
        shutdown=Shutdown();created=[]
        class Child:
            def __init__(self,command):
                self.kind=command[command.index('--pipeline')+1];self.terminated=False;self.returncode=None
                self.first_embedding=self.kind=='embeddings' and not any(child.kind=='embeddings' for child in created)
                created.append(self)
            def poll(self):
                if self.first_embedding and shutdown.ticks>=1:self.returncode=1
                return self.returncode
            def terminate(self):self.terminated=True;self.returncode=0
            def wait(self,timeout):return self.returncode
        with tempfile.TemporaryDirectory() as temporary,patch.object(w,'_shutdown',shutdown),patch.object(w.subprocess,'Popen',side_effect=Child):
            w.supervise(SimpleNamespace(bootstrap=str(Path(temporary)/'bootstrap.json'),once=False),Path(temporary))
        self.assertEqual([child.kind for child in created],['descriptions','ocr','embeddings','faces','embeddings'])
        self.assertTrue(created[0].terminated)  # Only stopped when the supervisor itself shuts down.
        self.assertFalse(created[2].terminated)  # This process failed independently.

    def test_empty_php_reset_marker_and_invalid_control_are_read_safely(self):
        with tempfile.TemporaryDirectory() as temporary:
            directory=Path(temporary)
            (directory/'embedding-rebuild.json').write_text('[]')  # PHP serializes an empty array this way.
            self.assertEqual(w.read(directory/'embedding-rebuild.json',{}),{})
            (directory/'control-embeddings.json').write_text('null')
            self.assertTrue(w.read(directory/'control-embeddings.json',{'paused':True})['paused'])
