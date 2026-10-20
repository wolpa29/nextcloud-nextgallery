"""Unit tests for shared per-file face artifacts cache and independent account state."""
from __future__ import annotations

import io
import math
from pathlib import Path
import tempfile
import unittest
import urllib.error
from unittest.mock import Mock, patch

from PIL import Image

import media_ai_faces as faces


def make_unit_vector(dim: int = 128, index: int = 0) -> list[float]:
    v = [0.0] * dim
    v[index] = 1.0
    return v


class SharedMockItem:
    def __init__(
        self,
        file_id: int,
        etag: str,
        path: str = '/photos/shared.jpg',
        mime: str = 'image/jpeg',
        analyzed_etag: str = '',
    ):
        self.locator = str(file_id)
        self.etag = etag
        self.path = path
        self.mime = mime
        self.analyzed_etag = analyzed_etag


class SharedMockSource:
    def __init__(self, items: list[SharedMockItem], raw_bytes: bytes, unchanged: bool = True):
        self.items = items
        self.raw = raw_bytes
        self._unchanged = unchanged
        self.read_calls: list[str] = []

    def inventory(self):
        return self.items

    def read(self, item, max_bytes):
        self.read_calls.append(item.locator)
        return self.raw

    def unchanged(self, item):
        return self._unchanged


class FakeSharedBackend:
    """Simulates Nextcloud backend with shared raw face-artifacts cache and multi-account state."""

    def __init__(self, epoch: str = 'epoch-shared-1', generation: int = 1, rules_rev: int = 1):
        self.epoch = epoch
        self.generation = generation
        self.rules_rev = rules_rev
        self.model_space = faces.MODEL_SPACE
        self.inference_revision = '0123456789abcdef' * 4  # 64 hex characters
        self.shared_cache: dict[tuple[int, str], dict] = {}
        self.accounts: dict[str, dict] = {}
        self.global_share_enabled = True

    def get_api(self, user_id: str) -> FakeUserAPI:
        if user_id not in self.accounts:
            self.accounts[user_id] = {'published': [], 'items': []}
        return FakeUserAPI(self, user_id)


class FakeUserAPI:
    def __init__(self, backend: FakeSharedBackend, user_id: str):
        self.backend = backend
        self.user_id = user_id
        self.artifact_override = None
        self.artifact_error = None
        self.publish_error = None
        self.on_publish_error = None
        self.denied_files: set[tuple[int, str]] = set()

    def call(self, endpoint: str, body: dict = None):
        if endpoint.startswith('faces-state'):
            return {
                'epoch': self.backend.epoch,
                'generation': self.backend.generation,
                'rules_revision': self.backend.rules_rev,
                'model_space': self.backend.model_space,
                'inference_revision': self.backend.inference_revision,
                'items': self.backend.accounts[self.user_id]['items'],
                'next': None,
                'more': False,
            }

        if endpoint == 'face-artifacts':
            file_id = body['file_id']
            etag = body['etag']
            key = (file_id, etag)

            if self.artifact_error:
                err = self.artifact_error
                self.artifact_error = None
                raise err

            if key in self.denied_files:
                raise urllib.error.HTTPError(
                    url='http://127.0.0.1/face-artifacts',
                    code=403,
                    msg='Forbidden',
                    hdrs={},
                    fp=io.BytesIO(b'{"error": "Access denied to face artifacts"}'),
                )

            if self.artifact_override is not None:
                if callable(self.artifact_override):
                    return self.artifact_override(body)
                return self.artifact_override

            if not self.backend.global_share_enabled:
                return {
                    'detections': None,
                    'inference_revision': self.backend.inference_revision,
                    'disabled': True,
                }

            cached = self.backend.shared_cache.get(key)
            if cached is not None:
                return {
                    'detections': cached['detections'],
                    'inference_revision': cached['inference_revision'],
                    'disabled': False,
                }

            return {
                'detections': None,
                'inference_revision': self.backend.inference_revision,
                'disabled': False,
            }

        if endpoint == 'publish-faces':
            if self.publish_error:
                err = self.publish_error
                self.publish_error = None
                if self.on_publish_error:
                    self.on_publish_error()
                raise err

            file_id = body['file_id']
            etag = body['etag']
            key = (file_id, etag)

            # Atomically cache raw detections ONLY on accepted publish
            # Cache contains strictly bbox, vector, score (NO group_id or id)
            raw_detections = []
            for d in body.get('detections', []):
                raw_detections.append({
                    'bbox': d['bbox'],
                    'vector': d['vector'],
                    'score': d['score'],
                })

            self.backend.shared_cache[key] = {
                'detections': raw_detections,
                'inference_revision': body.get('inference_revision', self.backend.inference_revision),
            }

            self.backend.accounts[self.user_id]['published'].append(body)

            canon = []
            for i, d in enumerate(body.get('detections', [])):
                canon_item = {
                    'id': f"canon-{self.user_id}-{file_id}-{i}",
                    'file_id': file_id,
                    'etag': etag,
                    'bbox': d['bbox'],
                    'vector': d['vector'],
                    'score': d['score'],
                    'group_id': d['group_id'],
                    'review': 0,
                }
                canon.append(canon_item)
                self.backend.accounts[self.user_id]['items'].append(canon_item)

            return {'detections': canon}

        raise ValueError(f'Unexpected endpoint: {endpoint}')


class TestFacesShared(unittest.TestCase):
    def setUp(self):
        im = Image.new('RGB', (120, 120), color='blue')
        buf = io.BytesIO()
        im.save(buf, format='JPEG')
        self.raw_image = buf.getvalue()

    def _setup_models(self, directory: Path):
        models_dir = directory / 'faces-local' / 'models'
        models_dir.mkdir(parents=True, exist_ok=True)
        (models_dir / 'face_detection_yunet_2023mar.onnx').touch()
        (models_dir / 'face_recognition_sface_2021dec.onnx').touch()

    def test_multi_account_single_inference_and_download(self):
        """Account 1 infers/downloads; Account 2 hits shared cache with 0 downloads and 0 inferences."""
        backend = FakeSharedBackend()
        api1 = backend.get_api('user-alpha')
        api2 = backend.get_api('user-beta')

        shared_item = SharedMockItem(101, 'etag-shared-101', path='/photos/portrait.jpg')

        with tempfile.TemporaryDirectory() as temp:
            d = Path(temp)
            self._setup_models(d)

            config = {
                'faces': {'enabled': True, 'cosine_threshold': 0.55},
                '_model_directory': str(d),
                '_inference_directory': str(d),
            }

            source1 = SharedMockSource([shared_item], self.raw_image)
            source2 = SharedMockSource([shared_item], self.raw_image)

            fake_vector = make_unit_vector(128, 0)
            mock_detector1 = Mock()
            mock_detector1.process_image.return_value = [
                {'bbox': [0.1, 0.1, 0.3, 0.3], 'vector': fake_vector, 'score': 0.95}
            ]

            events1 = []
            dir1 = d / 'user1'
            dir1.mkdir()

            with patch.object(faces, 'cv2', Mock()), patch.object(faces, 'FaceDetector', return_value=mock_detector1):
                res1 = faces.run(config, source1, api1, dir1, lambda e, **f: events1.append((e, f)), lambda: False)

            self.assertEqual(res1['state'], 'finished')
            self.assertEqual(res1['stats']['done'], 1)
            self.assertEqual(len(source1.read_calls), 1)
            mock_detector1.process_image.assert_called_once()

            event_names1 = [e[0] for e in events1]
            self.assertIn('faces_cache_miss', event_names1)
            self.assertNotIn('faces_cache_hit', event_names1)

            # Account 2 runs on the exact same file
            mock_detector2 = Mock()
            mock_detector2.process_image.side_effect = AssertionError('Cache hit must not call process_image')

            events2 = []
            dir2 = d / 'user2'
            dir2.mkdir()

            with patch.object(faces, 'cv2', Mock()), patch.object(faces, 'FaceDetector', return_value=mock_detector2):
                res2 = faces.run(config, source2, api2, dir2, lambda e, **f: events2.append((e, f)), lambda: False)

            self.assertEqual(res2['state'], 'finished')
            self.assertEqual(res2['stats']['done'], 1)
            # Account 2 made ZERO downloads!
            self.assertEqual(len(source2.read_calls), 0)
            # Account 2 made ZERO inferences!
            mock_detector2.process_image.assert_not_called()

            event_names2 = [e[0] for e in events2]
            self.assertIn('faces_cache_hit', event_names2)
            self.assertNotIn('faces_cache_miss', event_names2)

            # Ensure observe events contain file_id and path, but NO vectors
            hit_events = [f for e, f in events2 if e == 'faces_cache_hit']
            self.assertEqual(len(hit_events), 1)
            self.assertEqual(hit_events[0]['file_id'], 101)
            self.assertEqual(hit_events[0]['path'], '/photos/portrait.jpg')
            self.assertNotIn('vector', hit_events[0])
            self.assertNotIn('detections', hit_events[0])

            # Account 2 published via publish-faces
            self.assertEqual(len(backend.accounts['user-beta']['published']), 1)
            pub2 = backend.accounts['user-beta']['published'][0]
            self.assertEqual(pub2['inference_revision'], backend.inference_revision)

    def test_independent_group_assignment_and_zero_leakage(self):
        """Account 1 and Account 2 cluster vectors independently into their own group IDs."""
        backend = FakeSharedBackend()
        api1 = backend.get_api('user-alpha')
        api2 = backend.get_api('user-beta')

        shared_item = SharedMockItem(201, 'etag-shared-201', path='/shared/face.jpg')

        with tempfile.TemporaryDirectory() as temp:
            d = Path(temp)
            self._setup_models(d)

            config = {
                'faces': {'enabled': True, 'cosine_threshold': 0.55},
                '_model_directory': str(d),
                '_inference_directory': str(d),
            }

            dir1 = d / 'u1'
            dir1.mkdir()
            dir2 = d / 'u2'
            dir2.mkdir()

            # Pre-seed Account 2 with an existing group "group-existing-beta" having the same face vector
            store2 = faces.FaceStore(dir2 / 'faces.sqlite3')
            store2.set_meta('epoch', backend.epoch)
            store2.set_meta('generation', str(backend.generation))
            store2.set_meta('rules_revision', str(backend.rules_rev))
            store2.set_meta('model_space', backend.model_space)

            fake_vector = make_unit_vector(128, 5)
            # Add existing item to Account 2
            backend.accounts['user-beta']['items'].append({
                'id': 'det-existing-b',
                'file_id': 999,
                'etag': 'etag-999',
                'bbox': [0.2, 0.2, 0.3, 0.3],
                'vector': fake_vector,
                'score': 0.96,
                'group_id': 'group-existing-beta',
                'review': 0,
            })
            store2.close()

            source1 = SharedMockSource([shared_item], self.raw_image)
            source2 = SharedMockSource([shared_item], self.raw_image)

            mock_det = Mock()
            mock_det.process_image.return_value = [
                {'bbox': [0.15, 0.15, 0.25, 0.25], 'vector': fake_vector, 'score': 0.94}
            ]

            with patch.object(faces, 'cv2', Mock()), patch.object(faces, 'FaceDetector', return_value=mock_det):
                faces.run(config, source1, api1, dir1, lambda *a, **k: None, lambda: False)

            # Raw cache has NO group_id or id
            raw_cached = backend.shared_cache[(201, 'etag-shared-201')]['detections'][0]
            self.assertEqual(set(raw_cached.keys()), {'bbox', 'vector', 'score'})
            self.assertNotIn('group_id', raw_cached)
            self.assertNotIn('id', raw_cached)

            group_acc1 = backend.accounts['user-alpha']['published'][0]['detections'][0]['group_id']

            with patch.object(faces, 'cv2', Mock()), patch.object(faces, 'FaceDetector', return_value=mock_det):
                faces.run(config, source2, api2, dir2, lambda *a, **k: None, lambda: False)

            group_acc2 = backend.accounts['user-beta']['published'][0]['detections'][0]['group_id']

            # Account 2 mapped to its own existing group!
            self.assertEqual(group_acc2, 'group-existing-beta')
            # Account 1 created a brand new UUID group!
            self.assertNotEqual(group_acc1, group_acc2)

    def test_cache_empty_zero_face_bypass(self):
        """0-face cache hit [] bypasses both download and inference."""
        backend = FakeSharedBackend()
        api1 = backend.get_api('u1')
        api2 = backend.get_api('u2')

        empty_item = SharedMockItem(301, 'etag-301', path='/photos/landscape.jpg')

        with tempfile.TemporaryDirectory() as temp:
            d = Path(temp)
            self._setup_models(d)

            config = {
                'faces': {'enabled': True},
                '_model_directory': str(d),
                '_inference_directory': str(d),
            }

            source1 = SharedMockSource([empty_item], self.raw_image)
            source2 = SharedMockSource([empty_item], self.raw_image)

            mock_det1 = Mock()
            mock_det1.process_image.return_value = []

            dir1 = d / 'u1'
            dir1.mkdir()
            dir2 = d / 'u2'
            dir2.mkdir()

            with patch.object(faces, 'cv2', Mock()), patch.object(faces, 'FaceDetector', return_value=mock_det1):
                res1 = faces.run(config, source1, api1, dir1, lambda *a, **k: None, lambda: False)

            self.assertEqual(res1['stats']['done'], 1)
            mock_det1.process_image.assert_called_once()
            self.assertEqual(len(source1.read_calls), 1)

            # Shared cache now holds []
            self.assertEqual(backend.shared_cache[(301, 'etag-301')]['detections'], [])

            mock_det2 = Mock()
            mock_det2.process_image.side_effect = AssertionError('Must not infer on zero-face cache hit')

            events2 = []
            with patch.object(faces, 'cv2', Mock()), patch.object(faces, 'FaceDetector', return_value=mock_det2):
                res2 = faces.run(config, source2, api2, dir2, lambda e, **f: events2.append(e), lambda: False)

            self.assertEqual(res2['stats']['done'], 1)
            self.assertEqual(len(source2.read_calls), 0)
            mock_det2.process_image.assert_not_called()
            self.assertIn('faces_cache_hit', events2)

            # Published with 0 detections
            pub2 = backend.accounts['u2']['published'][0]
            self.assertEqual(pub2['detections'], [])
            self.assertEqual(pub2['inference_revision'], backend.inference_revision)

    def test_cache_disabled_response_treats_as_miss_and_carries_revision(self):
        """When sharing is disabled, worker performs fresh download+inference and carries revision."""
        backend = FakeSharedBackend()
        backend.global_share_enabled = False
        api = backend.get_api('u1')

        item = SharedMockItem(401, 'etag-401')

        with tempfile.TemporaryDirectory() as temp:
            d = Path(temp)
            self._setup_models(d)

            config = {
                'faces': {'enabled': True},
                '_model_directory': str(d),
                '_inference_directory': str(d),
            }

            source = SharedMockSource([item], self.raw_image)
            mock_det = Mock()
            mock_det.process_image.return_value = []
            events = []

            with patch.object(faces, 'cv2', Mock()), patch.object(faces, 'FaceDetector', return_value=mock_det):
                res = faces.run(config, source, api, d, lambda e, **f: events.append(e), lambda: False)

            self.assertEqual(res['stats']['done'], 1)
            self.assertEqual(len(source.read_calls), 1)
            mock_det.process_image.assert_called_once()
            self.assertIn('faces_cache_miss', events)

    def test_denied_lookup_never_infers_or_publishes(self):
        """When face-artifacts returns 403 or error, worker never downloads, infers or publishes."""
        backend = FakeSharedBackend()
        api = backend.get_api('u1')

        item = SharedMockItem(501, 'etag-501', path='/denied/secret.jpg')
        api.denied_files.add((501, 'etag-501'))

        with tempfile.TemporaryDirectory() as temp:
            d = Path(temp)
            self._setup_models(d)

            config = {
                'faces': {'enabled': True},
                '_model_directory': str(d),
                '_inference_directory': str(d),
            }

            source = SharedMockSource([item], self.raw_image)
            mock_det = Mock()
            mock_det.process_image.side_effect = AssertionError('Must not infer when denied')
            events = []

            with patch.object(faces, 'cv2', Mock()), patch.object(faces, 'FaceDetector', return_value=mock_det):
                res = faces.run(config, source, api, d, lambda e, **f: events.append((e, f)), lambda: False)

            self.assertEqual(res['stats']['error'], 1)
            self.assertEqual(res['stats']['done'], 0)
            self.assertEqual(len(source.read_calls), 0)
            mock_det.process_image.assert_not_called()
            self.assertEqual(len(backend.accounts['u1']['published']), 0)

            # faces_error observed
            err_events = [f for e, f in events if e == 'faces_error']
            self.assertEqual(len(err_events), 1)
            self.assertEqual(err_events[0]['file_id'], 501)

            # Store records file as error
            store = faces.FaceStore(d / 'faces.sqlite3')
            self.assertTrue(store.is_file_error(501, 'etag-501'))
            store.close()

    def test_invalid_or_missing_inference_revision_refused(self):
        """Worker refuses to publish with invalid/missing 64-hex inference_revision signature."""
        invalid_revisions = [
            None,
            '',
            'not-hex',
            'a' * 63,          # 63 chars (too short)
            'a' * 65,          # 65 chars (too long)
            ('A' * 64),        # Uppercase hex rejected (strictly lowercase)
            12345,
            True,
        ]

        for bad_rev in invalid_revisions:
            with self.subTest(bad_rev=bad_rev):
                backend = FakeSharedBackend()
                api = backend.get_api('u1')
                api.artifact_override = {
                    'detections': None,
                    'inference_revision': bad_rev,
                    'disabled': False,
                }

                item = SharedMockItem(601, 'etag-601')

                with tempfile.TemporaryDirectory() as temp:
                    d = Path(temp)
                    self._setup_models(d)

                    config = {
                        'faces': {'enabled': True},
                        '_model_directory': str(d),
                        '_inference_directory': str(d),
                    }

                    source = SharedMockSource([item], self.raw_image)
                    mock_det = Mock()

                    with patch.object(faces, 'cv2', Mock()), patch.object(faces, 'FaceDetector', return_value=mock_det):
                        res = faces.run(config, source, api, d, lambda *a, **k: None, lambda: False)

                    self.assertEqual(res['stats']['error'], 1)
                    self.assertEqual(res['stats']['done'], 0)
                    self.assertEqual(len(backend.accounts['u1']['published']), 0)

    def test_validator_rejects_malformed_payloads(self):
        """Strict validation of cached raw detections."""
        valid_vec = make_unit_vector(128, 0)

        # 1. Non-list payload
        with self.assertRaises(faces.PipelineError):
            faces.validate_raw_detections('not-a-list')
        with self.assertRaises(faces.PipelineError):
            faces.validate_raw_detections(True)
        with self.assertRaises(faces.PipelineError):
            faces.validate_raw_detections({'detections': []})

        # 2. Exceeds 100 faces
        huge_list = [{'bbox': [0.1, 0.1, 0.2, 0.2], 'vector': valid_vec, 'score': 0.9}] * 101
        with self.assertRaises(faces.PipelineError):
            faces.validate_raw_detections(huge_list)

        # 3. Non-dict items or bool items
        with self.assertRaises(faces.PipelineError):
            faces.validate_raw_detections(['not-a-dict'])
        with self.assertRaises(faces.PipelineError):
            faces.validate_raw_detections([True])

        # 4. Prohibited keys (leaked IDs) or missing keys
        with self.assertRaises(faces.PipelineError):
            faces.validate_raw_detections([{'bbox': [0.1, 0.1, 0.2, 0.2], 'vector': valid_vec, 'score': 0.9, 'group_id': 'leak'}])
        with self.assertRaises(faces.PipelineError):
            faces.validate_raw_detections([{'bbox': [0.1, 0.1, 0.2, 0.2], 'vector': valid_vec, 'score': 0.9, 'id': 'det-leak'}])
        with self.assertRaises(faces.PipelineError):
            faces.validate_raw_detections([{'bbox': [0.1, 0.1, 0.2, 0.2], 'vector': valid_vec}])  # missing score

        # 5. Invalid bbox: shape, booleans, non-finite, zero-size, out-of-bounds
        with self.assertRaises(faces.PipelineError):
            faces.validate_raw_detections([{'bbox': [0.1, 0.1, 0.2], 'vector': valid_vec, 'score': 0.9}])  # len 3
        with self.assertRaises(faces.PipelineError):
            faces.validate_raw_detections([{'bbox': [True, 0.1, 0.2, 0.2], 'vector': valid_vec, 'score': 0.9}])  # bool in bbox
        with self.assertRaises(faces.PipelineError):
            faces.validate_raw_detections([{'bbox': [float('nan'), 0.1, 0.2, 0.2], 'vector': valid_vec, 'score': 0.9}])  # NaN
        with self.assertRaises(faces.PipelineError):
            faces.validate_raw_detections([{'bbox': [0.1, 0.1, 0.0, 0.2], 'vector': valid_vec, 'score': 0.9}])  # zero width (w <= 1e-5)
        with self.assertRaises(faces.PipelineError):
            faces.validate_raw_detections([{'bbox': [0.1, 0.1, 0.2, 1e-6], 'vector': valid_vec, 'score': 0.9}])  # zero height
        with self.assertRaises(faces.PipelineError):
            faces.validate_raw_detections([{'bbox': [0.8, 0.1, 0.3, 0.2], 'vector': valid_vec, 'score': 0.9}])  # x+w = 1.1 > 1.0001
        with self.assertRaises(faces.PipelineError):
            faces.validate_raw_detections([{'bbox': [-0.01, 0.1, 0.2, 0.2], 'vector': valid_vec, 'score': 0.9}])  # negative x

        # 6. Invalid score: booleans, out of range, non-finite
        with self.assertRaises(faces.PipelineError):
            faces.validate_raw_detections([{'bbox': [0.1, 0.1, 0.2, 0.2], 'vector': valid_vec, 'score': True}])
        with self.assertRaises(faces.PipelineError):
            faces.validate_raw_detections([{'bbox': [0.1, 0.1, 0.2, 0.2], 'vector': valid_vec, 'score': -0.1}])
        with self.assertRaises(faces.PipelineError):
            faces.validate_raw_detections([{'bbox': [0.1, 0.1, 0.2, 0.2], 'vector': valid_vec, 'score': 1.05}])
        with self.assertRaises(faces.PipelineError):
            faces.validate_raw_detections([{'bbox': [0.1, 0.1, 0.2, 0.2], 'vector': valid_vec, 'score': float('inf')}])

        # 7. Invalid vector: booleans, wrong dim, non-finite, zero vector, norm overflow
        with self.assertRaises(faces.PipelineError):
            faces.validate_raw_detections([{'bbox': [0.1, 0.1, 0.2, 0.2], 'vector': [True] * 128, 'score': 0.9}])
        with self.assertRaises(faces.PipelineError):
            faces.validate_raw_detections([{'bbox': [0.1, 0.1, 0.2, 0.2], 'vector': valid_vec[:127], 'score': 0.9}])
        with self.assertRaises(faces.PipelineError):
            bad_vec = list(valid_vec)
            bad_vec[0] = float('nan')
            faces.validate_raw_detections([{'bbox': [0.1, 0.1, 0.2, 0.2], 'vector': bad_vec, 'score': 0.9}])
        with self.assertRaises(faces.PipelineError):
            # Zero vector: norm = 0.0 <= 1e-4
            faces.validate_raw_detections([{'bbox': [0.1, 0.1, 0.2, 0.2], 'vector': [0.0] * 128, 'score': 0.9}])
        with self.assertRaises(faces.PipelineError):
            # Norm overflow: norm > 10.0
            faces.validate_raw_detections([{'bbox': [0.1, 0.1, 0.2, 0.2], 'vector': [10.0] * 128, 'score': 0.9}])

        # Valid payload returns clean sanitized list
        valid_input = [{'bbox': [0.1, 0.1, 0.2, 0.2], 'vector': valid_vec, 'score': 0.95}]
        cleaned = faces.validate_raw_detections(valid_input)
        self.assertEqual(len(cleaned), 1)
        self.assertEqual(cleaned[0]['score'], 0.95)
        self.assertEqual(cleaned[0]['bbox'], [0.1, 0.1, 0.2, 0.2])

    def test_rules_conflict_retries_without_re_inference(self):
        """HTTP 409 rules_revision conflict retries without repeat inference or download."""
        backend = FakeSharedBackend()
        api = backend.get_api('u1')

        item = SharedMockItem(701, 'etag-701')

        with tempfile.TemporaryDirectory() as temp:
            d = Path(temp)
            self._setup_models(d)

            config = {
                'faces': {'enabled': True},
                '_model_directory': str(d),
                '_inference_directory': str(d),
            }

            source = SharedMockSource([item], self.raw_image)

            fake_vec = make_unit_vector(128, 1)
            mock_det = Mock()
            mock_det.process_image.return_value = [
                {'bbox': [0.1, 0.1, 0.2, 0.2], 'vector': fake_vec, 'score': 0.95}
            ]

            # First publish call triggers 409 with rules bump
            api.publish_error = urllib.error.HTTPError(
                url='http://127.0.0.1/publish-faces',
                code=409,
                msg='Conflict',
                hdrs={},
                fp=io.BytesIO(b'{"error": "Rules revision conflict"}'),
            )

            def bump_rules():
                backend.rules_rev = 2

            api.on_publish_error = bump_rules

            with patch.object(faces, 'cv2', Mock()), patch.object(faces, 'FaceDetector', return_value=mock_det):
                res = faces.run(config, source, api, d, lambda *a, **k: None, lambda: False)

            self.assertEqual(res['state'], 'finished')
            self.assertEqual(res['stats']['done'], 1)
            # Detector called only ONCE! No repeat inference
            mock_det.process_image.assert_called_once()
            # Media read only ONCE!
            self.assertEqual(len(source.read_calls), 1)

            published = backend.accounts['u1']['published']
            self.assertEqual(len(published), 1)
            self.assertEqual(published[0]['rules_revision'], 2)

    def test_stale_generation_aborts_and_cannot_persist(self):
        """HTTP 409 generation mismatch clears cache and pauses without persisting old inference."""
        backend = FakeSharedBackend()
        api = backend.get_api('u1')

        item = SharedMockItem(801, 'etag-801')

        with tempfile.TemporaryDirectory() as temp:
            d = Path(temp)
            self._setup_models(d)

            # Pre-seed local store with old item
            store = faces.FaceStore(d / 'faces.sqlite3')
            store.set_meta('epoch', backend.epoch)
            store.set_meta('generation', str(backend.generation))
            store.record_file(999, 'etag-old', 'done', face_count=1)
            store.close()

            config = {
                'faces': {'enabled': True},
                '_model_directory': str(d),
                '_inference_directory': str(d),
            }

            source = SharedMockSource([item], self.raw_image)
            fake_vec = make_unit_vector(128, 2)
            mock_det = Mock()
            mock_det.process_image.return_value = [
                {'bbox': [0.1, 0.1, 0.2, 0.2], 'vector': fake_vec, 'score': 0.95}
            ]

            api.publish_error = urllib.error.HTTPError(
                url='http://127.0.0.1/publish-faces',
                code=409,
                msg='Conflict',
                hdrs={},
                fp=io.BytesIO(b'{"error": "Generation mismatch"}'),
            )

            def bump_generation():
                backend.generation = 2

            api.on_publish_error = bump_generation

            with patch.object(faces, 'cv2', Mock()), patch.object(faces, 'FaceDetector', return_value=mock_det):
                res = faces.run(config, source, api, d, lambda *a, **k: None, lambda: False)

            self.assertEqual(res['state'], 'paused')
            self.assertEqual(len(backend.accounts['u1']['published']), 0)

            # Store was completely wiped on reset
            store = faces.FaceStore(d / 'faces.sqlite3')
            self.assertFalse(store.is_file_done(999, 'etag-old'))
            store.close()

    def test_inference_revision_change_during_publish_pauses(self):
        """HTTP 409 inference_revision change pauses cycle instead of publishing old raw with new revision."""
        backend = FakeSharedBackend()
        api = backend.get_api('u1')

        item = SharedMockItem(901, 'etag-901')

        with tempfile.TemporaryDirectory() as temp:
            d = Path(temp)
            self._setup_models(d)

            config = {
                'faces': {'enabled': True},
                '_model_directory': str(d),
                '_inference_directory': str(d),
            }

            source = SharedMockSource([item], self.raw_image)
            fake_vec = make_unit_vector(128, 3)
            mock_det = Mock()
            mock_det.process_image.return_value = [
                {'bbox': [0.1, 0.1, 0.2, 0.2], 'vector': fake_vec, 'score': 0.95}
            ]

            api.publish_error = urllib.error.HTTPError(
                url='http://127.0.0.1/publish-faces',
                code=409,
                msg='Conflict',
                hdrs={},
                fp=io.BytesIO(b'{"error": "Inference revision changed"}'),
            )

            def change_inference_revision():
                backend.inference_revision = 'f' * 64

            api.on_publish_error = change_inference_revision

            with patch.object(faces, 'cv2', Mock()), patch.object(faces, 'FaceDetector', return_value=mock_det):
                res = faces.run(config, source, api, d, lambda *a, **k: None, lambda: False)

            self.assertEqual(res['state'], 'paused')
            self.assertEqual(len(backend.accounts['u1']['published']), 0)

    def test_cache_miss_carries_revision(self):
        """Cache miss carries inference_revision from face-artifacts response into publish-faces."""
        backend = FakeSharedBackend()
        api = backend.get_api('u1')
        custom_rev = '1234567890abcdef' * 4
        backend.inference_revision = custom_rev

        item = SharedMockItem(1001, 'etag-1001')

        with tempfile.TemporaryDirectory() as temp:
            d = Path(temp)
            self._setup_models(d)

            config = {
                'faces': {'enabled': True},
                '_model_directory': str(d),
                '_inference_directory': str(d),
            }

            source = SharedMockSource([item], self.raw_image)
            fake_vec = make_unit_vector(128, 4)
            mock_det = Mock()
            mock_det.process_image.return_value = [
                {'bbox': [0.1, 0.1, 0.2, 0.2], 'vector': fake_vec, 'score': 0.95}
            ]

            with patch.object(faces, 'cv2', Mock()), patch.object(faces, 'FaceDetector', return_value=mock_det):
                res = faces.run(config, source, api, d, lambda *a, **k: None, lambda: False)

            self.assertEqual(res['state'], 'finished')
            self.assertEqual(res['stats']['done'], 1)
            published = backend.accounts['u1']['published']
            self.assertEqual(len(published), 1)
            self.assertEqual(published[0]['inference_revision'], custom_rev)

    def test_malformed_cached_payload_marks_file_error_and_rolls_back(self):
        """Malformed cache hit payload is refused: records error, rolls back clusters, never publishes."""
        malformed_detections = [
            # Contains forbidden group_id
            [{'bbox': [0.1, 0.1, 0.2, 0.2], 'vector': make_unit_vector(128, 0), 'score': 0.9, 'group_id': 'leaked-id'}],
            # Non-finite vector
            [{'bbox': [0.1, 0.1, 0.2, 0.2], 'vector': [float('nan')] + [0.0] * 127, 'score': 0.9}],
            # Zero-size box
            [{'bbox': [0.1, 0.1, 1e-6, 0.2], 'vector': make_unit_vector(128, 0), 'score': 0.9}],
            # Out of bounds
            [{'bbox': [0.9, 0.1, 0.2, 0.2], 'vector': make_unit_vector(128, 0), 'score': 0.9}],
            # Zero vector norm
            [{'bbox': [0.1, 0.1, 0.2, 0.2], 'vector': [0.0] * 128, 'score': 0.9}],
        ]

        for i, bad_payload in enumerate(malformed_detections):
            with self.subTest(idx=i):
                backend = FakeSharedBackend()
                api = backend.get_api('u1')
                api.artifact_override = {
                    'detections': bad_payload,
                    'inference_revision': 'c' * 64,
                    'disabled': False,
                }

                item = SharedMockItem(2000 + i, f'etag-{i}')

                with tempfile.TemporaryDirectory() as temp:
                    d = Path(temp)
                    self._setup_models(d)

                    config = {
                        'faces': {'enabled': True},
                        '_model_directory': str(d),
                        '_inference_directory': str(d),
                    }

                    source = SharedMockSource([item], self.raw_image)
                    mock_det = Mock()
                    mock_det.process_image.side_effect = AssertionError('Must not call process_image on malformed cache hit')

                    events = []
                    with patch.object(faces, 'cv2', Mock()), patch.object(faces, 'FaceDetector', return_value=mock_det):
                        res = faces.run(config, source, api, d, lambda e, **f: events.append((e, f)), lambda: False)

                    self.assertEqual(res['stats']['error'], 1)
                    self.assertEqual(res['stats']['done'], 0)
                    mock_det.process_image.assert_not_called()
                    self.assertEqual(len(source.read_calls), 0)
                    self.assertEqual(len(backend.accounts['u1']['published']), 0)

                    store = faces.FaceStore(d / 'faces.sqlite3')
                    self.assertTrue(store.is_file_error(2000 + i, f'etag-{i}'))
                    active = store.load_active_vectors()
                    self.assertEqual(len(active), 0)
                    store.close()


if __name__ == '__main__':
    unittest.main()
