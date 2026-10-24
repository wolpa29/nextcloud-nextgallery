"""Independent, bounded video speech transcription. Originals are read-only.

Only this lane uses faster-whisper; inference executes in a cancellable child so
pause, deadlines and failures never stop another pipeline. No media is uploaded.
"""
from __future__ import annotations
import argparse
from contextlib import contextmanager
import hashlib
import json
import math
import os
from pathlib import Path
import signal
import sqlite3
import subprocess
import sys
import tempfile
import time
import media_pipeline as p

MAX_DURATION = 14400  # Four hours; longer inputs fail visibly rather than truncate.
MAX_TEXT = 500000
MAX_SEGMENTS = 20000


def revision(config):
    return p.digest(['audio-v1', config.get('audio', {}), 'faster-whisper-1.2.1'])


def execute(command, stop, deadline, directory):
    """Keep diagnostics on private disk and kill the whole decoder on pause."""
    with tempfile.TemporaryFile(dir=directory) as output:
        child = subprocess.Popen(command, stdout=output, stderr=subprocess.STDOUT, start_new_session=True)
        try:
            while child.poll() is None:
                if stop(): raise p.PipelineError('Audio processing paused')
                if time.monotonic() >= deadline: raise p.PipelineError('Audio processing time budget exceeded')
                time.sleep(.2)
            if child.returncode:
                output.seek(0, 2); size = output.tell(); output.seek(max(0, size - 2000))
                detail = output.read().decode('utf-8', 'replace')[-1500:]
                if 'AUDIO_SERVICE_UNAVAILABLE' in detail or child.returncode < 0:
                    raise p.ServiceUnavailable('Audio model unavailable or stopped by resource limits: ' + detail[-800:])
                raise p.PipelineError('Audio decoder failed: ' + detail)
        finally:
            if child.poll() is None:
                try: os.killpg(child.pid, signal.SIGTERM); child.wait(timeout=3)
                except subprocess.TimeoutExpired: os.killpg(child.pid, signal.SIGKILL); child.wait()
                except ProcessLookupError: pass


def probe(path, stop, deadline, directory):
    result = Path(directory) / 'probe.json'
    # ffprobe output is bounded to selected scalar fields, never full packets/tags.
    with result.open('wb') as output:
        child = subprocess.Popen(['ffprobe', '-v', 'error', '-select_streams', 'a:0', '-show_entries', 'stream=index:format=duration', '-of', 'json', str(path)], stdout=output, stderr=subprocess.DEVNULL)
        try:
            while child.poll() is None:
                if stop() or time.monotonic() >= deadline: raise p.PipelineError('Audio probe cancelled or timed out')
                time.sleep(.1)
            if child.returncode: raise p.PipelineError('Video audio stream could not be inspected')
        finally:
            if child.poll() is None: child.kill(); child.wait()
    data = json.loads(result.read_text()); duration = float(data.get('format', {}).get('duration') or 0)
    if not math.isfinite(duration) or duration < 0: raise p.PipelineError('Invalid video duration')
    if data.get('streams') and duration <= 0: raise p.PipelineError('Audio duration unavailable; refusing an unbounded extraction')
    if duration > MAX_DURATION: raise p.PipelineError('Videoton unterstützt bis zu vier Stunden pro Video; Datei wurde nicht gekürzt')
    return bool(data.get('streams')), duration


def transcribe(path, config, directory, stop, observe):
    settings = config['audio']; deadline = time.monotonic() + int(settings.get('timeout_seconds', 3600))
    has_audio, duration = probe(path, stop, deadline, directory)
    if not has_audio: return {'text': '', 'segments': [], 'language': '', 'duration': duration, 'model': settings['model'], 'no_audio': True}
    wave = Path(directory) / 'audio.wav'
    observe('audio_extracting', duration=duration)
    execute(['ffmpeg', '-nostdin', '-v', 'error', '-i', str(path), '-map', '0:a:0', '-vn', '-ac', '1', '-ar', '16000', '-c:a', 'pcm_s16le', '-y', str(wave)], stop, deadline, directory)
    request = Path(directory) / 'request.json'; response = Path(directory) / 'transcript.json'
    request.write_text(json.dumps({'settings': settings, 'wave': str(wave), 'output': str(response), 'cache': str(Path(config['_model_directory']) / 'audio-local/models')}))
    import audio_setup
    runtime = audio_setup.python_path(config['_model_directory'])
    observe('audio_transcribing', duration=duration)
    execute([str(runtime), str(Path(__file__).resolve()), '--request', str(request)], stop, deadline, directory)
    if not response.is_file() or response.stat().st_size > 1000000: raise p.PipelineError('Audio transcript response too large or missing')
    data = json.loads(response.read_text()); data['duration'] = duration; data['no_audio'] = False
    return data


def run(config, source, api, directory, observe, stop, retry_errors=False):
    directory = Path(directory)
    db = sqlite3.connect(directory / 'audio.sqlite3')
    db.row_factory = sqlite3.Row
    db.execute('CREATE TABLE IF NOT EXISTS files (key TEXT PRIMARY KEY,file_id INTEGER NOT NULL,etag TEXT NOT NULL,status TEXT NOT NULL,metadata TEXT,error TEXT)')
    try:
        if retry_errors:
            with db: db.execute("UPDATE files SET status='pending',error=NULL WHERE status='error'")
        items = [item for item in source.inventory() if item.mime.startswith('video/')]
        stats = {'done': 0, 'pending': 0, 'error': 0}; pending = []
        for item in items:
            row = db.execute('SELECT * FROM files WHERE key=?', (item.key,)).fetchone()
            if item.analyzed_etag:
                stats['done'] += 1; continue  # Persisted analyses survive file/model changes.
            if row and row['etag'] == item.etag and row['status'] == 'error' and not retry_errors:
                stats['error'] += 1; continue
            pending.append(item); stats['pending'] += 1
        observe('audio_inventory', **stats)
        processed = 0
        for item in pending:
            if stop(): break
            observe('file_start', path=item.path, file_id=int(item.locator), stage='audio')
            try:
                cached = db.execute('SELECT * FROM files WHERE key=? AND etag=? AND status=?', (item.key, item.etag, 'ready')).fetchone()
                if cached:
                    payload = json.loads(cached['metadata'])
                    if not source.unchanged(item): raise p.PipelineError('Video changed before transcript publication')
                    api.call('publish', payload)
                else:
                    with tempfile.TemporaryDirectory(prefix='audio-', dir=directory) as temp:
                        with source.embedding_video(item, Path(temp) / 'video', config['max_video_download_bytes']) as local:
                            audio = transcribe(local, config, temp, stop, lambda event, **fields: observe(event, path=item.path, file_id=int(item.locator), **fields))
                            sha = hashlib.sha256()
                            with Path(local).open('rb') as stream:
                                for block in iter(lambda: stream.read(1024 * 1024), b''):
                                    if stop(): raise p.PipelineError('Audio processing paused')
                                    sha.update(block)
                        if stop(): break
                        if not source.unchanged(item): raise p.PipelineError('Video changed during audio analysis')
                        payload = {'file_id': int(item.locator), 'etag': item.etag, 'sha256': sha.hexdigest(), 'revision': revision(config), 'stage': 'audio', 'metadata': {'audio': audio}, 'exif': {}}
                        with db: db.execute('INSERT OR REPLACE INTO files VALUES (?,?,?,?,?,?)', (item.key, int(item.locator), item.etag, 'ready', json.dumps(payload, ensure_ascii=False), None))
                        api.call('publish', payload)
                stats['done'] += 1; stats['pending'] -= 1; processed += 1
                observe('audio_processed', path=item.path, file_id=int(item.locator))
            except p.ServiceUnavailable:
                raise  # An unavailable model must not mark the entire inventory as bad media.
            except Exception as error:
                if stop(): break
                # Keep ready results after a publication failure, so retry never re-runs ASR.
                with db:
                    cached = db.execute('SELECT status FROM files WHERE key=? AND etag=?', (item.key, item.etag)).fetchone()
                    if cached and cached['status'] == 'ready': db.execute('UPDATE files SET error=? WHERE key=?', (str(error)[:1000], item.key))
                    else: db.execute('INSERT OR REPLACE INTO files VALUES (?,?,?,?,?,?)', (item.key, int(item.locator), item.etag, 'error', None, str(error)[:1000]))
                stats['error'] += 1; stats['pending'] -= 1
                observe('audio_error', path=item.path, file_id=int(item.locator), error=str(error)[:1000])
        return {'state': 'paused' if stop() else 'finished', 'stats': stats, 'processed': processed}
    finally: db.close()


def infer(request):
    os.environ['HF_HOME'] = str(Path(request['cache']).parent / 'hf')
    os.environ['HF_HUB_DISABLE_TELEMETRY'] = '1'
    try:
        from faster_whisper import WhisperModel
        from huggingface_hub import snapshot_download
        settings = request['settings']
        manifest = json.loads(Path(__file__).with_name('audio_models.json').read_text())
        pinned = manifest[settings['model']]
        model_path = snapshot_download(pinned['repository'], revision=pinned['revision'], cache_dir=request['cache'], allow_patterns=['config.json','preprocessor_config.json','model.bin','tokenizer.json','vocabulary.*'])
        model = WhisperModel(model_path, device=settings.get('device', 'cpu'), compute_type=settings.get('compute_type', 'int8'), cpu_threads=int(settings.get('cpu_threads', 4)), download_root=request['cache'])
    except Exception as error:
        print('AUDIO_SERVICE_UNAVAILABLE: '+str(error), flush=True)
        raise
    segments, info = model.transcribe(request['wave'], language=settings.get('language') or None, vad_filter=True, beam_size=5, condition_on_previous_text=False, no_speech_threshold=.6, hallucination_silence_threshold=2)
    result = []; length = 0
    for segment in segments:
        text = segment.text.strip()
        if not text: continue
        length += len(text.encode('utf-8')) + 1
        if length > MAX_TEXT or len(result) >= MAX_SEGMENTS: raise p.PipelineError('Transcript exceeds safe storage limit; no partial result was published')
        result.append({'start': round(segment.start, 3), 'end': round(segment.end, 3), 'text': text})
    data = {'text': ' '.join(part['text'] for part in result), 'segments': result, 'language': info.language, 'model': settings['model']}
    output = Path(request['output']); output.write_text(json.dumps(data, ensure_ascii=False))
    if output.stat().st_size > 950000: output.unlink(); raise p.PipelineError('Transcript segments exceed storage limit')


if __name__ == '__main__':
    os.umask(0o077)
    parser = argparse.ArgumentParser(); parser.add_argument('--request', required=True)
    infer(json.loads(Path(parser.parse_args().request).read_text()))
