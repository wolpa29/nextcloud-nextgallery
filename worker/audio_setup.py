"""Prepare a private ASR runtime only after the administrator enables audio."""
from __future__ import annotations
import argparse
import hashlib
import json
import os
from pathlib import Path
import signal
import subprocess
import sys
import time
import uuid
import venv

REQUIREMENTS = Path(__file__).with_name('requirements-audio.txt')
_process = None
_retry_at = 0.0


def atomic(path, value):
    path = Path(path)
    temp = path.with_name(path.name + '.' + uuid.uuid4().hex)
    temp.write_text(json.dumps(value, ensure_ascii=False)); temp.chmod(0o600); temp.replace(path)


def read(path):
    try: return json.loads(Path(path).read_text())
    except (OSError, ValueError): return {}


def python_path(directory):
    return Path(directory) / 'audio-local/runtime/bin/python'


def identity():
    return hashlib.sha256(REQUIREMENTS.read_bytes() + REQUIREMENTS.with_name('requirements.txt').read_bytes()).hexdigest()


def tick(directory, requested=False):
    global _process, _retry_at
    root = Path(directory) / 'audio-local'
    ready = read(root / 'ready.json')
    if python_path(directory).is_file() and ready.get('identity') == identity():
        return {'installed': True, 'state': 'ready'}
    status = read(root / 'setup.json')
    if _process is not None:
        if _process.poll() is None: return {'installed': False, **status, 'state': 'installing'}
        _process = None; _retry_at = time.monotonic() + 300
        ready = read(root / 'ready.json')
        if python_path(directory).is_file() and ready.get('identity') == identity(): return {'installed': True, 'state': 'ready'}
    if requested and time.monotonic() >= _retry_at:
        root.mkdir(mode=0o700, parents=True, exist_ok=True)
        atomic(root / 'setup.json', {'state': 'installing', 'message': 'Lokale Spracherkennung wird eingerichtet'})
        with (root / 'setup.log').open('a') as log:
            _process = subprocess.Popen([sys.executable, str(Path(__file__).resolve()), '--directory', str(directory)], stdout=log, stderr=log, start_new_session=True)
        return {'installed': False, 'state': 'installing'}
    return {'installed': False, **status, 'state': status.get('state', 'not_installed')}


def stop():
    global _process
    if _process is not None and _process.poll() is None:
        try: os.killpg(_process.pid, signal.SIGTERM); _process.wait(timeout=5)
        except subprocess.TimeoutExpired: os.killpg(_process.pid, signal.SIGKILL); _process.wait()
        except ProcessLookupError: pass
    _process = None


def install(directory):
    import fcntl
    root = Path(directory) / 'audio-local'; root.mkdir(mode=0o700, parents=True, exist_ok=True)
    with (root / 'setup.lock').open('a') as lock:
        fcntl.flock(lock, fcntl.LOCK_EX)
        try:
            runtime = root / 'runtime'
            if not python_path(directory).is_file(): venv.EnvBuilder(with_pip=True).create(runtime)
            subprocess.run([str(python_path(directory)), '-m', 'pip', 'install', '--disable-pip-version-check', 'pip==26.2.1'], check=True, timeout=600)
            subprocess.run([str(python_path(directory)), '-m', 'pip', 'install', '--disable-pip-version-check', '-r', str(REQUIREMENTS)], check=True, timeout=1800)
            subprocess.run([str(python_path(directory)), '-c', 'from faster_whisper import WhisperModel; import PIL'], check=True, timeout=60)
            atomic(root / 'ready.json', {'identity': identity()})
            atomic(root / 'setup.json', {'state': 'ready', 'installed': True})
        except Exception as error:
            atomic(root / 'setup.json', {'state': 'error', 'message': str(error)[-500:]}); raise


if __name__ == '__main__':
    parser = argparse.ArgumentParser(); parser.add_argument('--directory', required=True)
    install(parser.parse_args().directory)
