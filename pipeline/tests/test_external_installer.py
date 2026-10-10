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
                    command=run.call_args_list[-1].args[0];self.assertIn('down',command);self.assertEqual('--volumes' in command,wants_volumes)
if __name__=='__main__':unittest.main()
