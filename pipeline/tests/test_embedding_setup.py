import hashlib
import io
import json
from pathlib import Path
import tempfile
import unittest
from unittest.mock import Mock, patch
import embedding_setup as setup
import app_worker as worker
import media_pipeline as pipeline
from test_embedding import config, API, Reporter, Source


class Response(io.BytesIO):
    status=200


class SetupTests(unittest.TestCase):
    def tearDown(self):
        setup._process=None;setup._identity=None;setup._retry_at=0.;setup._request_id=None

    def download(self, root, payload, expected=b'synthetic-model'):
        opener=Mock();opener.open.return_value=Response(payload)
        with patch.object(setup,'MODEL_BYTES',len(expected)),patch.object(setup,'MODEL_SHA256',hashlib.sha256(expected).hexdigest()),patch.object(setup.urllib.request,'build_opener',return_value=opener):
            return setup.download(root,lambda **fields:None)

    def test_complete_verified_download_is_atomic_and_private(self):
        with tempfile.TemporaryDirectory() as temp:
            root=Path(temp);model=self.download(root,b'synthetic-model')
            self.assertEqual(model.read_bytes(),b'synthetic-model')
            self.assertEqual(model.stat().st_mode&0o777,0o600)
            self.assertFalse(list(root.glob('*.download')))

    def test_truncated_oversized_and_wrong_hash_never_replace_model(self):
        for payload in (b'short',b'oversized-invalid-model',b'wrong-value!!!!'):
            with self.subTest(payload=payload),tempfile.TemporaryDirectory() as temp:
                root=Path(temp);model=root/setup.MODEL_NAME;model.write_bytes(b'existing')
                with self.assertRaises(ValueError):self.download(root,payload)
                self.assertEqual(model.read_bytes(),b'existing')
                self.assertFalse(list(root.glob('*.download')))

    def test_verified_cached_model_needs_no_network(self):
        with tempfile.TemporaryDirectory() as temp:
            root=Path(temp);model=root/setup.MODEL_NAME;model.write_bytes(b'synthetic-model')
            with patch.object(setup,'MODEL_BYTES',15),patch.object(setup,'MODEL_SHA256',hashlib.sha256(b'synthetic-model').hexdigest()),patch.object(setup.urllib.request,'build_opener',side_effect=AssertionError('Unexpected network')):
                self.assertEqual(setup.download(root,lambda **kw:None),model)

    def test_redirect_cannot_downgrade_to_http(self):
        with self.assertRaises(ValueError):setup.HTTPSRedirects().redirect_request(None,None,302,'',{},'http://example.invalid/model')

    def test_ready_cache_does_not_spawn_installer(self):
        with tempfile.TemporaryDirectory() as temp:
            directory=Path(temp);root=directory/'embedding-local';(root/'runtime/bin').mkdir(parents=True)
            (root/'runtime/bin/python').touch();(root/setup.MODEL_NAME).write_bytes(b'synthetic-model')
            setup.atomic(root/'ready.json',{'identity':setup.identity()})
            with patch.object(setup,'MODEL_BYTES',15),patch.object(setup.subprocess,'Popen',side_effect=AssertionError('Reinstalled cached runtime')):
                progress,python,model=setup.poll(directory)
                self.assertEqual(progress['phase'],'installed');self.assertTrue(python.is_file());self.assertTrue(model.is_file())

    def test_runtime_failure_reports_error_without_ready_marker(self):
        with tempfile.TemporaryDirectory() as temp:
            root=Path(temp)
            with patch.object(setup.venv,'EnvBuilder',side_effect=RuntimeError('No venv support')):
                with self.assertRaises(RuntimeError):setup.install(root)
            self.assertEqual(setup.read(root/'setup.json')['phase'],'error')
            self.assertFalse((root/'ready.json').exists())

    def test_failed_installer_backs_off_instead_of_spawning_each_poll(self):
        with tempfile.TemporaryDirectory() as temp:
            root=Path(temp)/'embedding-local';root.mkdir();setup.atomic(root/'setup.json',{'phase':'error','message':'Synthetic failure'})
            setup.atomic(Path(temp)/'embedding-install.json',{'requested':True,'request_id':'synthetic'})
            setup._request_id='synthetic'
            setup._process=Mock();setup._process.poll.return_value=1;setup._process.returncode=1
            setup._identity=(str(root),setup.identity())
            with patch.object(setup.subprocess,'Popen',side_effect=AssertionError('Retried immediately')):
                for _ in range(3):self.assertEqual(setup.poll(Path(temp))[0]['phase'],'error')

    def test_external_pipeline_never_installs(self):
        for enabled,location in ((False,'external'),(True,'external')):
            c=config();c['embedding'].update(enabled=enabled,location=location,local_auto_setup=True)
            with patch.object(setup,'poll',side_effect=AssertionError('Installer started')):
                self.assertTrue(worker.maintain_embedding_service(c,Path('/synthetic-unused'),Reporter()))

    def test_selecting_saving_or_starting_never_downloads_without_install_request(self):
        with tempfile.TemporaryDirectory() as temp:
            root=Path(temp)
            with patch.object(setup.subprocess,'Popen',side_effect=AssertionError('Implicit installer start')):
                for _ in range(3):self.assertEqual(setup.poll(root)[0]['phase'],'not_installed')
                c=config();c['embedding'].update(location='local',local_auto_setup=True)
                with self.assertRaises(worker.EmbeddingSetupPending):worker.ensure_local_embedding(c,root)

    def test_explicit_install_works_before_enabling_media_processing(self):
        with tempfile.TemporaryDirectory() as temp:
            root=Path(temp);setup.atomic(root/'embedding-install.json',{'requested':True,'request_id':'synthetic'})
            process=Mock();process.poll.return_value=None
            c=config();c['embedding'].update(location='local',local_auto_setup=True,enabled=False)
            with patch.object(setup.subprocess,'Popen',return_value=process) as spawn:
                self.assertTrue(worker.maintain_embedding_service(c,root,Reporter()))
                setup.poll(root)
                spawn.assert_called_once()

    def test_cached_install_consumes_request_and_remains_available(self):
        with tempfile.TemporaryDirectory() as temp:
            directory=Path(temp);root=directory/'embedding-local';(root/'runtime/bin').mkdir(parents=True)
            (root/'runtime/bin/python').touch();(root/setup.MODEL_NAME).write_bytes(b'synthetic-model')
            setup.atomic(root/'ready.json',{'identity':setup.identity()});setup.atomic(directory/'embedding-install.json',{'requested':True,'request_id':'synthetic'})
            with patch.object(setup,'MODEL_BYTES',15),patch.object(setup.subprocess,'Popen',side_effect=AssertionError('Cached installer start')):
                self.assertTrue(setup.poll(directory)[0]['installed'])
                self.assertFalse(setup.read(directory/'embedding-install.json')['requested'])

    def test_changing_host_cancels_installer_process_group(self):
        process=Mock(pid=12345);process.poll.return_value=None;setup._process=process
        c=config();c['embedding'].update(location='external',local_auto_setup=True)
        with patch.object(setup.os,'killpg') as terminate:
            worker.maintain_embedding_service(c,Path('/synthetic-unused'),Reporter())
        terminate.assert_called_once_with(12345,setup.signal.SIGTERM)
        process.wait.assert_called_once_with(timeout=5)
        self.assertIsNone(setup._process)

    def test_pending_installation_is_progress_not_worker_outage(self):
        c=config();c['embedding'].update(location='local',local_auto_setup=True)
        reporter=Reporter();progress={'phase':'downloading','message':'Synthetic setup','received_bytes':123,'total_bytes':456}
        with patch.object(worker,'_embedding_service_check',(None,0.)),patch.object(setup,'poll',return_value=(progress,None,None)):
            self.assertFalse(worker.maintain_embedding_service(c,Path('/synthetic-unused'),reporter))
        self.assertEqual(reporter.value['embedding_service']['received_bytes'],123)
        self.assertFalse(reporter.value['embedding_service']['ready'])

    def test_setup_pending_keeps_description_processing_and_resume(self):
        c=config();c['analysis_enabled']=True;c['embedding'].update(location='local',local_auto_setup=True)
        c.update(vision={'model':'synthetic'},ocr={'model':'synthetic'})
        pending=worker.EmbeddingSetupPending({'phase':'downloading','message':'Synthetic setup'})
        completed={'state':'finished','processed':1,'errors':0,'published':1,'cached_stage_results':0}
        with tempfile.TemporaryDirectory() as temp:
            root=Path(temp);worker.atomic(root/'control.json',{'paused':False})
            with patch.object(worker,'NativeSource',return_value=Source()),patch.object(pipeline,'Model'),patch.object(pipeline,'run',return_value=completed) as captions,patch.object(worker,'ensure_local_embedding',side_effect=pending):
                result=worker.cycle(API(),c,root,Reporter(),{})
            captions.assert_called_once();self.assertEqual(result['embedding']['state'],'preparing')
            self.assertFalse(worker.read(root/'control.json',{})['paused'])
            self.assertTrue(worker.is_due({'enabled':False,'embedding':{'enabled':True}},{'paused':False},{'embedding_pending':True}))
            self.assertFalse(worker.is_due({'enabled':False,'embedding':{'enabled':False}},{'paused':False},{'embedding_pending':True}))


    def test_full_profile_is_separate_and_requires_its_own_request(self):
        with tempfile.TemporaryDirectory() as temp,patch.object(setup.subprocess,'Popen',side_effect=AssertionError('Implicit install')):
            directory=Path(temp)
            setup.atomic(directory/'embedding-install.json',{'requested':True,'profile':'lite','request_id':'lite-only'})
            self.assertEqual(setup.poll(directory,'full')[0]['phase'],'not_installed')
            self.assertTrue(setup.read(directory/'embedding-install.json')['requested'])
            self.assertNotEqual(setup.paths(directory,'lite')[0],setup.paths(directory,'full')[0])
            with self.assertRaises(ValueError):setup.paths(directory,'../../state')

    def test_full_download_verifies_each_file_and_rejects_corrupt_weights(self):
        data=b'synthetic-full-weights';manifest={'repository':'synthetic','revision':'a'*40,'files':[{'name':'model.safetensors','bytes':len(data),'sha256':hashlib.sha256(data).hexdigest()}]}
        for payload in (data,b'wrong'):
            with self.subTest(payload=payload),tempfile.TemporaryDirectory() as temp:
                root=Path(temp);manifestpath=root/'manifest.json';setup.atomic(manifestpath,manifest)
                opener=Mock();opener.open.return_value=Response(payload)
                with patch.object(setup,'FULL_MANIFEST',manifestpath),patch.object(setup.urllib.request,'build_opener',return_value=opener):
                    if payload==data:
                        model=setup.download_full(root,lambda **fields:None);self.assertEqual((model/'model.safetensors').read_bytes(),data)
                    else:
                        with self.assertRaises(ValueError):setup.download_full(root,lambda **fields:None)
                        self.assertFalse((root/'model/model.safetensors').exists())
                    self.assertFalse(list((root/'model').glob('*.download')))

    def test_full_request_spawns_the_full_installer_once(self):
        with tempfile.TemporaryDirectory() as temp:
            directory=Path(temp);setup.atomic(directory/'embedding-install.json',{'requested':True,'profile':'full','request_id':'full-only'})
            process=Mock();process.poll.return_value=None
            with patch.object(setup.subprocess,'Popen',return_value=process) as spawn:
                for _ in range(3):self.assertEqual(setup.poll(directory,'full')[0]['profile'],'full')
                spawn.assert_called_once();self.assertEqual(spawn.call_args.args[0][-2:],['--profile','full'])

    def test_cached_lite_does_not_consume_a_full_install_request(self):
        with tempfile.TemporaryDirectory() as temp:
            directory=Path(temp);root,python,model=setup.paths(directory,'lite');python.parent.mkdir(parents=True);python.touch();model.write_bytes(b'x')
            setup.atomic(root/'ready.json',{'identity':setup.identity()});setup.atomic(directory/'embedding-install.json',{'requested':True,'profile':'full'})
            with patch.object(setup,'MODEL_BYTES',1):self.assertTrue(setup.poll(directory,'lite')[0]['installed'])
            self.assertTrue(setup.read(directory/'embedding-install.json')['requested'])

    def test_removing_selected_profile_preserves_vectors_control_and_other_model(self):
        with tempfile.TemporaryDirectory() as temp:
            directory=Path(temp)
            for name in ('native.sqlite3','embedding.sqlite3','control.json'):(directory/name).write_text('keep')
            for profile in ('lite','full'):
                root=setup.paths(directory,profile)[0];root.mkdir();(root/'weights').write_text(profile)
            setup.remove(directory,'full')
            self.assertFalse(setup.paths(directory,'full')[0].exists());self.assertTrue(setup.paths(directory,'lite')[0].exists())
            for name in ('native.sqlite3','embedding.sqlite3','control.json'):self.assertEqual((directory/name).read_text(),'keep')

    def test_uninstall_refuses_linked_model_directory(self):
        with tempfile.TemporaryDirectory() as temp,tempfile.TemporaryDirectory() as other:
            directory=Path(temp);outside=Path(other);(outside/'keep').write_text('keep');setup.paths(directory,'full')[0].symlink_to(outside,target_is_directory=True)
            with self.assertRaises(ValueError):setup.remove(directory,'full')
            self.assertTrue((outside/'keep').exists())

    def test_worker_handles_uninstall_without_media_run_or_reinstall(self):
        with tempfile.TemporaryDirectory() as temp:
            directory=Path(temp);root=setup.paths(directory,'full')[0];root.mkdir();(root/'weights').write_text('synthetic')
            setup.atomic(directory/'embedding-remove.json',{'requested':True,'profile':'full'})
            c=config();c['embedding'].update(location='local',local_auto_setup=True,local_profile='full',enabled=False)
            with patch.object(setup.subprocess,'Popen',side_effect=AssertionError('Reinstalled removed model')):
                self.assertTrue(worker.maintain_embedding_service(c,directory,Reporter()))
            self.assertFalse(setup.read(directory/'embedding-remove.json')['requested']);self.assertFalse((root/'weights').exists())


    def test_changed_dimensions_and_runtime_require_one_approval_per_space(self):
        import media_ai_embedding as embeddings
        c=config();c['embedding_config_id']='synthetic-config';directory=None
        with tempfile.TemporaryDirectory() as temp:
            directory=Path(temp);store=embeddings.Store(directory/'embeddings.sqlite3')
            old=embeddings.space_id({'space':'a'*64},c['embedding'])
            with store.db:store.db.execute("INSERT INTO embeddings(id,space,etag,path,status,active,vectors) VALUES (?,?,?,?,?,1,?)",('synthetic',old,'v1','synthetic.png','done',json.dumps([{'values':[1.]+[0.]*127}])))
            store.db.close();info={'space':'b'*64,'dimensions':768};client=Mock();client.health.side_effect=lambda:info
            with patch.object(embeddings,'Client',return_value=client):
                self.assertTrue(worker.detect_embedding_rebuild(c,directory,Reporter()))
                marker=setup.read(directory/'embedding-rebuild.json');self.assertEqual(marker['old_dimensions'],128);self.assertEqual(marker['new_dimensions'],768)
                first=marker['request_id'];marker.update(required=False,approved=True,approved_space=marker['target_space']);setup.atomic(directory/'embedding-rebuild.json',marker)
                self.assertFalse(worker.detect_embedding_rebuild(c,directory,Reporter()))
                info['space']='c'*64
                self.assertTrue(worker.detect_embedding_rebuild(c,directory,Reporter()))
                self.assertNotEqual(setup.read(directory/'embedding-rebuild.json')['request_id'],first)
                self.assertEqual(setup.read(directory/'embedding-rebuild.json')['new_dimensions'],768)

    def test_pending_rebuild_blocks_even_when_new_service_is_unavailable(self):
        c=config()
        with tempfile.TemporaryDirectory() as temp:
            directory=Path(temp);setup.atomic(directory/'embedding-rebuild.json',{'required':True})
            with patch.object(worker.embedding,'Client',side_effect=pipeline.ServiceUnavailable('Offline')):
                self.assertTrue(worker.detect_embedding_rebuild(c,directory,Reporter()))


    def test_scheduled_run_waits_for_rebuild_confirmation(self):
        c=config();c.update(enabled=True,timezone='Europe/Vienna',log_days=30,schedule_time='00:00')
        with tempfile.TemporaryDirectory() as temp:
            directory=Path(temp);setup.atomic(directory/'bootstrap.json',{});setup.atomic(directory/'control.json',{'paused':False});setup.atomic(directory/'embedding-rebuild.json',{'required':True})
            api=Mock();api.call.return_value=c;reporter=Mock();client=Mock();client.health.return_value={'space':'b'*64,'dimensions':768}
            with patch.object(worker.sys,'argv',['worker','--bootstrap',str(directory/'bootstrap.json'),'--once']),patch.object(worker,'API',return_value=api),patch.object(worker,'Reporter',return_value=reporter),patch.object(worker,'maintain_embedding_service',return_value=True),patch.object(worker.embedding,'Client',return_value=client),patch.object(worker,'cycle',side_effect=AssertionError('Scheduled rebuild started without approval')):
                worker.main()
            self.assertTrue(setup.read(directory/'embedding-rebuild.json')['required'])
            reporter.update.assert_any_call(state='paused',current=None,message='Modellwechsel: Bitte zuerst den Neuaufbau der Bildvektoren bestätigen.')


    def test_admin_pause_blocks_manual_and_scheduled_jobs(self):
        c=config();c.update(enabled=True,timezone='Europe/Vienna',log_days=30,schedule_time='00:00')
        for requested in (False,True):
            with tempfile.TemporaryDirectory() as temp:
                directory=Path(temp);setup.atomic(directory/'bootstrap.json',{});setup.atomic(directory/'control.json',{'paused':False,'requested':requested});setup.atomic(directory/'processing-admin.json',{'paused':True})
                api=Mock();api.call.return_value=c;reporter=Mock()
                with patch.object(worker.sys,'argv',['worker','--bootstrap',str(directory/'bootstrap.json'),'--once']),patch.object(worker,'API',return_value=api),patch.object(worker,'Reporter',return_value=reporter),patch.object(worker,'maintain_embedding_service',return_value=True),patch.object(worker,'cycle',side_effect=AssertionError('Job bypassed admin pause')):
                    worker.main()
                self.assertEqual(api.call.call_count,1)
                self.assertEqual(setup.read(directory/'control.json')['requested'],requested)

    def test_manual_rebuild_waits_for_model_installation_without_second_start(self):
        c=config();c.update(enabled=False,timezone='Europe/Vienna',log_days=30,schedule_time='00:00')
        with tempfile.TemporaryDirectory() as temp:
            directory=Path(temp);setup.atomic(directory/'bootstrap.json',{});setup.atomic(directory/'control.json',{'paused':False,'requested':True,'request_id':'synthetic-rebuild','run_kind':'embeddings'})
            api=Mock();api.call.return_value=c;reporter=Mock();client=Mock();client.health.return_value={'space':'b'*64,'dimensions':768}
            with patch.object(worker.sys,'argv',['worker','--bootstrap',str(directory/'bootstrap.json'),'--once']),patch.object(worker,'API',return_value=api),patch.object(worker,'Reporter',return_value=reporter),patch.object(worker,'maintain_embedding_service',return_value=False),patch.object(worker.embedding,'Client',return_value=client),patch.object(worker,'cycle',return_value={'embedding':{'state':'preparing'}}):
                worker.main()
            control=setup.read(directory/'control.json');schedule=setup.read(directory/'schedule.json')
            self.assertFalse(control['paused']);self.assertFalse(control['requested']);self.assertTrue(schedule['embedding_pending'])
            self.assertTrue(worker.is_due(c,control,schedule))

    def test_model_transition_lock_blocks_worker_start(self):
        import fcntl
        c=config();c.update(enabled=True,timezone='Europe/Vienna',log_days=30,schedule_time='00:00')
        with tempfile.TemporaryDirectory() as temp:
            directory=Path(temp);setup.atomic(directory/'bootstrap.json',{});setup.atomic(directory/'control.json',{'paused':False,'requested':True})
            api=Mock();api.call.return_value=c;reporter=Mock()
            with (directory/'processing.lock').open('a') as gate:
                fcntl.flock(gate,fcntl.LOCK_EX)
                with patch.object(worker.sys,'argv',['worker','--bootstrap',str(directory/'bootstrap.json'),'--once']),patch.object(worker,'API',return_value=api),patch.object(worker,'Reporter',return_value=reporter),patch.object(worker,'maintain_embedding_service',return_value=True),patch.object(worker,'cycle',side_effect=AssertionError('Job bypassed model-change gate')):
                    worker.main()
                self.assertTrue(setup.read(directory/'control.json')['requested'])


if __name__=='__main__':unittest.main()
