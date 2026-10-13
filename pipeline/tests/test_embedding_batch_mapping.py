"""Independent verification of batch-to-media vector mapping.

Validates that batching, chunking, fallbacks, outages, and mixed inventories
never shift or transpose vectors relative to their source media items.
"""
from __future__ import annotations
import base64
import io
import json
from pathlib import Path
import tempfile
from typing import Any
import unittest
from unittest.mock import patch
from PIL import Image

import media_pipeline as p
import media_ai_embedding as e
from pipeline.tests.test_embedding import config, API
from pipeline.tests.test_embedding_batch_worker import png, values, Source, BatchClient


class MixedSource:
    """Source that returns items in an arbitrary, non-sequential inventory order."""
    def __init__(self, locators: list[int]):
        self.items = [p.Item(f'key_{i}', f'photo_{i}.png', str(i), 'v1', len(png(i)), 'image/png') for i in locators]
        self.changed: set[str] = set()

    def inventory(self) -> list[p.Item]:
        return list(self.items)

    def read(self, item: p.Item, maximum: int) -> bytes:
        return png(int(item.locator))

    def unchanged(self, item: p.Item) -> bool:
        return item.key not in self.changed

    def download_to(self, item: p.Item, path: Path, maximum: int) -> None:
        path.write_bytes(b'synthetic video')


class EmbeddingBatchMappingTests(unittest.TestCase):
    def setUp(self):
        BatchClient.calls = []

    def run_worker(self, directory: Path, source: Any, api: Any, batch_size: int = 4, stop=lambda: False):
        setting = config()
        setting['embedding']['batch_size'] = batch_size
        with patch.object(e, 'Client', BatchClient):
            return e.run(setting, source, api, directory, lambda *a, **k: None, stop)

    def test_mixed_inventory_order_with_tail_maintains_exact_vector_mapping(self):
        """Mixed non-ascending file IDs (e.g. 99, 12, 77, 3, 50, 8, 21) across Batch4 + remainder3."""
        locators = [99, 12, 77, 3, 50, 8, 21]
        source = MixedSource(locators)
        api = API()

        with tempfile.TemporaryDirectory() as tmp:
            result = self.run_worker(Path(tmp), source, api, batch_size=4)
            self.assertEqual(result['processed'], 7)
            self.assertEqual(result['errors'], 0)
            self.assertEqual(BatchClient.calls, [4, 3])

            self.assertEqual(len(api.published), 7)
            published_ids = [row['file_id'] for row in api.published]
            self.assertEqual(published_ids, locators, "Published items must retain inventory order")

            for row in api.published:
                expected_vector = values(row['file_id'])
                actual_vector = row['vectors'][0]['values']
                self.assertEqual(
                    actual_vector,
                    expected_vector,
                    f"Vector for file_id {row['file_id']} was transposed or mismatched!",
                )

    def test_batch_fallback_with_error_in_middle_does_not_shift_subsequent_vectors(self):
        """When a batch fails and falls back to single requests, a failure on item i must not shift items > i."""
        locators = [10, 20, 30, 40, 50]
        source = MixedSource(locators)
        api = API()

        class FaultyBatchClient(BatchClient):
            def images(self, wires, should_stop=lambda: False):
                # Simulate batch endpoint failure (e.g. 400 or model rejection)
                if len(wires) > 1:
                    raise p.PipelineError("Batch rejected by server")
                return super().images(wires, should_stop)

            def image(self, wire):
                # When falling back to single requests, fail exactly on locator 30
                index = Image.open(io.BytesIO(wire)).getpixel((0, 0))[0]
                if index == 30:
                    raise p.PipelineError("Corrupt image data at locator 30")
                return values(index)

        with tempfile.TemporaryDirectory() as tmp:
            directory = Path(tmp)
            setting = config()
            setting['embedding']['batch_size'] = 5

            with patch.object(e, 'Client', FaultyBatchClient):
                result = e.run(setting, source, api, directory, lambda *a, **k: None, lambda: False)

            self.assertEqual(result['processed'], 4)
            self.assertEqual(result['errors'], 1)

            published_ids = [row['file_id'] for row in api.published]
            self.assertEqual(published_ids, [10, 20, 40, 50])

            # CRITICAL CHECK: Verify items 40 and 50 received their OWN vectors, NOT shifted vectors!
            for row in api.published:
                fid = row['file_id']
                self.assertEqual(
                    row['vectors'][0]['values'],
                    values(fid),
                    f"Vector shift detected on file_id {fid}! Expected {fid}, got mismatch.",
                )

            # Verify the failed item in database
            store = e.Store(directory / 'embeddings.sqlite3')
            failed_row = store.db.execute("SELECT status, error, vectors FROM embeddings WHERE id='key_30'").fetchone()
            self.assertIsNotNone(failed_row)
            self.assertEqual(failed_row['status'], 'error')
            self.assertIn("Corrupt image data at locator 30", failed_row['error'])
            self.assertIsNone(failed_row['vectors'])
            store.db.close()

    def test_client_byte_limit_chunking_preserves_order_for_large_payloads(self):
        """Client.images() must preserve 1-to-1 order when base64 payload size forces internal chunk splits."""
        setting = config()['embedding']
        setting['batch_size'] = 10
        client = e.Client(setting)
        client.info = {'space': 'a' * 64, 'dimensions': 128, 'image_batch': True, 'max_batch_size': 64}

        # Create 5 distinct large wires of ~7 MB each (Base64 size ~9.33 MB).
        # At 24 MiB limit, batch size of 10 cannot fit all 5; it must chunk into [2, 2, 1].
        large_size = 7 * 1024 * 1024
        wires = [bytes([idx]) + b'\x00' * (large_size - 1) for idx in range(5)]

        recorded_calls: list[dict[str, Any]] = []

        def fake_call(endpoint, body, maximum=None):
            recorded_calls.append(body)
            if 'image' in body:
                raw = base64.b64decode(body['image'])
                idx = raw[0]
                return {'space': 'a' * 64, 'embedding': values(idx)}
            elif 'images' in body:
                idxs = [base64.b64decode(w)[0] for w in body['images']]
                return {'space': 'a' * 64, 'embeddings': [values(i) for i in idxs]}
            raise AssertionError(f"Unexpected endpoint call: {endpoint}")

        with patch.object(client, 'call', side_effect=fake_call):
            results = client.images(wires)

        self.assertEqual(len(results), 5)
        for i, vec in enumerate(results):
            self.assertEqual(vec, values(i), f"Vector at index {i} does not match input wire {i}")

        # Check call structure: first two calls had 2 images each, last call had 1 image
        self.assertEqual(len(recorded_calls), 3)
        self.assertEqual([len(c.get('images', [c.get('image')])) for c in recorded_calls], [2, 2, 1])
        # Verify signatures in each request
        sigs_call_0 = [base64.b64decode(w)[0] for w in recorded_calls[0]['images']]
        sigs_call_1 = [base64.b64decode(w)[0] for w in recorded_calls[1]['images']]
        sig_call_2 = base64.b64decode(recorded_calls[2]['image'])[0]
        self.assertEqual(sigs_call_0, [0, 1])
        self.assertEqual(sigs_call_1, [2, 3])
        self.assertEqual(sig_call_2, 4)

    def test_publish_outage_with_mixed_inventory_resumes_without_reembedding_or_misalignment(self):
        """Publication outage halfway through mixed inventory must persist staged batch and resume cleanly."""
        locators = [42, 9, 88, 3, 71, 15]
        source = MixedSource(locators)
        api = API()
        api.fail = True

        with tempfile.TemporaryDirectory() as tmp:
            directory = Path(tmp)
            # Run 1: Outage occurs on first publish
            result1 = self.run_worker(directory, source, api, batch_size=4)
            self.assertEqual(result1['state'], 'deferred')
            self.assertEqual(len(api.published), 0)

            # First batch of 4 (42, 9, 88, 3) was staged into SQLite before publishing failed
            store = e.Store(directory / 'embeddings.sqlite3')
            staged_count = store.db.execute("SELECT COUNT(*) FROM embeddings WHERE vectors IS NOT NULL").fetchone()[0]
            self.assertEqual(staged_count, 4)
            store.db.close()

            calls_after_run1 = len(BatchClient.calls)

            # Run 2: Publisher restored; resume processing
            api.fail = False
            result2 = self.run_worker(directory, source, api, batch_size=4)
            self.assertEqual(result2['state'], 'finished')
            self.assertEqual(result2['processed'], 6)

            # BatchClient should only have been called for the remaining 2 items (71, 15)
            new_calls = BatchClient.calls[calls_after_run1:]
            self.assertEqual(new_calls, [2])

            self.assertEqual(len(api.published), 6)
            self.assertEqual([r['file_id'] for r in api.published], locators)

            for row in api.published:
                fid = row['file_id']
                self.assertEqual(
                    row['vectors'][0]['values'],
                    values(fid),
                    f"Resumed vector for file_id {fid} does not match!",
                )

    def test_pause_and_resume_with_mixed_inventory_order(self):
        """Worker paused after partial publication in mixed inventory order must resume with exact mapping."""
        locators = [88, 14, 55, 2, 91]
        source = MixedSource(locators)
        api = API()

        with tempfile.TemporaryDirectory() as tmp:
            directory = Path(tmp)

            # Pause after exactly 2 published items
            result1 = self.run_worker(directory, source, api, batch_size=4, stop=lambda: len(api.published) == 2)
            self.assertEqual(result1['state'], 'paused')
            self.assertEqual(result1['processed'], 2)
            self.assertEqual([r['file_id'] for r in api.published], [88, 14])

            # Resume to completion
            result2 = self.run_worker(directory, source, api, batch_size=4)
            self.assertEqual(result2['state'], 'finished')
            self.assertEqual(result2['processed'], 3)
            self.assertEqual(len(api.published), 5)
            self.assertEqual([r['file_id'] for r in api.published], locators)

            for row in api.published:
                fid = row['file_id']
                self.assertEqual(row['vectors'][0]['values'], values(fid))

    def test_video_frame_order_and_timestamp_correspondence(self):
        """Video frames embedded via batch must strictly preserve frame index and timestamp alignment."""
        source = Source(1)
        source.items = [p.Item('v_test', 'test_video.mp4', '777', 'v1', 100, 'video/mp4')]

        frame_count = 6
        timestamps = [0.0, 1.5, 3.0, 4.5, 6.0, 7.5]
        # Frame i has pixel value i
        frames = p.ImageBatch([(png(i), 'image/png', f'frame_{i}') for i in range(frame_count)], 'test frames')

        def mock_prepare_video(path, setting, max_pixels, report):
            report['frames'] = [{'sample_seconds': ts} for ts in timestamps]
            return frames, 'image/png', {}

        api = API()
        with tempfile.TemporaryDirectory() as tmp, patch.object(p, 'prepare_video', mock_prepare_video):
            result = self.run_worker(Path(tmp), source, api, batch_size=4)
            self.assertEqual(result['processed'], 1)
            self.assertEqual(BatchClient.calls, [6])

            self.assertEqual(len(api.published), 1)
            published_vectors = api.published[0]['vectors']
            self.assertEqual(len(published_vectors), frame_count)

            for i, vec_info in enumerate(published_vectors):
                self.assertEqual(vec_info['frame'], i)
                self.assertEqual(vec_info['seconds'], timestamps[i])
                self.assertEqual(vec_info['values'], values(i))

    def test_real_http_server_mixed_inventory_end_to_end(self):
        """End-to-end integration test with real HTTPServer, handler, and mixed inventory order."""
        from http.server import HTTPServer
        import threading
        from embedding_server import handler

        class MockEngine:
            info = {
                'model': 'embeddinggemma-2',
                'family': 'gemma2',
                'space': 'a' * 64,
                'dimensions': 128,
                'modalities': ['text', 'image'],
                'image_batch': True,
                'max_batch_size': 64,
            }

            def embed(self, text=None, image=None):
                idx = Image.open(io.BytesIO(image)).getpixel((0, 0))[0]
                return values(idx)

            def embed_images(self, images):
                idxs = [Image.open(io.BytesIO(wire)).getpixel((0, 0))[0] for wire in images]
                return [values(i) for i in idxs]

        engine = MockEngine()
        server = HTTPServer(('127.0.0.1', 0), handler(engine, 'secret-token'))
        thread = threading.Thread(target=server.serve_forever, daemon=True)
        thread.start()

        try:
            locators = [73, 11, 49, 5, 28]
            source = MixedSource(locators)
            api = API()
            with tempfile.TemporaryDirectory() as tmp:
                setting = config()
                setting['embedding'].update(
                    batch_size=3,
                    url=f'http://127.0.0.1:{server.server_port}/v1',
                    api_key='secret-token',
                )
                result = e.run(setting, source, api, Path(tmp), lambda *a, **k: None, lambda: False)
                self.assertEqual(result['processed'], 5)
                self.assertEqual(len(api.published), 5)
                self.assertEqual([r['file_id'] for r in api.published], locators)
                for row in api.published:
                    self.assertEqual(row['vectors'][0]['values'], values(row['file_id']))
        finally:
            server.shutdown()
            server.server_close()
            thread.join()


if __name__ == '__main__':
    unittest.main()
