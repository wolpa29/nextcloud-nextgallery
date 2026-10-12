import importlib.util
import json
import os
from pathlib import Path
import tempfile
import unittest
from unittest.mock import patch
spec=importlib.util.spec_from_file_location('external_installer',Path(__file__).resolve().parents[2]/'scripts/install-embedding-server.py')
m=importlib.util.module_from_spec(spec);spec.loader.exec_module(m)
class Installer(unittest.TestCase):
    def test_key_is_reused_with_private_files_and_minimal_container_privileges(self):
        with tempfile.TemporaryDirectory() as tmp:
            directory=Path(tmp)/'private';first=m.configure(directory,Path(tmp),'lite',9379,4)
            second=m.configure(directory,Path(tmp),'full',9380,2)
            self.assertEqual(first[0],second[0]);self.assertEqual(second[1],'embeddinggemma-2-full')
            for name in ['api-key.txt','.env','compose.json','connection.json']:self.assertEqual((directory/name).stat().st_mode&0o777,0o600)
            self.assertEqual(directory.stat().st_mode&0o777,0o700)
            c=json.loads((directory/'compose.json').read_text());service=c['services']['embedding']
            self.assertTrue(service['read_only']);self.assertEqual(service['cap_drop'],['ALL']);self.assertEqual(service['volumes'],['models:/data']);self.assertNotIn(first[0],json.dumps(c))
    def test_prepare_does_not_start_docker_or_download_models(self):
        with tempfile.TemporaryDirectory() as tmp,patch.object(m.subprocess,'run') as run:
            self.assertEqual(m.main(['--directory',tmp,'--prepare-only']),0);run.assert_not_called()
    def test_uninstall_removes_downloads_only_when_explicitly_requested(self):
        with tempfile.TemporaryDirectory() as tmp:
            directory=Path(tmp);m.configure(directory,Path(tmp),'lite',9379,4)
            for extra,wants_volumes in [([],False),(['--delete-models'],True)]:
                with patch.object(m.subprocess,'run') as run:
                    self.assertEqual(m.main(['--directory',tmp,'--action','uninstall',*extra]),0)
    def test_gpu_configuration_creates_reservations_and_environment(self):
        with tempfile.TemporaryDirectory() as tmp:
            directory=Path(tmp)/'private';key,model=m.configure(directory,Path(tmp),'full',9379,4,device='cuda',gpu_id='1')
            self.assertEqual(model,'embeddinggemma-2-full')
            c=json.loads((directory/'compose.json').read_text());service=c['services']['embedding']
            self.assertEqual(service['environment']['NEXTGALLERY_EMBEDDING_DEVICE'],'cuda')
            self.assertEqual(service['environment']['NVIDIA_VISIBLE_DEVICES'],'1')
            devs=service['deploy']['resources']['reservations']['devices']
            self.assertEqual(devs[0]['driver'],'nvidia')
            self.assertEqual(devs[0]['device_ids'],['1'])
            conn=json.loads((directory/'connection.json').read_text())
            self.assertEqual(conn['device'],'cuda');self.assertEqual(conn['gpu_id'],'1')
            self.assertEqual(conn['model'],'embeddinggemma-2-full')

    def test_multi_gpu_all_reservation(self):
        with tempfile.TemporaryDirectory() as tmp:
            directory=Path(tmp)/'private';key,model=m.configure(directory,Path(tmp),'full',9379,4,device='cuda',gpu_id='all')
            c=json.loads((directory/'compose.json').read_text());service=c['services']['embedding']
            devs=service['deploy']['resources']['reservations']['devices']
            self.assertEqual(devs[0]['count'],'all')
            self.assertEqual(service['environment']['NVIDIA_VISIBLE_DEVICES'],'all')

    def test_legacy_host_ordinal_does_not_become_container_ordinal(self):
        with tempfile.TemporaryDirectory() as tmp:
            directory=Path(tmp)
            m.configure(directory,directory,'full',9379,4,device='cuda:1',gpu_id='1')
            service=json.loads((directory/'compose.json').read_text())['services']['embedding']
            self.assertEqual(service['environment']['NEXTGALLERY_EMBEDDING_DEVICE'],'cuda')
            self.assertEqual(service['environment']['NVIDIA_VISIBLE_DEVICES'],'1')
            self.assertEqual(service['deploy']['resources']['reservations']['devices'][0]['device_ids'],['1'])

    def test_wizard_selects_second_host_gpu_without_container_ordinal(self):
        gpus=[{'index':'0','name':'RTX 3060','memory_mb':'12288'},
              {'index':'1','name':'RTX 3090','memory_mb':'24576'}]
        with tempfile.TemporaryDirectory() as tmp,patch.object(m.subprocess,'run'), \
             patch.object(m,'detect_gpus',return_value=gpus), \
             patch.object(m,'check_docker_nvidia_support',return_value=True), \
             patch.object(m,'prompt_choice',side_effect=[1,2]), \
             patch.object(m,'prompt_string',side_effect=['9379',tmp]), \
             patch.object(m,'prompt_yes_no',return_value=True),patch('builtins.print'):
            params,action=m.interactive_wizard(Path(tmp),Path(tmp),language='de')
        self.assertEqual(action,'install')
        self.assertEqual(params['device'],'cuda')
        self.assertEqual(params['gpu_id'],'1')
        self.assertIn('RTX 3090',params['gpu_info'])

    def test_start_repairs_legacy_configuration_before_docker_and_preserves_data(self):
        with tempfile.TemporaryDirectory() as tmp:
            directory=Path(tmp)
            m.configure(directory,directory,'full',9381,7,device='cuda',gpu_id='1')
            compose=json.loads((directory/'compose.json').read_text())
            compose['services']['embedding']['environment']['NEXTGALLERY_EMBEDDING_DEVICE']='cuda:1'
            (directory/'compose.json').write_text(json.dumps(compose))
            connection=json.loads((directory/'connection.json').read_text());connection['device']='cuda:1'
            (directory/'connection.json').write_text(json.dumps(connection))
            preserved={name:(directory/name).read_bytes() for name in ['api-key.txt','.env']}
            expected=json.loads(json.dumps(compose))
            expected['services']['embedding']['environment']['NEXTGALLERY_EMBEDDING_DEVICE']='cuda'
            def docker(cmd,**kwargs):
                self.assertEqual(json.loads((directory/'compose.json').read_text()),expected)
                return unittest.mock.Mock(returncode=0)
            with patch.object(m.subprocess,'run',side_effect=docker) as run,patch.object(m,'print_summary'),patch('builtins.print'):
                self.assertEqual(m.main(['--directory',tmp,'--action','start']),0)
            self.assertEqual(run.call_args_list[-1].args[0][-2:],['up','-d'])
            self.assertEqual(json.loads((directory/'connection.json').read_text()),connection|{'device':'cuda'})
            for name,contents in preserved.items():self.assertEqual((directory/name).read_bytes(),contents)
            self.assertEqual((directory/'compose.json').stat().st_mode&0o777,0o600)
            self.assertFalse(m.repair_saved_gpu_device(directory))

    def test_start_leaves_cpu_and_all_gpu_configurations_unchanged(self):
        for device,gpu_id in [('cpu',None),('cuda:1','all'),('cuda','1')]:
            with self.subTest(device=device,gpu_id=gpu_id),tempfile.TemporaryDirectory() as tmp:
                directory=Path(tmp)
                m.configure(directory,directory,'full',9379,4,device=device,gpu_id=gpu_id)
                files={file.name:file.read_bytes() for file in directory.iterdir()}
                with patch.object(m.subprocess,'run') as run,patch.object(m,'print_summary'):
                    run.return_value.returncode=0
                    self.assertEqual(m.main(['--directory',tmp,'--action','start']),0)
                self.assertEqual({file.name:file.read_bytes() for file in directory.iterdir()},files)

    def test_start_uses_saved_gpu_device_for_nvidia_error_guidance(self):
        with tempfile.TemporaryDirectory() as tmp:
            directory=Path(tmp)
            m.configure(directory,directory,'full',9381,7,device='cuda',gpu_id='1')
            with patch.object(m.subprocess,'run',side_effect=[unittest.mock.Mock(returncode=0),unittest.mock.Mock(returncode=1)]), \
                 patch.object(m.sys.stdin,'isatty',return_value=False),patch('builtins.print') as output:
                self.assertEqual(m.main(['--directory',tmp,'--action','start','--language','de']),1)
            self.assertTrue(any('NVIDIA-Grafikkarte' in str(call) for call in output.call_args_list))

    def test_start_cpu_fallback_preserves_saved_full_profile_port_threads_and_key(self):
        with tempfile.TemporaryDirectory() as tmp:
            directory=Path(tmp)
            m.configure(directory,directory,'full',9381,7,device='cuda',gpu_id='1')
            key=(directory/'api-key.txt').read_bytes()
            with patch.object(m.subprocess,'run',side_effect=[unittest.mock.Mock(returncode=0),unittest.mock.Mock(returncode=1),unittest.mock.Mock(returncode=0)]), \
                 patch.object(m.sys.stdin,'isatty',return_value=True),patch.object(m,'prompt_choice',return_value=3), \
                 patch.object(m,'print_summary'),patch('builtins.print'):
                self.assertEqual(m.main(['--directory',tmp,'--action','start']),0)
            service=json.loads((directory/'compose.json').read_text())['services']['embedding']
            self.assertEqual(service['environment']['NEXTGALLERY_EMBEDDING_PROFILE'],'full')
            self.assertEqual(service['environment']['NEXTGALLERY_EMBEDDING_THREADS'],'7')
            self.assertEqual(service['environment']['NEXTGALLERY_EMBEDDING_DEVICE'],'cpu')
            self.assertEqual(service['ports'],['9381:9379'])
            self.assertEqual((directory/'api-key.txt').read_bytes(),key)

    def test_detect_gpus_parses_nvidia_smi(self):
        fake_output='0, NVIDIA GeForce RTX 3080, 10240\n1, NVIDIA GeForce RTX 4090, 24576\n'
        fake_res=unittest.mock.Mock(returncode=0,stdout=fake_output)
        with patch.object(m.subprocess,'run',return_value=fake_res):
            gpus=m.detect_gpus()
            self.assertEqual(len(gpus),2)
            self.assertEqual(gpus[0],{'index':'0','name':'NVIDIA GeForce RTX 3080','memory_mb':'10240'})
            self.assertEqual(gpus[1],{'index':'1','name':'NVIDIA GeForce RTX 4090','memory_mb':'24576'})

    def test_info_action_outputs_summary(self):
        with tempfile.TemporaryDirectory() as tmp:
            directory=Path(tmp)
            m.configure(directory,Path(tmp),'full',9379,4,device='cuda',gpu_id='0')
            with patch('builtins.print') as mock_print:
                self.assertEqual(m.main(['--directory',tmp,'--action','info']),0)
            mock_print.assert_called()

    def test_check_docker_nvidia_support_when_probe_fails(self):
        fake_images=unittest.mock.Mock(returncode=0,stdout='abc1234\n')
        fake_probe=unittest.mock.Mock(returncode=1,stderr='could not select device driver "nvidia" with capabilities: [[gpu]]',stdout='')
        with patch.object(m.subprocess,'run',side_effect=[fake_images,fake_probe]):
            self.assertFalse(m.check_docker_nvidia_support())

    def test_check_docker_nvidia_support_when_probe_succeeds(self):
        fake_images=unittest.mock.Mock(returncode=0,stdout='abc1234\n')
        fake_probe=unittest.mock.Mock(returncode=0,stderr='',stdout='')
        with patch.object(m.subprocess,'run',side_effect=[fake_images,fake_probe]):
            self.assertTrue(m.check_docker_nvidia_support())

    def test_wait_for_docker_succeeds(self):
        with patch.object(m.subprocess,'run',return_value=unittest.mock.Mock(returncode=0)):
            self.assertTrue(m.wait_for_docker(timeout=2))

    def test_language_flag_explicit_and_defaults(self):
        with tempfile.TemporaryDirectory() as tmp, patch.object(m.subprocess, 'run'):
            # Non-interactive without explicit language defaults to English
            self.assertEqual(m.main(['--directory', tmp, '--prepare-only']), 0)
            self.assertEqual(m.get_language(), 'en')

            # Explicit --language de
            self.assertEqual(m.main(['--directory', tmp, '--prepare-only', '--language', 'de']), 0)
            self.assertEqual(m.get_language(), 'de')

            # Explicit --language en
            self.assertEqual(m.main(['--directory', tmp, '--prepare-only', '--language', 'en']), 0)
            self.assertEqual(m.get_language(), 'en')

    def test_wizard_language_selection_interactive(self):
        # When language='ask', wizard asks language first
        with tempfile.TemporaryDirectory() as tmp, patch.object(m.subprocess, 'run'), \
             patch.object(m, 'check_docker_nvidia_support', return_value=True), \
             patch.object(m, 'prompt_choice', side_effect=[1, 1, 1]), \
             patch.object(m, 'prompt_string', side_effect=['4', '9379', tmp]), \
             patch.object(m, 'prompt_yes_no', return_value=True), patch('builtins.print'):
            params, action = m.interactive_wizard(Path(tmp), Path(tmp), language='ask')
            self.assertEqual(action, 'install')
            self.assertEqual(m.get_language(), 'de')

        # When language='ask' and user selects English (option 2)
        with tempfile.TemporaryDirectory() as tmp, patch.object(m.subprocess, 'run'), \
             patch.object(m, 'check_docker_nvidia_support', return_value=True), \
             patch.object(m, 'prompt_choice', side_effect=[2, 1, 1]), \
             patch.object(m, 'prompt_string', side_effect=['4', '9379', tmp]), \
             patch.object(m, 'prompt_yes_no', return_value=True), patch('builtins.print'):
            params, action = m.interactive_wizard(Path(tmp), Path(tmp), language='ask')
            self.assertEqual(action, 'install')
            self.assertEqual(m.get_language(), 'en')

        # When language is explicitly provided, it does not prompt for language
        with tempfile.TemporaryDirectory() as tmp, patch.object(m.subprocess, 'run'), \
             patch.object(m, 'check_docker_nvidia_support', return_value=True), \
             patch.object(m, 'prompt_choice', side_effect=[1, 1]), \
             patch.object(m, 'prompt_string', side_effect=['4', '9379', tmp]), \
             patch.object(m, 'prompt_yes_no', return_value=True), patch('builtins.print'):
            params, action = m.interactive_wizard(Path(tmp), Path(tmp), language='en')
            self.assertEqual(action, 'install')
            self.assertEqual(m.get_language(), 'en')

    def test_summary_output_german_and_english(self):
        import io
        from contextlib import redirect_stdout

        with tempfile.TemporaryDirectory() as tmp:
            directory = Path(tmp)

            # German summary
            m.set_language('de')
            out_de = io.StringIO()
            with redirect_stdout(out_de):
                m.print_summary(directory, key='a' * 64, model='embeddinggemma-2', port=9379, profile='lite', device='cpu')
            val_de = out_de.getvalue()
            self.assertIn('NextGallery · Externer Embedding-Server eingerichtet', val_de)
            self.assertIn('Feld in Nextcloud', val_de)
            self.assertIn('Einzutragender Wert', val_de)
            self.assertIn('--action check', val_de)
            self.assertNotIn('erfolgreich eingerichtet', val_de)

            # English summary
            m.set_language('en')
            out_en = io.StringIO()
            with redirect_stdout(out_en):
                m.print_summary(directory, key='a' * 64, model='embeddinggemma-2', port=9379, profile='lite', device='cpu')
            val_en = out_en.getvalue()
            self.assertIn('NextGallery · External Embedding Server Configured', val_en)
            self.assertIn('Field in Nextcloud', val_en)
            self.assertIn('Value to enter', val_en)
            self.assertIn('--action check', val_en)
            self.assertNotIn('successfully set up', val_en)
            self.assertNotIn('erfolgreich', val_en)

    def test_check_does_not_invoke_docker_or_change_configuration(self):
        with tempfile.TemporaryDirectory() as tmp,patch.object(m.subprocess,'run') as docker, \
             patch.object(m.service_check,'run_check',return_value=0) as probe:
            self.assertEqual(m.main(['--action','check','--directory',tmp,'--language','de']),0)
            probe.assert_called_once_with(Path(tmp).resolve(),'de',None,None,None)
            docker.assert_not_called();self.assertEqual(list(Path(tmp).iterdir()),[])

    def test_custom_setup_keeps_existing_key_and_docker_configuration(self):
        with tempfile.TemporaryDirectory() as tmp:
            directory=Path(tmp);m.configure(directory,directory,'full',9379,4,device='cuda',gpu_id='1')
            before={file.name:file.read_bytes() for file in directory.iterdir()}
            key_file=directory/'external.key';key_file.write_text('synthetic-external-key\n')
            with patch.object(m.subprocess,'run') as docker,patch.object(m.service_check,'custom_setup',return_value=0) as setup:
                self.assertEqual(m.main(['--profile','custom','--directory',tmp,'--url','http://synthetic.invalid/v1','--model','operator/free-model','--api-key-file',str(key_file),'--language','en']),0)
                setup.assert_called_once_with('en','http://synthetic.invalid/v1','operator/free-model','synthetic-external-key',interactive=False)
                docker.assert_not_called()
            self.assertTrue(all((directory/name).read_bytes()==wire for name,wire in before.items()))

    def test_wizard_custom_does_not_require_docker(self):
        with tempfile.TemporaryDirectory() as tmp,patch.object(m.subprocess,'run',side_effect=AssertionError('Docker called')), \
             patch.object(m,'prompt_choice',return_value=3),patch('builtins.print'):
            params,action=m.interactive_wizard(Path(tmp),Path(tmp),language='en')
            self.assertEqual(action,'custom')
            self.assertEqual(list(Path(tmp).iterdir()),[])

    def test_language_flag_alone_still_opens_terminal_wizard(self):
        with patch.object(m.sys,'argv',['install-embedding-server.py','--language','en']), \
             patch.object(m.sys.stdin,'isatty',return_value=True), \
             patch.object(m,'interactive_wizard',return_value=({},'custom')) as wizard, \
             patch.object(m.service_check,'custom_setup',return_value=0):
            self.assertEqual(m.main(),0)
            self.assertEqual(wizard.call_args.kwargs['language'],'en')

    def test_lite_cuda_is_rejected_before_creating_files(self):
        with tempfile.TemporaryDirectory() as tmp,patch.object(m.subprocess,'run') as docker,patch('sys.stderr'):
            with self.assertRaises(SystemExit):m.main(['--directory',tmp,'--profile','lite','--device','cuda','--prepare-only'])
            docker.assert_not_called();self.assertEqual(list(Path(tmp).iterdir()),[])

    def test_lite_gpu_wizard_falls_back_to_cpu_when_full_is_declined(self):
        with tempfile.TemporaryDirectory() as tmp,patch.object(m.subprocess,'run'), \
             patch.object(m,'detect_gpus',return_value=[{'index':'1','name':'synthetic GPU','memory_mb':'24576'}]), \
             patch.object(m,'check_docker_nvidia_support',return_value=True), \
             patch.object(m,'prompt_choice',side_effect=[2,1]), \
             patch.object(m,'prompt_string',side_effect=['9379',tmp]), \
             patch.object(m,'prompt_yes_no',side_effect=[False,True]),patch('builtins.print'):
            params,action=m.interactive_wizard(Path(tmp),Path(tmp),language='en')
            self.assertEqual(params['profile'],'lite');self.assertEqual(params['device'],'cpu');self.assertIsNone(params['gpu_id'])

    def test_argparse_help_german_and_english(self):
        import io
        from contextlib import redirect_stdout

        # English help
        out_en = io.StringIO()
        with redirect_stdout(out_en), self.assertRaises(SystemExit):
            m.main(['--language', 'en', '--help'])
        val_en = out_en.getvalue()
        self.assertIn('Install and start the external NextGallery embedding service using Docker.', val_en)
        self.assertIn('Model setup', val_en)

        # German help
        out_de = io.StringIO()
        with redirect_stdout(out_de), self.assertRaises(SystemExit):
            m.main(['--language', 'de', '--help'])
        val_de = out_de.getvalue()
        self.assertIn('Externen NextGallery Embedding-Dienst mit Docker installieren und starten.', val_de)
        self.assertIn('Modellsetup', val_de)


if __name__=='__main__':unittest.main()
