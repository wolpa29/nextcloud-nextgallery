"""Smoke test exercising real OpenCV YuNet and SFace models on public fixtures."""
import os
from pathlib import Path
import unittest

import media_ai_faces as faces

ROOT = Path(__file__).resolve().parents[2]
SMOKE_DIR = Path(os.environ.get('NEXTGALLERY_FACES_FIXTURES',ROOT / 'artifacts' / 'faces-public-smoke'))
DEV_MODELS = Path(os.environ.get('NEXTGALLERY_FACES_MODELS','/tmp/nextgallery-faces-dev/faces-local/models'))


class FacesSmokeRealInferenceTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        if faces.cv2 is None or faces.np is None:
            raise unittest.SkipTest("OpenCV/numpy not installed in current python environment")
        det_path = DEV_MODELS / 'face_detection_yunet_2023mar.onnx'
        rec_path = DEV_MODELS / 'face_recognition_sface_2021dec.onnx'
        if not (det_path.is_file() and rec_path.is_file()):
            raise unittest.SkipTest("Set NEXTGALLERY_FACES_MODELS to the installed YuNet/SFace directory")
        if not SMOKE_DIR.is_dir():
            raise unittest.SkipTest("artifacts/faces-public-smoke directory missing")
        cls.detector = faces.FaceDetector(det_path, rec_path, detection_threshold=0.85)

    def test_multiscale_detection_on_all_four_smoke_fixtures(self):
        """At 2048+640 multiscale, all 4 public portraits must be detected with 1 face each."""
        images = ['obama-2009.jpg', 'obama-2012.jpg', 'biden-2013.jpg', 'biden-2021.jpg']
        detections = {}
        for name in images:
            raw = (SMOKE_DIR / name).read_bytes()
            results = self.detector.process_image(raw, max_edge=2048)
            self.assertEqual(len(results), 1, f"Expected exactly 1 face in {name}, got {len(results)}")
            bbox = results[0]['bbox']
            self.assertEqual(len(bbox), 4)
            # Check bounding box normalization
            self.assertTrue(all(0.0 <= v <= 1.0 for v in bbox))
            self.assertGreater(bbox[2], 0.05)  # reasonable width
            self.assertGreater(bbox[3], 0.05)  # reasonable height
            detections[name] = results[0]

        # Verify Cosine similarities
        vec_o2009 = faces.np.array(detections['obama-2009.jpg']['vector'])
        vec_o2012 = faces.np.array(detections['obama-2012.jpg']['vector'])
        vec_b2013 = faces.np.array(detections['biden-2013.jpg']['vector'])
        vec_b2021 = faces.np.array(detections['biden-2021.jpg']['vector'])

        sim_obama = float(faces.np.dot(vec_o2009, vec_o2012))
        sim_biden = float(faces.np.dot(vec_b2013, vec_b2021))
        sim_diff_1 = float(faces.np.dot(vec_o2012, vec_b2013))
        sim_diff_2 = float(faces.np.dot(vec_o2009, vec_b2021))

        self.assertGreater(sim_obama, 0.70, f"Expected high Obama similarity, got {sim_obama}")
        self.assertGreater(sim_biden, 0.70, f"Expected high Biden similarity, got {sim_biden}")
        self.assertLess(sim_diff_1, 0.35, f"Expected low cross-identity similarity, got {sim_diff_1}")
        self.assertLess(sim_diff_2, 0.35, f"Expected low cross-identity similarity, got {sim_diff_2}")

    def test_clustering_produces_two_distinct_groups(self):
        """Index clustering at threshold 0.55 must group Obama together and Biden together."""
        cluster_index = faces.FaceClusterIndex(cosine_threshold=0.55)
        assigned_groups = {}
        for name in ['obama-2009.jpg', 'obama-2012.jpg', 'biden-2013.jpg', 'biden-2021.jpg']:
            raw = (SMOKE_DIR / name).read_bytes()
            results = self.detector.process_image(raw, max_edge=2048)
            group_id = cluster_index.match_or_create(results[0]['vector'])
            assigned_groups[name] = group_id

        # Obama 2009 and 2012 share the same cluster
        self.assertEqual(assigned_groups['obama-2009.jpg'], assigned_groups['obama-2012.jpg'])
        # Biden 2013 and 2021 share the same cluster
        self.assertEqual(assigned_groups['biden-2013.jpg'], assigned_groups['biden-2021.jpg'])
        # Obama and Biden are in different clusters
        self.assertNotEqual(assigned_groups['obama-2009.jpg'], assigned_groups['biden-2013.jpg'])


if __name__ == '__main__':
    unittest.main()
