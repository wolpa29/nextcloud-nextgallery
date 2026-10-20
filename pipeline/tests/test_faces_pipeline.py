"""Unit tests for media_ai_faces pipeline execution."""
import io
from pathlib import Path
import tempfile
import unittest
import urllib.error
from unittest.mock import Mock, patch

from PIL import Image

import media_ai_faces as faces


class MockItem:
    def __init__(self, file_id: int, etag: str, path: str = '/photos/img.jpg', mime: str = 'image/jpeg', analyzed_etag: str = ''):
        self.locator = str(file_id)
        self.etag = etag
        self.path = path
        self.mime = mime
        self.analyzed_etag = analyzed_etag


class MockSource:
    def __init__(self, items: list[MockItem], image_bytes: bytes, unchanged: bool = True):
        self.items = items
        self.raw = image_bytes
        self._unchanged = unchanged

    def inventory(self):
        return self.items

    def read(self, item, max_bytes):
        return self.raw

    def unchanged(self, item):
        return self._unchanged


class MockAPI:
    def __init__(self, epoch: str = 'epoch-test-1', generation: int = 1, rules_rev: int = 1):
        self.epoch = epoch
        self.generation = generation
        self.rules_rev = rules_rev
        self.published = []
        self.publish_error = None
        self.on_publish_error = None
        self.canonical_group_override = None
        self.face_artifacts_response = None
        self.face_artifacts_error = None

    def call(self, endpoint: str, body: dict = None):
        if endpoint.startswith('faces-state'):
            return {
                'epoch': self.epoch,
                'generation': self.generation,
                'rules_revision': self.rules_rev,
                'model_space': faces.MODEL_SPACE,
                'items': [],
                'next': None,
                'more': False,
            }
        if endpoint == 'face-artifacts':
            if self.face_artifacts_error:
                err = self.face_artifacts_error
                self.face_artifacts_error = None
                raise err
            if self.face_artifacts_response is not None:
                if callable(self.face_artifacts_response):
                    return self.face_artifacts_response(body)
                return self.face_artifacts_response
            return {
                'detections': None,
                'inference_revision': 'a' * 64,
                'disabled': False,
            }
        if endpoint == 'publish-faces':
            if self.publish_error:
                err = self.publish_error
                self.publish_error = None  # Clear for subsequent retry if any
                if self.on_publish_error:
                    self.on_publish_error()
                raise err
            self.published.append(body)
            canon = []
            for i, d in enumerate(body.get('detections', [])):
                gid = self.canonical_group_override or d['group_id']
                canon.append({
                    'id': f"canon-det-{body['file_id']}-{i}",
                    'file_id': body['file_id'],
                    'etag': body['etag'],
                    'bbox': d['bbox'],
                    'vector': d['vector'],
                    'score': d['score'],
                    'group_id': gid,
                    'review': 0,
                })
            return {'detections': canon}
        raise ValueError(f'Unexpected endpoint: {endpoint}')


class FacesPipelineTests(unittest.TestCase):
    def setUp(self):
        im = Image.new('RGB', (100, 100), color='green')
        buf = io.BytesIO()
        im.save(buf, format='JPEG')
        self.raw_image = buf.getvalue()

    def test_run_disabled_by_default(self):
        with tempfile.TemporaryDirectory() as temp:
            config = {}  # No explicit faces.enabled -> default false
            res = faces.run(config, MockSource([], self.raw_image), MockAPI(), temp, lambda *a, **kw: None, lambda: False)
            self.assertEqual(res['state'], 'idle')
            self.assertEqual(res['stats']['done'], 0)

    def test_run_missing_models_raises_pipeline_error(self):
        with tempfile.TemporaryDirectory() as temp:
            d = Path(temp)
            config = {'faces': {'enabled': True}, '_model_directory': str(d)}
            with patch.object(faces, 'cv2', Mock()), patch.object(faces, 'np', Mock()):
                with self.assertRaises(faces.PipelineError):
                    faces.run(config, MockSource([], self.raw_image), MockAPI(), d, lambda *a, **kw: None, lambda: False)

    def test_run_successful_cycle_with_events(self):
        with tempfile.TemporaryDirectory() as temp:
            d = Path(temp)
            models_dir = d / 'faces-local' / 'models'
            models_dir.mkdir(parents=True)
            (models_dir / 'face_detection_yunet_2023mar.onnx').touch()
            (models_dir / 'face_recognition_sface_2021dec.onnx').touch()

            config = {
                'faces': {'enabled': True, 'cosine_threshold': 0.55},
                '_model_directory': str(d),
                '_inference_directory': str(d),
            }

            items = [
                MockItem(10, 'etag-10', path='/photos/a.jpg'),
                MockItem(20, 'etag-20', path='/photos/b.jpg'),
            ]
            source = MockSource(items, self.raw_image)
            api = MockAPI()
            events = []

            fake_vector_1 = [0.0] * 128
            fake_vector_1[0] = 1.0

            fake_vector_2 = [0.0] * 128
            fake_vector_2[1] = 1.0

            mock_detector = Mock()
            mock_detector.process_image.side_effect = [
                [{'bbox': [0.1, 0.1, 0.2, 0.2], 'vector': fake_vector_1, 'score': 0.95}],
                [{'bbox': [0.3, 0.3, 0.2, 0.2], 'vector': fake_vector_2, 'score': 0.92}],
            ]

            with patch.object(faces, 'cv2', Mock()), patch.object(faces, 'FaceDetector', return_value=mock_detector):
                result = faces.run(config, source, api, d, lambda event, **fields: events.append((event, fields)), lambda: False)

            self.assertEqual(result['state'], 'finished')
            self.assertEqual(result['stats']['done'], 2)
            self.assertEqual(len(api.published), 2)

            # Verify file_start and faces_processed events; file_finished is omitted to prevent double mapping
            event_names = [e[0] for e in events]
            self.assertIn('file_start', event_names)
            self.assertIn('faces_processed', event_names)
            self.assertNotIn('file_finished', event_names)

            file_start_events = [e[1] for e in events if e[0] == 'file_start']
            self.assertEqual(file_start_events[0]['file_id'], 10)
            self.assertEqual(file_start_events[0]['path'], '/photos/a.jpg')

            proc_events = [e[1] for e in events if e[0] == 'faces_processed']
            self.assertEqual(proc_events[0]['file_id'], 10)
            self.assertEqual(proc_events[0]['path'], '/photos/a.jpg')
            self.assertEqual(proc_events[0]['faces_count'], 1)

            # Verify local sqlite store
            store = faces.FaceStore(d / 'faces.sqlite3')
            self.assertTrue(store.is_file_done(10, 'etag-10'))
            self.assertTrue(store.is_file_done(20, 'etag-20'))
            store.close()

    def test_analyzed_etag_skips_reprocessing(self):
        with tempfile.TemporaryDirectory() as temp:
            d = Path(temp)
            models_dir = d / 'faces-local' / 'models'
            models_dir.mkdir(parents=True)
            (models_dir / 'face_detection_yunet_2023mar.onnx').touch()
            (models_dir / 'face_recognition_sface_2021dec.onnx').touch()

            config = {'faces': {'enabled': True}, '_model_directory': str(d), '_inference_directory': str(d)}

            # Item already marked as analyzed in backend (analyzed_etag == etag)
            items = [MockItem(10, 'etag-10', analyzed_etag='etag-10')]
            source = MockSource(items, self.raw_image)
            api = MockAPI()
            mock_detector = Mock()

            with patch.object(faces, 'cv2', Mock()), patch.object(faces, 'FaceDetector', return_value=mock_detector):
                result = faces.run(config, source, api, d, lambda *a, **kw: None, lambda: False)

            self.assertEqual(result['state'], 'finished')
            self.assertEqual(result['stats']['done'], 1)
            self.assertEqual(len(api.published), 0)
            mock_detector.process_image.assert_not_called()

    def test_store_done_cannot_override_empty_or_stale_analyzed_etag(self):
        with tempfile.TemporaryDirectory() as temp:
            d = Path(temp)
            models_dir = d / 'faces-local' / 'models'
            models_dir.mkdir(parents=True)
            (models_dir / 'face_detection_yunet_2023mar.onnx').touch()
            (models_dir / 'face_recognition_sface_2021dec.onnx').touch()

            config = {'faces': {'enabled': True}, '_model_directory': str(d), '_inference_directory': str(d)}

            # Local store claims done, but backend analyzed_etag is empty/stale
            store = faces.FaceStore(d / 'faces.sqlite3')
            store.set_meta('epoch', 'epoch-test-1')
            store.set_meta('generation', '1')
            store.set_meta('rules_revision', '1')
            store.set_meta('model_space', faces.MODEL_SPACE)
            store.record_file(10, 'etag-10', 'done', face_count=1)
            store.close()

            items = [MockItem(10, 'etag-10', analyzed_etag='')]
            source = MockSource(items, self.raw_image)
            api = MockAPI()
            mock_detector = Mock()
            mock_detector.process_image.return_value = []

            with patch.object(faces, 'cv2', Mock()), patch.object(faces, 'FaceDetector', return_value=mock_detector):
                result = faces.run(config, source, api, d, lambda *a, **kw: None, lambda: False)

            self.assertEqual(result['state'], 'finished')
            self.assertEqual(result['stats']['done'], 1)
            # Must NOT skip: detector.process_image was called because backend is authoritative
            mock_detector.process_image.assert_called_once()

    def test_persistent_errors_skipped_unless_retry_requested(self):
        with tempfile.TemporaryDirectory() as temp:
            d = Path(temp)
            models_dir = d / 'faces-local' / 'models'
            models_dir.mkdir(parents=True)
            (models_dir / 'face_detection_yunet_2023mar.onnx').touch()
            (models_dir / 'face_recognition_sface_2021dec.onnx').touch()

            config = {'faces': {'enabled': True}, '_model_directory': str(d), '_inference_directory': str(d)}

            # Record prior persistent error in local store with matching epoch
            store = faces.FaceStore(d / 'faces.sqlite3')
            store.set_meta('epoch', 'epoch-test-1')
            store.set_meta('generation', '1')
            store.set_meta('model_space', faces.MODEL_SPACE)
            store.record_file(10, 'etag-10', 'error', error='corrupt image')
            store.close()

            items = [MockItem(10, 'etag-10')]
            source = MockSource(items, self.raw_image)
            api = MockAPI()
            mock_detector = Mock()

            with patch.object(faces, 'cv2', Mock()), patch.object(faces, 'FaceDetector', return_value=mock_detector):
                # Without retry_errors: skipped
                res = faces.run(config, source, api, d, lambda *a, **kw: None, lambda: False, retry_errors=False)
                self.assertEqual(res['stats']['error'], 1)
                self.assertEqual(len(api.published), 0)
                mock_detector.process_image.assert_not_called()

                # With retry_errors=True: re-attempted
                mock_detector.process_image.return_value = []
                res_retry = faces.run(config, source, api, d, lambda *a, **kw: None, lambda: False, retry_errors=True)
                self.assertEqual(res_retry['stats']['done'], 1)
                mock_detector.process_image.assert_called_once()

    def test_http_409_numeric_rules_revision_retries_without_reinference(self):
        with tempfile.TemporaryDirectory() as temp:
            d = Path(temp)
            models_dir = d / 'faces-local' / 'models'
            models_dir.mkdir(parents=True)
            (models_dir / 'face_detection_yunet_2023mar.onnx').touch()
            (models_dir / 'face_recognition_sface_2021dec.onnx').touch()

            config = {'faces': {'enabled': True}, '_model_directory': str(d), '_inference_directory': str(d)}
            items = [MockItem(10, 'etag-10')]
            source = MockSource(items, self.raw_image)
            api = MockAPI(rules_rev=1)

            # First publish call raises real numeric HTTP 409 Conflict
            api.publish_error = urllib.error.HTTPError(
                url='http://127.0.0.1/publish-faces',
                code=409,
                msg='Conflict',
                hdrs={},
                fp=io.BytesIO(b'{"error": "Rules revision changed"}'),
            )
            # Backend rules revision increases to 2 when publish is attempted
            def bump_rules():
                api.rules_rev = 2
            api.on_publish_error = bump_rules

            mock_detector = Mock()
            fake_vec = [0.0] * 128
            fake_vec[0] = 1.0
            mock_detector.process_image.return_value = [{'bbox': [0.1, 0.1, 0.2, 0.2], 'vector': fake_vec, 'score': 0.95}]

            with patch.object(faces, 'cv2', Mock()), patch.object(faces, 'FaceDetector', return_value=mock_detector):
                result = faces.run(config, source, api, d, lambda *a, **kw: None, lambda: False)

            self.assertEqual(result['state'], 'finished')
            self.assertEqual(result['stats']['done'], 1)
            self.assertEqual(len(api.published), 1)  # Retried successfully
            self.assertEqual(api.published[0]['rules_revision'], 2)
            # Detection process_image called only ONCE (no re-inference)
            mock_detector.process_image.assert_called_once()

    def test_http_409_numeric_generation_reset_aborts_and_clears_cache(self):
        with tempfile.TemporaryDirectory() as temp:
            d = Path(temp)
            models_dir = d / 'faces-local' / 'models'
            models_dir.mkdir(parents=True)
            (models_dir / 'face_detection_yunet_2023mar.onnx').touch()
            (models_dir / 'face_recognition_sface_2021dec.onnx').touch()

            config = {'faces': {'enabled': True}, '_model_directory': str(d), '_inference_directory': str(d)}
            items = [MockItem(10, 'etag-10')]
            source = MockSource(items, self.raw_image)
            api = MockAPI(epoch='epoch-test-1', generation=1)

            # Pre-populate store
            store = faces.FaceStore(d / 'faces.sqlite3')
            store.set_meta('epoch', 'epoch-test-1')
            store.set_meta('generation', '1')
            store.record_file(99, 'etag-99', 'done', face_count=1)
            store.close()

            # First publish call raises numeric HTTP 409 Conflict
            api.publish_error = urllib.error.HTTPError(
                url='http://127.0.0.1/publish-faces',
                code=409,
                msg='Conflict',
                hdrs={},
                fp=io.BytesIO(b'{"error": "Generation mismatch: verworfenes Worker-Ergebnis nach Reset"}'),
            )
            # Backend generation incremented (account reset) when publish is attempted
            def bump_generation():
                api.generation = 2
            api.on_publish_error = bump_generation

            mock_detector = Mock()
            fake_vec = [0.0] * 128
            fake_vec[0] = 1.0
            mock_detector.process_image.return_value = [{'bbox': [0.1, 0.1, 0.2, 0.2], 'vector': fake_vec, 'score': 0.95}]

            with patch.object(faces, 'cv2', Mock()), patch.object(faces, 'FaceDetector', return_value=mock_detector):
                result = faces.run(config, source, api, d, lambda *a, **kw: None, lambda: False)

            # Cycle must abort and pause; never retrying old inference to new generation!
            self.assertEqual(result['state'], 'paused')
            self.assertEqual(len(api.published), 0)

            # Local store was cleared on reset
            store = faces.FaceStore(d / 'faces.sqlite3')
            self.assertFalse(store.is_file_done(99, 'etag-99'))
            store.close()

    def test_http_409_numeric_etag_mismatch_marks_error_without_retry(self):
        with tempfile.TemporaryDirectory() as temp:
            d = Path(temp)
            models_dir = d / 'faces-local' / 'models'
            models_dir.mkdir(parents=True)
            (models_dir / 'face_detection_yunet_2023mar.onnx').touch()
            (models_dir / 'face_recognition_sface_2021dec.onnx').touch()

            config = {'faces': {'enabled': True}, '_model_directory': str(d), '_inference_directory': str(d)}
            items = [MockItem(10, 'etag-10')]
            source = MockSource(items, self.raw_image)
            # Epoch and generation and rules_rev are unchanged -> pure ETag mismatch
            api = MockAPI(epoch='epoch-test-1', generation=1, rules_rev=1)
            api.publish_error = urllib.error.HTTPError(
                url='http://127.0.0.1/publish-faces',
                code=409,
                msg='Conflict',
                hdrs={},
                fp=io.BytesIO(b'{"error": "Bilddatei geaendert (ETag-Konflikt)"}'),
            )

            mock_detector = Mock()
            fake_vec = [0.0] * 128
            fake_vec[0] = 1.0
            mock_detector.process_image.return_value = [{'bbox': [0.1, 0.1, 0.2, 0.2], 'vector': fake_vec, 'score': 0.95}]

            with patch.object(faces, 'cv2', Mock()), patch.object(faces, 'FaceDetector', return_value=mock_detector):
                result = faces.run(config, source, api, d, lambda *a, **kw: None, lambda: False)

            self.assertEqual(result['state'], 'finished')
            self.assertEqual(result['stats']['error'], 1)
            self.assertEqual(len(api.published), 0)

            store = faces.FaceStore(d / 'faces.sqlite3')
            self.assertTrue(store.is_file_error(10, 'etag-10'))
            store.close()

    def test_canonical_override_applied_to_cluster_index(self):
        with tempfile.TemporaryDirectory() as temp:
            d = Path(temp)
            models_dir = d / 'faces-local' / 'models'
            models_dir.mkdir(parents=True)
            (models_dir / 'face_detection_yunet_2023mar.onnx').touch()
            (models_dir / 'face_recognition_sface_2021dec.onnx').touch()

            config = {'faces': {'enabled': True}, '_model_directory': str(d), '_inference_directory': str(d)}
            items = [MockItem(10, 'etag-10')]
            source = MockSource(items, self.raw_image)
            api = MockAPI()
            api.canonical_group_override = 'server-override-group-id'

            mock_detector = Mock()
            fake_vec = [0.0] * 128
            fake_vec[0] = 1.0
            mock_detector.process_image.return_value = [{'bbox': [0.1, 0.1, 0.2, 0.2], 'vector': fake_vec, 'score': 0.95}]

            with patch.object(faces, 'cv2', Mock()), patch.object(faces, 'FaceDetector', return_value=mock_detector):
                result = faces.run(config, source, api, d, lambda *a, **kw: None, lambda: False)

            self.assertEqual(result['state'], 'finished')
            self.assertEqual(result['stats']['done'], 1)

            store = faces.FaceStore(d / 'faces.sqlite3')
            active = store.load_active_vectors()
            self.assertEqual(len(active), 1)
            self.assertEqual(active[0][0], 'server-override-group-id')
            store.close()


if __name__ == '__main__':
    unittest.main()
