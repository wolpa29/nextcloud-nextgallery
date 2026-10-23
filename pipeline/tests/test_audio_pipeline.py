"""Synthetic fifth-lane contracts: migration, publication retry and cancellation."""
from contextlib import contextmanager
import json
from pathlib import Path
import subprocess
import sys
import tempfile
import time
import unittest
from unittest.mock import patch

sys.path.insert(0, str(Path(__file__).resolve().parents[2] / 'worker'))
import app_worker as worker
import media_ai_audio as audio
import media_pipeline as p


class AudioMigrationTests(unittest.TestCase):
    def test_version_three_preserves_four_existing_lanes_byte_for_byte(self):
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary); worker.atomic(root/'pipelines.json', {'version': 3})
            preserved = {}
            for lane in ('descriptions', 'ocr', 'embeddings', 'faces'):
                for name in ('control', 'schedule', 'status'):
                    path = root/f'{name}-{lane}.json'; worker.atomic(path, {'paused': False, 'requested': True, 'marker': lane}); preserved[path] = path.read_bytes()
                path = root/f'{lane}.sqlite3'; path.write_bytes(b'original preserved'); preserved[path] = path.read_bytes()
            worker.initialize_pipelines(root)
            self.assertEqual(worker.read(root/'pipelines.json', {})['version'], 4)
            self.assertEqual(worker.read(root/'control-audio.json', {}), {'paused': True, 'requested': False, 'run_kind': 'audio'})
            for path, wire in preserved.items(): self.assertEqual(path.read_bytes(), wire)
            worker.atomic(root/'control-audio.json', {'paused': False, 'requested': True})
            worker.initialize_pipelines(root)
            self.assertFalse(worker.read(root/'control-audio.json', {})['paused'])

    def test_supervisor_detects_an_independently_started_lane(self):
        import fcntl
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            self.assertFalse(worker.lane_process_active(root, 'audio'))
            with (root/'daemon-audio.lock').open('a') as lock:
                fcntl.flock(lock, fcntl.LOCK_EX)
                self.assertTrue(worker.lane_process_active(root, 'audio'))
                self.assertFalse(worker.lane_process_active(root, 'faces'))

    def test_cancel_kills_inference_without_waiting_for_deadline(self):
        with tempfile.TemporaryDirectory() as temporary:
            start = time.monotonic()
            with self.assertRaisesRegex(p.PipelineError, 'paused'):
                audio.execute([sys.executable, '-c', 'import time;time.sleep(30)'], lambda: True, time.monotonic()+60, temporary)
            self.assertLess(time.monotonic()-start, 3)

    def test_deadline_kills_inference(self):
        with tempfile.TemporaryDirectory() as temporary:
            with self.assertRaisesRegex(p.PipelineError, 'budget'):
                audio.execute([sys.executable, '-c', 'import time;time.sleep(30)'], lambda: False, time.monotonic()-1, temporary)

    def test_no_audio_never_loads_model(self):
        with tempfile.TemporaryDirectory() as temporary:
            with patch.object(audio, 'probe', return_value=(False, 2)), patch.object(audio, 'execute') as execute:
                result = audio.transcribe(Path('synthetic.mp4'), {'audio': {'model': 'small'}}, temporary, lambda: False, lambda *args, **kw: None)
            self.assertTrue(result['no_audio']); self.assertEqual(result['text'], ''); execute.assert_not_called()

    def test_failed_publication_retries_saved_transcript_without_inference(self):
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary); original = root/'synthetic.mp4'; original.write_bytes(b'synthetic nonprivate video')
            item = p.Item('scope:1', 'Videos/test.mp4', '1', 'etag', original.stat().st_size, 'video/mp4', '')
            class Source:
                def inventory(self): return [item]
                def unchanged(self, value): return True
                @contextmanager
                def embedding_video(self, *args): yield original
            class API:
                def __init__(self): self.calls = 0; self.payloads = []
                def call(self, endpoint, payload):
                    self.calls += 1
                    if self.calls == 1: raise p.PipelineError('synthetic unavailable')
                    self.payloads.append(payload)
            api = API(); config = {'audio': {'model': 'small'}, 'max_video_download_bytes': 1000}
            transcript = {'text': 'pineapple', 'language': 'en', 'segments': [{'start': 0, 'end': 1, 'text': 'pineapple'}], 'duration': 1, 'model': 'small', 'no_audio': False}
            with patch.object(audio, 'transcribe', return_value=transcript) as infer:
                first = audio.run(config, Source(), api, root, lambda *args, **kw: None, lambda: False)
                self.assertEqual(first['stats']['error'], 1)
                second = audio.run(config, Source(), api, root, lambda *args, **kw: None, lambda: False)
                self.assertEqual(second['stats']['done'], 1); self.assertEqual(infer.call_count, 1)
                self.assertEqual(api.payloads[0]['stage'], 'audio')

    def test_model_outage_does_not_mark_video_as_failed(self):
        with tempfile.TemporaryDirectory() as temporary:
            original = Path(temporary)/'synthetic.mp4';original.write_bytes(b'synthetic')
            item = p.Item('scope:1','test.mp4','1','etag',9,'video/mp4','')
            class Source:
                def inventory(self): return [item]
                @contextmanager
                def embedding_video(self,*args): yield original
            with patch.object(audio,'transcribe',side_effect=p.ServiceUnavailable('synthetic model offline')):
                with self.assertRaises(p.ServiceUnavailable):
                    audio.run({'audio':{},'max_video_download_bytes':100},Source(),None,temporary,lambda *a,**k:None,lambda:False)
            import sqlite3
            with sqlite3.connect(Path(temporary)/'audio.sqlite3') as db:
                self.assertEqual(db.execute('SELECT COUNT(*) FROM files').fetchone()[0],0)

    def test_completed_server_transcript_is_never_reanalyzed(self):
        with tempfile.TemporaryDirectory() as temporary:
            item = p.Item('scope:1', 'a.mp4', '1', 'changed-etag', 1, 'video/mp4', 'old-etag')
            class Source:
                def inventory(self): return [item]
            with patch.object(audio, 'transcribe') as infer:
                result = audio.run({'audio': {}}, Source(), None, temporary, lambda *args, **kw: None, lambda: False)
            self.assertEqual(result['stats'], {'done': 1, 'pending': 0, 'error': 0}); infer.assert_not_called()


if __name__ == '__main__': unittest.main()
