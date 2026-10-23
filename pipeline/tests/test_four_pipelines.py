"""Opt-in fourth lane must preserve existing worker state byte for byte."""
import json
from pathlib import Path
import sys
import tempfile
import unittest

sys.path.insert(0, str(Path(__file__).resolve().parents[2] / 'worker'))
import app_worker as worker


class FourthPipelineMigration(unittest.TestCase):
    def test_version_two_preserves_all_existing_controls_caches_and_schedules(self):
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            worker.atomic(root / 'pipelines.json', {'version': 2})
            preserved = {}
            for kind in ('descriptions', 'ocr', 'embeddings'):
                for name in ('control', 'schedule', 'status'):
                    path = root / f'{name}-{kind}.json'
                    value = {'paused': False, 'requested': True, 'request_id': kind} if name == 'control' else {'existing': kind}
                    worker.atomic(path, value)
                    preserved[path] = path.read_bytes()
            for name in ('native-descriptions.sqlite3', 'native-ocr.sqlite3', 'embeddings.sqlite3'):
                path = root / name
                path.write_bytes(b'protected existing cache')
                preserved[path] = path.read_bytes()
            worker.initialize_pipelines(root)
            self.assertEqual(json.loads((root / 'pipelines.json').read_text())['version'], 4)
            self.assertTrue(worker.read(root / 'control-faces.json', {})['paused'])
            self.assertFalse(worker.read(root / 'control-faces.json', {})['requested'])
            for path, content in preserved.items():
                self.assertEqual(path.read_bytes(), content, str(path))
            worker.atomic(root / 'control-faces.json', {'paused': False, 'requested': True, 'request_id': 'explicit'})
            worker.initialize_pipelines(root)
            self.assertEqual(worker.read(root / 'control-faces.json', {})['request_id'], 'explicit')


if __name__ == '__main__':
    unittest.main()
