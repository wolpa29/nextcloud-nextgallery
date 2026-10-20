"""Unit tests for faces_setup module."""
import hashlib
import io
import json
from pathlib import Path
import tempfile
import unittest
from unittest.mock import Mock, patch

import faces_setup as setup


class Response(io.BytesIO):
    status = 200


class FacesSetupTests(unittest.TestCase):
    def tearDown(self):
        setup._process = None
        setup._identity = None
        setup._retry_at = 0.0
        setup._request_id = None

    def test_paths_and_identity(self):
        with tempfile.TemporaryDirectory() as temp:
            d = Path(temp)
            root, py, models = setup.paths(d)
            self.assertEqual(root, d / 'faces-local')
            self.assertEqual(py, d / 'faces-local/runtime/bin/python')
            self.assertEqual(models, d / 'faces-local/models')

            self.assertEqual(setup.python_path(d), py)
            m_paths = setup.model_paths(d)
            self.assertIn('detector', m_paths)
            self.assertIn('recognizer', m_paths)
            self.assertTrue(m_paths['detector'].name.endswith('.onnx'))
            self.assertTrue(m_paths['recognizer'].name.endswith('.onnx'))

            ident = setup.identity()
            self.assertIsInstance(ident, str)
            self.assertIn('|', ident)

    def test_cached_detection(self):
        with tempfile.TemporaryDirectory() as temp:
            d = Path(temp)
            self.assertFalse(setup.cached(d))

            root, py, models = setup.paths(d)
            models.mkdir(parents=True)
            py.parent.mkdir(parents=True)
            py.touch()

            manifest = setup.manifest()
            det = models / manifest['detector']['name']
            rec = models / manifest['recognizer']['name']
            det.write_bytes(b'x' * manifest['detector']['bytes'])
            rec.write_bytes(b'y' * manifest['recognizer']['bytes'])

            setup.atomic(root / 'ready.json', {'identity': setup.identity()})
            self.assertTrue(setup.cached(d))

    def test_tick_not_requested_returns_not_installed(self):
        with tempfile.TemporaryDirectory() as temp:
            d = Path(temp)
            status = setup.tick(d, requested=False)
            self.assertEqual(status['phase'], 'not_installed')
            self.assertFalse(status['installed'])
            self.assertFalse(status['ready'])

    def test_tick_installed_clears_request_and_returns_ready(self):
        with tempfile.TemporaryDirectory() as temp:
            d = Path(temp)
            root, py, models = setup.paths(d)
            models.mkdir(parents=True)
            py.parent.mkdir(parents=True)
            py.touch()

            manifest = setup.manifest()
            det = models / manifest['detector']['name']
            rec = models / manifest['recognizer']['name']
            det.write_bytes(b'x' * manifest['detector']['bytes'])
            rec.write_bytes(b'y' * manifest['recognizer']['bytes'])
            setup.atomic(root / 'ready.json', {'identity': setup.identity()})
            setup.atomic(d / 'faces-install.json', {'requested': True})

            status = setup.tick(d)
            self.assertEqual(status['phase'], 'installed')
            self.assertTrue(status['installed'])
            self.assertTrue(status['ready'])
            self.assertFalse(setup.read(d / 'faces-install.json').get('requested'))

    def test_download_model_verifies_checksum_and_size(self):
        with tempfile.TemporaryDirectory() as temp:
            target = Path(temp) / 'model.onnx'
            content = b'synthetic-model-weights'
            sha = hashlib.sha256(content).hexdigest()
            opener = Mock()
            opener.open.return_value = Response(content)

            with patch.object(setup.urllib.request, 'build_opener', return_value=opener):
                res = setup.download_model(target, 'https://example.invalid/model.onnx', len(content), sha, 'Test-Modell', lambda **kw: None)
                self.assertEqual(res, target)
                self.assertEqual(target.read_bytes(), content)

            # Test invalid hash raises
            with patch.object(setup.urllib.request, 'build_opener', return_value=opener):
                with self.assertRaises(ValueError):
                    setup.download_model(target, 'https://example.invalid/model.onnx', len(content), 'wrong-hash', 'Test-Modell', lambda **kw: None)

    def test_remove_cleans_runtime(self):
        with tempfile.TemporaryDirectory() as temp:
            d = Path(temp)
            root, _, _ = setup.paths(d)
            root.mkdir(parents=True)
            (root / 'dummy.txt').touch()
            (d / 'faces-setup.json').touch()

            setup.remove(d)
            self.assertFalse(root.exists())
            self.assertFalse((d / 'faces-setup.json').exists())


if __name__ == '__main__':
    unittest.main()
