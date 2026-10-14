"""Regression and verification tests for worker reporting.

Verifies:
- current.files schema for simultaneous batch processing (e.g. 4-item batch)
- current.started_at stability across heartbeats and inference_progress
- embedding_stats in embedding lane does not accumulate old analysis_stats
- Real 4-item batch display during client.images execution
- Error reporting, OCR pipeline stats, and timing
"""
from __future__ import annotations

import io
import json
from pathlib import Path
import sys
import tempfile
import time
import unittest
from unittest.mock import patch
from PIL import Image

_root = Path(__file__).resolve().parents[2]
if str(_root / 'worker') not in sys.path:
    sys.path.insert(0, str(_root / 'worker'))
if str(_root / 'pipeline' / 'tests') not in sys.path:
    sys.path.insert(0, str(_root / 'pipeline' / 'tests'))

import media_pipeline as p
import media_ai_embedding as e
import app_worker as w


def synthetic_png(index: int) -> bytes:
    out = io.BytesIO()
    Image.new('RGB', (32, 24), (index, 20, 100)).save(out, 'PNG')
    return out.getvalue()


def synthetic_values(index: int, dim: int = 128) -> list[float]:
    return [float(i == index) for i in range(dim)]


class SyntheticSource:
    def __init__(self, count: int):
        self.items = [
            p.Item(str(i), f'{i}.png', str(i), 'v1', len(synthetic_png(i)), 'image/png')
            for i in range(count)
        ]
        self.changed: set[str] = set()

    def inventory(self):
        return self.items

    def read(self, item, maximum):
        return synthetic_png(int(item.locator))

    def unchanged(self, item):
        return item.key not in self.changed

    def download_to(self, item, path, maximum):
        path.write_bytes(b'synthetic video')


class SyntheticBatchClient:
    calls: list[int] = []
    current_during_images: list[dict | None] = []
    disk_during_images: list[dict | None] = []

    def __init__(self, setting, reporter=None, directory=None):
        self.setting = setting
        self.reporter = reporter
        self.directory = directory
        self.info = {'space': 'a' * 64, 'dimensions': 128}
        self.space = e.space_id(self.info, setting)

    def health(self):
        return self.info

    def batch_size(self):
        return self.setting.get('batch_size', 4)

    def images(self, wires, should_stop=lambda: False):
        SyntheticBatchClient.calls.append(len(wires))
        if self.reporter is not None:
            SyntheticBatchClient.current_during_images.append(
                json.loads(json.dumps(self.reporter.value.get('current')))
                if self.reporter.value.get('current')
                else None
            )
        if self.directory is not None:
            status_file = w.lane_file(self.directory, 'status.json', 'embeddings')
            status = w.read(status_file, {})
            SyntheticBatchClient.disk_during_images.append(status.get('current'))
        return [
            synthetic_values(Image.open(io.BytesIO(wire)).getpixel((0, 0))[0])
            for wire in wires
        ]

    def image(self, wire):
        return self.images([wire])[0]


class SyntheticAPI:
    url = 'https://synthetic.invalid/native/'

    def __init__(self):
        self.published = []
        self.fail = False

    def call(self, path, body=None):
        if path == 'publish-embedding':
            if self.fail:
                raise p.ServiceUnavailable('Publisher offline')
            self.published.append(body)
            return {'stored': True}
        raise AssertionError(path)


def embedding_config():
    return {
        'analysis_enabled': False,
        'user_id': 'synthetic',
        'folders': ['/'],
        'exclude_folders': [],
        'max_download_bytes': 67108864,
        'max_video_download_bytes': 536870912,
        'max_image_pixels': 80000000,
        'embedding': {
            'enabled': True,
            'location': 'external',
            'url': 'http://127.0.0.1:9379/v1',
            'transport': 'multimodal',
            'family': 'gemma2',
            'model': 'embeddinggemma-2',
            'max_edge': 512,
            'video_frames': 3,
            'timeout_seconds': 15,
            'batch_size': 4,
        },
    }


class ActivityReportingTests(unittest.TestCase):
    def setUp(self):
        SyntheticBatchClient.calls = []
        SyntheticBatchClient.current_during_images = []
        SyntheticBatchClient.disk_during_images = []

    def test_four_photo_batch_displays_all_files_during_client_images(self):
        """Verifies that during batch inference, current.files contains all 4 files."""
        with tempfile.TemporaryDirectory() as tmp:
            directory = Path(tmp)
            reporter = w.Reporter(directory, kind='embeddings')
            try:
                setting = embedding_config()
                setting['embedding']['batch_size'] = 4
                source = SyntheticSource(4)
                api = SyntheticAPI()

                def client_factory(conf):
                    return SyntheticBatchClient(conf, reporter=reporter, directory=directory)

                with patch.object(e, 'Client', client_factory):
                    result = e.run(setting, source, api, directory, reporter.event, lambda: False)

                self.assertEqual(result['processed'], 4)
                self.assertEqual(SyntheticBatchClient.calls, [4])
                self.assertEqual(len(SyntheticBatchClient.current_during_images), 1)

                current = SyntheticBatchClient.current_during_images[0]
                self.assertIsNotNone(current, "current should not be None during client.images")
                self.assertEqual(current.get('stage'), 'embedding')

                files = current.get('files')
                self.assertIsInstance(files, list, "current.files must be a list")
                self.assertEqual(len(files), 4, "current.files must contain exactly 4 files")
                self.assertEqual([f['file_id'] for f in files], ['0', '1', '2', '3'])
                self.assertEqual([f['path'] for f in files], ['0.png', '1.png', '2.png', '3.png'])

                # Also verify started_at is present and valid
                started_at = current.get('started_at')
                self.assertIsInstance(started_at, (int, float))
                self.assertGreater(started_at, 0)

                # Verify disk state matched in status.json
                disk_current = SyntheticBatchClient.disk_during_images[0]
                self.assertIsNotNone(disk_current)
                self.assertEqual(len(disk_current.get('files', [])), 4)

                # After completion, current should be cleared
                self.assertIsNone(reporter.value.get('current'))
            finally:
                reporter.close()

    def test_current_started_at_stable_during_inference_progress_and_heartbeats(self):
        """current.started_at must remain constant across inference_progress and heartbeats."""
        with tempfile.TemporaryDirectory() as tmp:
            directory = Path(tmp)
            reporter = w.Reporter(directory, kind='embeddings')
            try:
                batch_files = [{'file_id': str(i), 'path': f'{i}.png'} for i in range(4)]
                reporter.event('embedding_batch_start', stage='embedding', files=batch_files)

                current_initial = dict(reporter.value.get('current') or {})
                initial_started = current_initial.get('started_at')
                self.assertIsNotNone(initial_started)
                self.assertEqual(len(current_initial.get('files', [])), 4)

                time.sleep(0.01)

                # Simulating inference progress updates
                reporter.event(
                    'inference_progress',
                    path='0.png',
                    file_id='0',
                    stage='embedding',
                    attempt=1,
                    total_attempts=2,
                    reasoning_chars=2048,
                    content_chars=512,
                )

                current_after_progress = reporter.value.get('current')
                self.assertIsNotNone(current_after_progress)
                self.assertEqual(
                    current_after_progress.get('started_at'),
                    initial_started,
                    "started_at must remain stable during inference_progress",
                )
                self.assertEqual(
                    current_after_progress.get('files'),
                    batch_files,
                    "files array must be preserved during inference_progress",
                )
                self.assertEqual(current_after_progress.get('reasoning_chars'), 2048)

                # Simulating attempt start
                time.sleep(0.01)
                reporter.event(
                    'inference_attempt_start',
                    path='0.png',
                    file_id='0',
                    stage='embedding',
                    attempt=2,
                    total_attempts=2,
                )
                current_after_attempt = reporter.value.get('current')
                self.assertEqual(
                    current_after_attempt.get('started_at'),
                    initial_started,
                    "started_at must remain stable during inference_attempt_start",
                )

                # Verify heartbeat does not reset started_at or current
                time.sleep(0.01)
                reporter.stop.set()
                reporter.thread.join()
                reporter.stop.clear()
                with patch.object(reporter.stop, 'wait', side_effect=lambda seconds: reporter.stop.set()):
                    reporter.beat()
                current_after_beat = reporter.value.get('current')
                self.assertEqual(current_after_beat.get('started_at'), initial_started)

                # A true new phase / stage restart should update started_at
                time.sleep(0.02)
                reporter.event('stage_start', path='0.png', file_id='0', stage='publishing')
                new_current = reporter.value.get('current')
                self.assertEqual(new_current.get('stage'), 'publishing')
                self.assertGreaterEqual(
                    new_current.get('started_at'),
                    initial_started,
                )
            finally:
                reporter.close()

    def test_embedding_stats_does_not_sum_old_analysis_stats(self):
        """In embedding lanes, reporter stats must not add old analysis_stats."""
        with tempfile.TemporaryDirectory() as tmp:
            directory = Path(tmp)
            reporter = w.Reporter(directory, kind='embeddings')
            try:
                # Seed old analysis_stats as if from a legacy combined status
                reporter.value['analysis_stats'] = {'done': 500, 'pending': 100, 'error': 25}

                # Emit embedding stats
                reporter.event('embedding_stats_update', embedding_stats={'done': 10, 'pending': 2, 'error': 1})

                # In kind='embeddings', stats must match embedding_stats directly
                self.assertEqual(
                    reporter.value.get('stats'),
                    {'done': 10, 'pending': 2, 'error': 1},
                    "embedding lane must not accumulate old analysis_stats into stats",
                )
                self.assertEqual(
                    reporter.value.get('embedding_stats'),
                    {'done': 10, 'pending': 2, 'error': 1},
                )
            finally:
                reporter.close()

    def test_batch_tail_and_variable_batch_sizes(self):
        """Batch with 6 photos and batch_size 4 produces a 4-file batch then a 2-file batch."""
        with tempfile.TemporaryDirectory() as tmp:
            directory = Path(tmp)
            reporter = w.Reporter(directory, kind='embeddings')
            try:
                setting = embedding_config()
                setting['embedding']['batch_size'] = 4
                source = SyntheticSource(6)
                api = SyntheticAPI()

                def client_factory(conf):
                    return SyntheticBatchClient(conf, reporter=reporter, directory=directory)

                with patch.object(e, 'Client', client_factory):
                    result = e.run(setting, source, api, directory, reporter.event, lambda: False)

                self.assertEqual(result['processed'], 6)
                self.assertEqual(SyntheticBatchClient.calls, [4, 2])
                self.assertEqual(len(SyntheticBatchClient.current_during_images), 2)

                # First batch: 4 files
                first_batch = SyntheticBatchClient.current_during_images[0]
                self.assertEqual(len(first_batch.get('files', [])), 4)
                self.assertEqual([f['file_id'] for f in first_batch['files']], ['0', '1', '2', '3'])

                # Second batch: 2 files
                second_batch = SyntheticBatchClient.current_during_images[1]
                self.assertEqual(len(second_batch.get('files', [])), 2)
                self.assertEqual([f['file_id'] for f in second_batch['files']], ['4', '5'])
            finally:
                reporter.close()

    def test_ocr_pipeline_reporting_and_error_handling(self):
        """Verifies OCR lane reporting, stats isolation, and error logging."""
        with tempfile.TemporaryDirectory() as tmp:
            directory = Path(tmp)
            reporter = w.Reporter(directory, kind='ocr')
            try:
                reporter.event('file_start', path='scan.png', file_id='99', stage='download')
                current = reporter.value.get('current')
                self.assertIsNotNone(current)
                self.assertEqual(current.get('stage'), 'download')
                initial_started = current.get('started_at')

                reporter.event('stage_start', path='scan.png', file_id='99', stage='ocr')
                current_ocr = reporter.value.get('current')
                self.assertEqual(current_ocr.get('stage'), 'ocr')

                # Stats reporting for OCR
                reporter.event('ocr_progress', stats={'done': 3, 'pending': 7, 'error': 1})
                self.assertEqual(reporter.value.get('ocr_stats'), {'done': 3, 'pending': 7, 'error': 1})
                self.assertEqual(reporter.value.get('stats'), {'done': 3, 'pending': 7, 'error': 1})

                # Error event on file failure
                reporter.event(
                    'file_error',
                    level='error',
                    path='scan.png',
                    file_id='99',
                    stage='ocr',
                    error='OCR model timeout',
                    reason='OCR model timeout',
                    stats={'done': 3, 'pending': 6, 'error': 2},
                )

                # Verify error row logged to jsonl
                log_files = list((directory / 'logs').glob('*.jsonl'))
                self.assertEqual(len(log_files), 1)
                log_entries = [json.loads(line) for line in log_files[0].read_text().splitlines()]
                error_entries = [r for r in log_entries if r.get('event') == 'file_error']
                self.assertEqual(len(error_entries), 1)
                self.assertEqual(error_entries[0].get('error'), 'OCR model timeout')
                self.assertEqual(error_entries[0].get('reason'), 'OCR model timeout')
                self.assertEqual(error_entries[0].get('kind'), 'ocr')

                # After error, current should be cleared
                self.assertIsNone(reporter.value.get('current'))
            finally:
                reporter.close()

    def test_runtime_seconds_in_run_finished(self):
        """Measure a real cycle using a controlled monotonic clock."""
        with tempfile.TemporaryDirectory() as tmp:
            directory = Path(tmp)
            reporter = w.Reporter(directory, kind='embeddings')
            try:
                setting = embedding_config()
                setting['embedding']['enabled'] = False
                w.atomic(w.lane_file(directory, 'control.json', 'embeddings'), {'paused': False})
                with patch.object(w, 'NativeSource', return_value=SyntheticSource(0)), patch.object(w.time, 'monotonic', side_effect=[100.0, 112.25]):
                    result = w.cycle(SyntheticAPI(), setting, directory, reporter, {'run_kind': 'embeddings'})
                self.assertEqual(result['seconds'], 12.25)
                entries = [json.loads(line) for path in (directory / 'logs').glob('*.jsonl') for line in path.read_text().splitlines()]
                finished = [row for row in entries if row['event'] == 'run_finished']
                self.assertEqual(finished[0]['seconds'], 12.25)
                self.assertEqual(reporter.value['last_run']['seconds'], 12.25)
            finally:
                reporter.close()


if __name__ == '__main__':
    unittest.main()
