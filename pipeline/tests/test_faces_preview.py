"""Unit tests for faces_preview module."""
import io
from pathlib import Path
import unittest
from unittest.mock import patch

from PIL import Image

import faces_preview as preview


class FacesPreviewTests(unittest.TestCase):
    def test_crop_synthetic_image(self):
        im = Image.new('RGB', (400, 300), color=(100, 150, 200))
        buf = io.BytesIO()
        im.save(buf, format='JPEG')
        raw = buf.getvalue()

        # Crop center region [x=0.25, y=0.25, w=0.5, h=0.5]
        crop_bytes = preview.crop_face(raw, [0.25, 0.25, 0.5, 0.5], size=128)
        self.assertIsInstance(crop_bytes, bytes)
        self.assertGreater(len(crop_bytes), 100)

        with Image.open(io.BytesIO(crop_bytes)) as out_im:
            self.assertEqual(out_im.format, 'JPEG')
            self.assertLessEqual(out_im.width, 128)
            self.assertLessEqual(out_im.height, 128)

    def test_invalid_bbox_rejected(self):
        im = Image.new('RGB', (100, 100), 'white')
        buf = io.BytesIO()
        im.save(buf, 'PNG')
        raw = buf.getvalue()

        with self.assertRaises(ValueError):
            preview.crop_face(raw, [0.1, 0.2])  # Too few elements

        with self.assertRaises(ValueError):
            preview.crop_face(raw, [0.1, 0.1, -0.5, 0.2])  # Negative width

    def test_non_finite_and_out_of_bounds_bbox_rejected(self):
        im = Image.new('RGB', (100, 100), 'white')
        buf = io.BytesIO()
        im.save(buf, 'JPEG')
        raw = buf.getvalue()

        # NaN / Inf
        with self.assertRaises(ValueError):
            preview.crop_face(raw, [float('nan'), 0.1, 0.2, 0.2])
        with self.assertRaises(ValueError):
            preview.crop_face(raw, [0.1, float('inf'), 0.2, 0.2])

        # Origin out of [0, 1]
        with self.assertRaises(ValueError):
            preview.crop_face(raw, [-0.05, 0.1, 0.2, 0.2])
        with self.assertRaises(ValueError):
            preview.crop_face(raw, [0.1, 1.05, 0.2, 0.2])

        # Dimensions out of range
        with self.assertRaises(ValueError):
            preview.crop_face(raw, [0.1, 0.1, 0.0, 0.2])
        with self.assertRaises(ValueError):
            preview.crop_face(raw, [0.1, 0.1, 1.2, 0.2])

        # Overextending beyond image boundary
        with self.assertRaises(ValueError):
            preview.crop_face(raw, [0.8, 0.8, 0.5, 0.5])

    def test_pixel_guard(self):
        im = Image.new('RGB', (100, 100), 'black')
        buf = io.BytesIO()
        im.save(buf, 'JPEG')
        raw = buf.getvalue()

        with patch.object(preview, 'MAX_IMAGE_PIXELS', 50):
            with self.assertRaises(ValueError) as ctx:
                preview.crop_face(raw, [0.1, 0.1, 0.5, 0.5])
            self.assertIn('pixel guard', str(ctx.exception))

    def test_crop_with_exif_transposition_closes_handles(self):
        im = Image.new('RGB', (100, 80), 'blue')
        exif = im.getexif()
        exif[0x0112] = 6  # Rotate 90 CW
        buf = io.BytesIO()
        im.save(buf, 'JPEG', exif=exif)
        raw = buf.getvalue()

        crop_bytes = preview.crop_face(raw, [0.1, 0.1, 0.5, 0.5], size=64)
        self.assertIsInstance(crop_bytes, bytes)
        self.assertGreater(len(crop_bytes), 50)

    def test_face_detector_panorama_scaling_does_not_distort_aspect_ratio(self):
        import media_ai_faces as faces
        # Extreme panorama: 2000 x 20
        im = Image.new('RGB', (2000, 20), 'green')
        buf = io.BytesIO()
        im.save(buf, 'JPEG')
        raw = buf.getvalue()

        mock_cv2 = unittest.mock.Mock()
        mock_np = unittest.mock.Mock()
        mock_det_instance = unittest.mock.Mock()
        mock_det_instance.detect.return_value = (None, None)
        mock_cv2.FaceDetectorYN.create.return_value = mock_det_instance
        mock_cv2.FaceRecognizerSF.create.return_value = unittest.mock.Mock()

        dummy_path = Path(__file__)

        with patch.object(faces, 'cv2', mock_cv2), patch.object(faces, 'np', mock_np):
            detector = faces.FaceDetector(dummy_path, dummy_path)
            # max_edge=4096 supported
            detector.process_image(raw, max_edge=4096)
            # Check input_size set on detector
            calls = detector.detector.setInputSize.call_args_list
            self.assertGreater(len(calls), 0)
            # The input size for 2000x20 at scale 1.0 (since max_edge=4096 > 2000)
            # nw should be 2000, nh should be 20 (NOT clamped to 32)
            nw, nh = calls[0][0][0]
            self.assertEqual(nw, 2000)
            self.assertEqual(nh, 20)


if __name__ == '__main__':
    unittest.main()
