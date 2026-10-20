"""Unit tests for face quality filtering, sharpness calculation, and anchor eligibility."""
from __future__ import annotations

import io
import math
from pathlib import Path
import sqlite3
import tempfile
import unittest
from unittest.mock import Mock, patch

from PIL import Image

import media_ai_faces as faces


def make_unit_vector(dim: int = 128, index: int = 0) -> list[float]:
    v = [0.0] * dim
    v[index] = 1.0
    return v


class QualityMockItem:
    def __init__(self, file_id: int, etag: str, path: str = '/photos/p.jpg', mime: str = 'image/jpeg', analyzed_etag: str = ''):
        self.locator = str(file_id)
        self.etag = etag
        self.path = path
        self.mime = mime
        self.analyzed_etag = analyzed_etag


class QualityMockSource:
    def __init__(self, items: list[QualityMockItem], raw_bytes: bytes):
        self.items = items
        self.raw = raw_bytes
        self.read_calls: list[str] = []

    def inventory(self):
        return self.items

    def read(self, item, max_bytes):
        self.read_calls.append(item.locator)
        return self.raw

    def unchanged(self, item):
        return True


class QualityMockAPI:
    def __init__(self, epoch: str = 'epoch-q-1', generation: int = 1, rules_rev: int = 1):
        self.epoch = epoch
        self.generation = generation
        self.rules_rev = rules_rev
        self.published: list[dict] = []
        self.canonical_overrides: list[dict] | None = None
        self.artifacts_cache: dict[tuple[int, str], dict] = {}

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
            key = (body['file_id'], body['etag'])
            if key in self.artifacts_cache:
                return self.artifacts_cache[key]
            return {
                'detections': None,
                'inference_revision': '1' * 64,
                'disabled': False,
            }
        if endpoint == 'publish-faces':
            self.published.append(body)
            canon = []
            for i, d in enumerate(body.get('detections', [])):
                if self.canonical_overrides and i < len(self.canonical_overrides):
                    c = dict(self.canonical_overrides[i])
                else:
                    c = {
                        'id': f"canon-q-{body['file_id']}-{i}",
                        'file_id': body['file_id'],
                        'etag': body['etag'],
                        'bbox': d['bbox'],
                        'vector': d['vector'],
                        'score': d['score'],
                        'sharpness': d.get('sharpness'),
                        'group_id': d['group_id'],
                        'review': 0,
                        # Server marks eligible based on quality thresholds or overrides
                        'eligible': True,
                    }
                canon.append(c)
            return {'detections': canon}
        raise ValueError(f'Unexpected endpoint: {endpoint}')


class TestFaceQualityPureHelpers(unittest.TestCase):
    def test_face_sharpness_none_or_empty(self):
        self.assertEqual(faces.face_sharpness(None), 0.0)
        self.assertEqual(faces.face_sharpness('not-an-array'), 0.0)

    def test_face_sharpness_synthetic_blur(self):
        """Verify Laplacian variance on sharp vs blurred 112x112 crops."""
        if faces.cv2 is None or faces.np is None:
            self.skipTest('OpenCV/NumPy not available in this runtime')

        np = faces.np
        cv2 = faces.cv2

        # Create sharp 112x112 image with high-frequency pattern
        sharp_crop = np.zeros((112, 112, 3), dtype=np.uint8)
        for i in range(0, 112, 4):
            sharp_crop[i:i + 2, :, :] = 255
            sharp_crop[:, i:i + 2, :] = 255

        sharpness_val = faces.face_sharpness(sharp_crop)
        self.assertGreater(sharpness_val, 100.0)

        # Heavily blurred version
        blurred_crop = cv2.GaussianBlur(sharp_crop, (15, 15), 0)
        blur_val = faces.face_sharpness(blurred_crop)

        self.assertLess(blur_val, 45.0)
        self.assertLess(blur_val, sharpness_val / 2.0)
        self.assertGreaterEqual(blur_val, 0.0)

    def test_face_eligible_area_threshold(self):
        # min_face_area is 0.0025 (0.25% image area)
        # 0.05 * 0.05 = 0.0025 (eligible)
        face_edge = {'bbox': [0.1, 0.1, 0.05, 0.05], 'sharpness': 50.0}
        self.assertTrue(faces.face_eligible(face_edge))

        # 0.04 * 0.05 = 0.0020 < 0.0025 (ineligible area)
        face_small = {'bbox': [0.1, 0.1, 0.04, 0.05], 'sharpness': 50.0}
        self.assertFalse(faces.face_eligible(face_small))

    def test_face_eligible_sharpness_threshold(self):
        # min_sharpness is 45.0
        face_sharp = {'bbox': [0.1, 0.1, 0.1, 0.1], 'sharpness': 45.0}
        self.assertTrue(faces.face_eligible(face_sharp))

        face_blurry = {'bbox': [0.1, 0.1, 0.1, 0.1], 'sharpness': 44.9}
        self.assertFalse(faces.face_eligible(face_blurry))

        # Null/missing sharpness stays eligible for legacy compatibility
        face_null_sharpness = {'bbox': [0.1, 0.1, 0.1, 0.1], 'sharpness': None}
        self.assertTrue(faces.face_eligible(face_null_sharpness))

        face_missing_sharpness = {'bbox': [0.1, 0.1, 0.1, 0.1]}
        self.assertTrue(faces.face_eligible(face_missing_sharpness))

    def test_face_eligible_custom_filters(self):
        custom_filters = {'min_face_area': 0.01, 'min_sharpness': 60.0}
        face = {'bbox': [0.1, 0.1, 0.08, 0.08], 'sharpness': 55.0}  # area = 0.0064 < 0.01, sharpness 55 < 60
        self.assertFalse(faces.face_eligible(face, custom_filters))

        face_pass = {'bbox': [0.1, 0.1, 0.15, 0.15], 'sharpness': 75.0}
        self.assertTrue(faces.face_eligible(face_pass, custom_filters))


class TestFaceQualityRawCacheValidation(unittest.TestCase):
    def test_validate_raw_detections_with_optional_sharpness(self):
        vec = make_unit_vector(128, 0)
        # Sharpness present as float
        payload = [{'bbox': [0.1, 0.1, 0.2, 0.2], 'vector': vec, 'score': 0.95, 'sharpness': 58.4}]
        validated = faces.validate_raw_detections(payload)
        self.assertEqual(len(validated), 1)
        self.assertEqual(validated[0]['sharpness'], 58.4)

        # Sharpness present as None
        payload_none = [{'bbox': [0.1, 0.1, 0.2, 0.2], 'vector': vec, 'score': 0.95, 'sharpness': None}]
        val_none = faces.validate_raw_detections(payload_none)
        self.assertIsNone(val_none[0]['sharpness'])

        # Sharpness omitted (defaults to None)
        payload_omitted = [{'bbox': [0.1, 0.1, 0.2, 0.2], 'vector': vec, 'score': 0.95}]
        val_omitted = faces.validate_raw_detections(payload_omitted)
        self.assertIsNone(val_omitted[0]['sharpness'])

    def test_validate_raw_detections_rejects_invalid_sharpness(self):
        vec = make_unit_vector(128, 0)

        # Negative sharpness
        with self.assertRaises(faces.PipelineError):
            faces.validate_raw_detections([{'bbox': [0.1, 0.1, 0.2, 0.2], 'vector': vec, 'score': 0.95, 'sharpness': -1.0}])

        # Boolean sharpness
        with self.assertRaises(faces.PipelineError):
            faces.validate_raw_detections([{'bbox': [0.1, 0.1, 0.2, 0.2], 'vector': vec, 'score': 0.95, 'sharpness': True}])

        # Non-finite sharpness
        with self.assertRaises(faces.PipelineError):
            faces.validate_raw_detections([{'bbox': [0.1, 0.1, 0.2, 0.2], 'vector': vec, 'score': 0.95, 'sharpness': float('nan')}])

        # Unexpected extra keys (e.g. leaked 'eligible' or 'group_id')
        with self.assertRaises(faces.PipelineError):
            faces.validate_raw_detections([{'bbox': [0.1, 0.1, 0.2, 0.2], 'vector': vec, 'score': 0.95, 'sharpness': 50.0, 'eligible': True}])


class TestFaceStoreSchemaUpgrade(unittest.TestCase):
    def test_schema_upgrade_preserves_existing_rows(self):
        with tempfile.TemporaryDirectory() as temp:
            db_path = Path(temp) / 'faces.sqlite3'

            # 1. Create database with old schema (WITHOUT 'eligible' column)
            conn = sqlite3.connect(str(db_path))
            conn.execute("""
            CREATE TABLE detections (
                id TEXT PRIMARY KEY,
                file_id INTEGER NOT NULL,
                etag TEXT NOT NULL,
                bbox TEXT NOT NULL,
                vector BLOB NOT NULL,
                score REAL NOT NULL,
                group_id TEXT NOT NULL,
                review INTEGER NOT NULL DEFAULT 0,
                updated_at REAL NOT NULL
            );
            """)
            vec_bytes = bytes([0] * 512)
            conn.execute("""
            INSERT INTO detections(id, file_id, etag, bbox, vector, score, group_id, review, updated_at)
            VALUES('old-det-1', 10, 'etag1', '[0.1,0.1,0.2,0.2]', ?, 0.95, 'group-old', 0, 1000.0)
            """, (vec_bytes,))
            conn.commit()
            conn.close()

            # 2. Open FaceStore on the existing DB
            store = faces.FaceStore(db_path)

            # Verify table now has eligible column
            cur = store.conn.execute("PRAGMA table_info(detections);")
            cols = {row[1]: row for row in cur.fetchall()}
            self.assertIn('eligible', cols)

            # Verify old row was preserved and eligible defaulted to 1
            cur = store.conn.execute("SELECT id, eligible FROM detections WHERE id = 'old-det-1'")
            row = cur.fetchone()
            self.assertIsNotNone(row)
            self.assertEqual(row[1], 1)

            # Verify load_active_vectors loads it
            active = store.load_active_vectors()
            self.assertEqual(len(active), 1)
            self.assertEqual(active[0][0], 'group-old')

            # 3. Test saving an ineligible detection
            store.save_detections([{
                'id': 'ineligible-det-2',
                'file_id': 11,
                'etag': 'etag2',
                'bbox': [0.1, 0.1, 0.01, 0.01],
                'vector': [0.0] * 128,
                'score': 0.8,
                'group_id': 'group-ineligible',
                'eligible': False,
            }])

            # load_active_vectors excludes eligible = 0!
            active_after = store.load_active_vectors()
            self.assertEqual(len(active_after), 1)
            self.assertEqual(active_after[0][0], 'group-old')

            store.close()


class TestFaceClusterIndexQuality(unittest.TestCase):
    def test_apply_canonical_excludes_ineligible_detections(self):
        index = faces.FaceClusterIndex()
        vec_good = make_unit_vector(128, 0)
        vec_bad = make_unit_vector(128, 1)

        canonical = [
            {'id': 'c1', 'group_id': 'group-anchor', 'vector': vec_good, 'eligible': True},
            {'id': 'c2', 'group_id': 'group-bad', 'vector': vec_bad, 'eligible': False},
            # Legacy mock without explicit eligible (defaults to True)
            {'id': 'c3', 'group_id': 'group-legacy', 'vector': make_unit_vector(128, 2)},
        ]

        index.apply_canonical(canonical)

        # group-bad must NOT be present in tail_groups
        self.assertIn('group-anchor', index.tail_groups)
        self.assertIn('group-legacy', index.tail_groups)
        self.assertNotIn('group-bad', index.tail_groups)

        # Matching against vec_bad creates a NEW group instead of matching 'group-bad'
        matched_group = index.match_or_create(vec_bad)
        self.assertNotEqual(matched_group, 'group-bad')


class TestQualityGroupingInPipeline(unittest.TestCase):
    def setUp(self):
        im = Image.new('RGB', (100, 100), color='white')
        buf = io.BytesIO()
        im.save(buf, format='JPEG')
        self.raw_image = buf.getvalue()

    def _setup_models(self, directory: Path):
        models_dir = directory / 'faces-local' / 'models'
        models_dir.mkdir(parents=True, exist_ok=True)
        (models_dir / 'face_detection_yunet_2023mar.onnx').touch()
        (models_dir / 'face_recognition_sface_2021dec.onnx').touch()

    def test_weak_detection_published_with_fresh_uuid_and_not_anchored(self):
        """Weak blurry/tiny detection is published with UUID but does NOT anchor future matches."""
        with tempfile.TemporaryDirectory() as temp:
            d = Path(temp)
            self._setup_models(d)

            config = {
                'faces': {'enabled': True},
                '_model_directory': str(d),
                '_inference_directory': str(d),
            }

            # File 1 has a blurry face (sharpness 20.0 < 45.0)
            items = [
                QualityMockItem(10, 'etag-10', path='/photos/blurry.jpg'),
                QualityMockItem(20, 'etag-20', path='/photos/sharp.jpg'),
            ]
            source = QualityMockSource(items, self.raw_image)
            api = QualityMockAPI()

            vec_shared = make_unit_vector(128, 5)

            mock_det = Mock()
            mock_det.process_image.side_effect = [
                # File 1: blurry face (sharpness 20.0)
                [{'bbox': [0.1, 0.1, 0.2, 0.2], 'vector': vec_shared, 'score': 0.95, 'sharpness': 20.0}],
                # File 2: sharp face with same vector (sharpness 80.0)
                [{'bbox': [0.1, 0.1, 0.2, 0.2], 'vector': vec_shared, 'score': 0.95, 'sharpness': 80.0}],
            ]

            # Override canonical response so File 1 has eligible: False
            api.canonical_overrides = [
                {'id': 'c1', 'file_id': 10, 'etag': 'etag-10', 'bbox': [0.1, 0.1, 0.2, 0.2], 'vector': vec_shared, 'score': 0.95, 'group_id': 'g-blur', 'eligible': False}
            ]

            with patch.object(faces, 'cv2', Mock()), patch.object(faces, 'FaceDetector', return_value=mock_det):
                res = faces.run(config, source, api, d, lambda *a, **k: None, lambda: False)

            self.assertEqual(res['state'], 'finished')
            self.assertEqual(res['stats']['done'], 2)
            self.assertEqual(len(api.published), 2)

            # Both files were published!
            pub1 = api.published[0]['detections'][0]
            pub2 = api.published[1]['detections'][0]

            self.assertRegex(pub1['group_id'], r'^[0-9a-f]{32}$')
            self.assertEqual(pub1['sharpness'], 20.0)
            self.assertEqual(pub2['sharpness'], 80.0)

            # File 2 did NOT match File 1's group because File 1 was ineligible!
            self.assertNotEqual(pub1['group_id'], pub2['group_id'])

    def test_account_policy_differentiation_on_same_raw_cache(self):
        """Account A (permissive) anchors face; Account B (strict) rejects same raw face."""
        with tempfile.TemporaryDirectory() as temp:
            d = Path(temp)
            self._setup_models(d)

            item = QualityMockItem(100, 'etag-100', path='/photos/medium.jpg')
            source = QualityMockSource([item], self.raw_image)

            vec = make_unit_vector(128, 7)

            # Pre-seed shared raw cache with medium sharpness face (sharpness = 40.0, area = 0.04)
            api_a = QualityMockAPI()
            api_a.artifacts_cache[(100, 'etag-100')] = {
                'detections': [{'bbox': [0.1, 0.1, 0.2, 0.2], 'vector': vec, 'score': 0.92, 'sharpness': 40.0}],
                'inference_revision': '2' * 64,
                'disabled': False,
            }

            api_b = QualityMockAPI()
            api_b.artifacts_cache[(100, 'etag-100')] = api_a.artifacts_cache[(100, 'etag-100')]

            # Account A has relaxed sharpness filter (min_sharpness = 35.0 -> eligible)
            config_a = {
                'faces': {'enabled': True},
                'faces_filters': {'min_sharpness': 35.0, 'min_face_area': 0.001},
                '_model_directory': str(d),
                '_inference_directory': str(d),
            }

            # Account B has strict sharpness filter (min_sharpness = 50.0 -> ineligible)
            config_b = {
                'faces': {'enabled': True},
                'faces_filters': {'min_sharpness': 50.0, 'min_face_area': 0.001},
                '_model_directory': str(d),
                '_inference_directory': str(d),
            }

            dir_a = d / 'user_a'
            dir_a.mkdir()
            dir_b = d / 'user_b'
            dir_b.mkdir()

            mock_det = Mock()
            mock_det.process_image.side_effect = AssertionError('Cache hit must not call detector')

            with patch.object(faces, 'cv2', Mock()), patch.object(faces, 'FaceDetector', return_value=mock_det):
                faces.run(config_a, source, api_a, dir_a, lambda *a, **k: None, lambda: False)
                faces.run(config_b, source, api_b, dir_b, lambda *a, **k: None, lambda: False)

            # Both accounts published using the same raw cache hit
            pub_a = api_a.published[0]['detections'][0]
            pub_b = api_b.published[0]['detections'][0]

            self.assertEqual(pub_a['sharpness'], 40.0)
            self.assertEqual(pub_b['sharpness'], 40.0)

            # Account A matched or created an anchor in its store; Account B assigned an isolated UUID
            self.assertNotEqual(pub_a['group_id'], pub_b['group_id'])

    def test_real_public_model_smoke_sharpness(self):
        """Smoke test on public photo (artifacts/faces-public-smoke/obama-2009.jpg) measuring sharpness."""
        if faces.cv2 is None or faces.np is None:
            self.skipTest('OpenCV/NumPy not available in this runtime')

        public_photo = Path('artifacts/faces-public-smoke/obama-2009.jpg')
        if not public_photo.is_file():
            self.skipTest('Public smoke test photo not found')

        model_dir = Path('/tmp/nextgallery-faces-dev')
        models = faces.faces_setup.model_paths(model_dir)
        det_path = models['detector']
        rec_path = models['recognizer']
        if not det_path.is_file() or not rec_path.is_file():
            self.skipTest('Models not found in /tmp/nextgallery-faces-dev')

        detector = faces.FaceDetector(det_path, rec_path)
        img_bytes = public_photo.read_bytes()
        results = detector.process_image(img_bytes)

        self.assertGreater(len(results), 0)
        for r in results:
            self.assertIn('sharpness', r)
            self.assertIsInstance(r['sharpness'], float)
            self.assertGreater(r['sharpness'], 0.0)
            self.assertTrue(math.isfinite(r['sharpness']))
            self.assertTrue(faces.face_eligible(r))


if __name__ == '__main__':
    unittest.main()
