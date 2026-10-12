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

if __name__=='__main__':unittest.main()
