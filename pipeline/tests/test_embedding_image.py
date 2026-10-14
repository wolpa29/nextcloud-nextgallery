import io
import json
from pathlib import Path
import sys
import unittest
from unittest.mock import patch
import subprocess

sys.path.insert(0, str(Path(__file__).resolve().parents[2] / "worker"))

from PIL import Image
import media_pipeline
from media_ai_errors import PipelineError
import embedding_image


class EmbeddingImageTests(unittest.TestCase):
    def test_normal_png_is_byte_identical_to_existing_pipeline(self):
        """Standard images must produce byte-identical results to preserve existing spaces."""
        im = Image.new("RGB", (256, 128), color=(20, 180, 70))
        buf = io.BytesIO()
        im.save(buf, format="PNG")
        png_data = buf.getvalue()

        expected_wire, expected_mime, expected_exif = media_pipeline.prepare_image(png_data, 512, 80_000_000, "embedding")
        actual_wire, actual_mime, actual_exif = embedding_image.prepare_image(png_data, 512, 80_000_000)

        self.assertEqual(actual_wire, expected_wire)
        self.assertEqual(actual_mime, expected_mime)
        self.assertEqual(actual_exif, expected_exif)

    def test_large_jpeg_exceeding_guard_decodes_via_draft_subprocess(self):
        """Simulate high-resolution panorama (e.g. 3490 x 25430 ~88.7 MP) exceeding 80 MP decode guard."""
        # Create an 88.7 MP image (3490 x 25430) with low entropy to generate fast in memory
        width, height = 3490, 25430
        self.assertGreater(width * height, 80_000_000)

        im = Image.new("RGB", (width, height), color=(100, 150, 200))
        exif = im.getexif()
        exif[271] = "PanoCamera"
        exif[272] = "UltraWide"
        buf = io.BytesIO()
        im.save(buf, format="JPEG", exif=exif)
        jpeg_data = buf.getvalue()

        # Existing media_pipeline.prepare_image MUST reject this due to decode memory guard
        with self.assertRaises(PipelineError) as ctx:
            media_pipeline.prepare_image(jpeg_data, 2048, 80_000_000, "embedding")
        self.assertIn("Source image exceeds decode memory guard", str(ctx.exception))

        # embedding_image.prepare_image MUST successfully decode via draft subprocess
        max_edge = 2048
        wire, mime, extracted_exif = embedding_image.prepare_image(jpeg_data, max_edge, 80_000_000)
        self.assertEqual(mime, "image/png")
        self.assertEqual(extracted_exif.get("camera_make"), "PanoCamera")
        self.assertEqual(extracted_exif.get("camera_model"), "UltraWide")

        # Verify decoded PNG dimensions
        with Image.open(io.BytesIO(wire)) as decoded:
            self.assertLessEqual(max(decoded.size), max_edge)
            expected_w = round(width * (max_edge / height))
            self.assertAlmostEqual(decoded.width, expected_w, delta=5)
            self.assertEqual(decoded.height, max_edge)

    def test_exif_orientation_preserved_on_large_jpeg(self):
        """Ensure orientation tag (e.g. 6 = rotate 90 CW) is correctly respected on draft decoding."""
        # 4000 x 2000 image with orientation 6 should become height > width
        im = Image.new("RGB", (4000, 2000), color=(220, 50, 50))
        exif = im.getexif()
        exif[274] = 6  # 90 degrees CW rotation
        buf = io.BytesIO()
        im.save(buf, format="JPEG", exif=exif)
        jpeg_data = buf.getvalue()

        # Set max_pixels to 5 MP so fallback is triggered
        wire, mime, _ = embedding_image.prepare_image(jpeg_data, 1024, 5_000_000)
        with Image.open(io.BytesIO(wire)) as decoded:
            # Oriented size: width 2000, height 4000 -> scaled to height 1024, width 512
            self.assertEqual(decoded.height, 1024)
            self.assertEqual(decoded.width, 512)

    def test_empty_data_raises_clear_error(self):
        """0-byte file must raise clean PipelineError without BytesIO pointer."""
        with self.assertRaises(PipelineError) as ctx:
            embedding_image.prepare_image(b"", 2048, 80_000_000)
        msg = str(ctx.exception)
        self.assertIn("empty", msg.lower())
        self.assertNotIn("BytesIO", msg)

    def test_html_data_raises_clear_error(self):
        """Server HTML/XML response instead of image bytes must raise clean PipelineError."""
        html = b"<!DOCTYPE html><html><head><title>404 Not Found</title></head><body>File not found</body></html>"
        with self.assertRaises(PipelineError) as ctx:
            embedding_image.prepare_image(html, 2048, 80_000_000)
        msg = str(ctx.exception)
        self.assertIn("HTML/XML", msg)
        self.assertNotIn("BytesIO", msg)

    def test_corrupted_garbage_data_raises_clean_error(self):
        """Corrupted binary noise must raise clean error without internal object address."""
        garbage = b"\x00\x01\x02\x03random_binary_garbage_not_an_image"
        with self.assertRaises(PipelineError) as ctx:
            embedding_image.prepare_image(garbage, 2048, 80_000_000)
        msg = str(ctx.exception)
        self.assertNotIn("BytesIO object at", msg)

    def test_truncated_jpeg_raises_honest_pipeline_error(self):
        """Truncated image file must honestly fail and never be marked done."""
        im = Image.new("RGB", (800, 600), color=(80, 120, 200))
        buf = io.BytesIO()
        im.save(buf, format="JPEG")
        data = buf.getvalue()
        truncated = data[: len(data) // 3]

        with self.assertRaises(PipelineError) as ctx:
            embedding_image.prepare_image(truncated, 512, 80_000_000)
        msg = str(ctx.exception)
        self.assertNotIn("BytesIO object at", msg)

    def test_decoder_memory_limit_isolated(self):
        """Subprocess memory limit triggers clean error if exceeded."""
        im = Image.new("RGB", (2000, 2000), color="blue")
        buf = io.BytesIO()
        im.save(buf, format="JPEG")
        data = buf.getvalue()

        # With an unrealistically small memory limit (e.g. 5 MB), subprocess RLIMIT_AS will fail
        with self.assertRaises(PipelineError):
            embedding_image._decode_in_subprocess(data, 512, 1_000_000, mem_limit=5 * 1024 * 1024)

    def test_bomb_guard_is_relaxed_only_in_child(self):
        image=Image.new('RGB',(120,80),'red');out=io.BytesIO();image.save(out,'JPEG')
        wire,_,_=embedding_image.prepare_image(out.getvalue(),32,1000)
        with Image.open(io.BytesIO(wire)) as prepared:self.assertEqual(prepared.size,(32,21))
        self.assertEqual(Image.MAX_IMAGE_PIXELS,1000)

    def test_elapsed_budget_and_cancellation_are_never_hidden(self):
        from media_ai_budget import ImageTimeout
        from inference_gate import InferenceCancelled
        for error in (ImageTimeout('budget expired'),InferenceCancelled()):
            with self.subTest(error=type(error).__name__),patch.object(media_pipeline,'prepare_image',side_effect=error):
                with self.assertRaises(type(error)):
                    embedding_image.prepare_image(b'\xff\xd8synthetic',32,1000)

    def test_decoder_timeout_is_an_explicit_failure(self):
        with patch.object(embedding_image.subprocess,'run',side_effect=subprocess.TimeoutExpired('synthetic',1)):
            with self.assertRaisesRegex(PipelineError,'decoder timed out'):
                embedding_image._decode_in_subprocess(b'\xff\xd8synthetic',32,1000,timeout=1)


if __name__ == "__main__":
    unittest.main()
