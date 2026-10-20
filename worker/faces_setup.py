"""Explicit face recognition setup in the worker's private state directory.

Installs OpenCV YuNet and SFace in an isolated virtualenv under faces-local/.
The base worker remains lightweight and does not require cv2.
"""
from __future__ import annotations

import argparse
import errno
import fcntl
import hashlib
import json
import os
import re
from pathlib import Path
import shutil
import signal
import subprocess
import sys
import time
import urllib.error
import urllib.parse
import urllib.request
import uuid
import venv

MODELS_MANIFEST = Path(__file__).with_name('faces_models.json')
REQUIREMENTS = Path(__file__).with_name('requirements-faces.txt')
BASE_REQUIREMENTS = Path(__file__).with_name('requirements.txt')
PIP_VERSION = '26.2.1'

_process = None
_identity = None
_retry_at = 0.0
_request_id = None


def atomic(path: Path, value: dict):
    path = Path(path)
    temp = path.with_name(path.name + '.' + uuid.uuid4().hex)
    temp.write_text(json.dumps(value, ensure_ascii=False))
    temp.chmod(0o600)
    temp.replace(path)


def read(path: Path, fallback=None):
    try:
        return json.loads(Path(path).read_text())
    except (OSError, ValueError):
        return fallback if fallback is not None else {}


def manifest() -> dict:
    return read(MODELS_MANIFEST)


def paths(directory: Path | str) -> tuple[Path, Path, Path]:
    root = Path(directory) / 'faces-local'
    python = root / 'runtime' / 'bin' / 'python'
    models_dir = root / 'models'
    return root, python, models_dir


def python_path(directory: Path | str) -> Path:
    return paths(directory)[1]


def model_paths(directory: Path | str) -> dict[str, Path]:
    _, _, models_dir = paths(directory)
    data = manifest()
    return {
        'detector': models_dir / data['detector']['name'],
        'recognizer': models_dir / data['recognizer']['name'],
    }


def identity() -> str:
    parts = [
        f"{sys.version_info.major}.{sys.version_info.minor}",
        hashlib.sha256(REQUIREMENTS.read_bytes() + BASE_REQUIREMENTS.read_bytes()).hexdigest(),
        hashlib.sha256(MODELS_MANIFEST.read_bytes()).hexdigest(),
    ]
    return '|'.join(parts)


def digest(path: Path) -> str:
    sha = hashlib.sha256()
    with path.open('rb') as stream:
        for block in iter(lambda: stream.read(1024 * 1024), b''):
            sha.update(block)
    return sha.hexdigest()


def cached(directory: Path | str) -> bool:
    root, python, models_dir = paths(directory)
    try:
        data = manifest()
        ready = read(root / 'ready.json')
        if ready.get('identity') != identity() or not python.is_file():
            return False
        det = models_dir / data['detector']['name']
        rec = models_dir / data['recognizer']['name']
        if not (det.is_file() and det.stat().st_size == data['detector']['bytes']):
            return False
        if not (rec.is_file() and rec.stat().st_size == data['recognizer']['bytes']):
            return False
        return True
    except OSError:
        return False


def stop():
    global _process, _identity, _retry_at, _request_id
    if _process is not None and _process.poll() is None:
        try:
            os.killpg(_process.pid, signal.SIGTERM)
            _process.wait(timeout=5)
        except (subprocess.TimeoutExpired, ProcessLookupError, PermissionError):
            try:
                os.killpg(_process.pid, signal.SIGKILL)
                _process.wait(timeout=5)
            except Exception:
                pass
    _process = None
    _identity = None
    _retry_at = 0.0
    _request_id = None


def clear_request(directory: Path | str):
    directory = Path(directory)
    install_file = directory / 'faces-install.json'
    if not install_file.is_file():
        return
    with (directory / 'control.lock').open('a') as lock:
        fcntl.flock(lock, fcntl.LOCK_EX)
        value = read(install_file)
        if value.get('requested'):
            value['requested'] = False
            atomic(install_file, value)


def run_setup(command: list[str], timeout: int):
    result = subprocess.run(command, stdout=subprocess.PIPE, stderr=subprocess.STDOUT,
                            text=True, timeout=timeout)
    if result.stdout:
        print(result.stdout, end='', flush=True)
    if result.returncode:
        raise subprocess.CalledProcessError(result.returncode, command, output=result.stdout)


def setup_failure(error: Exception, step: str) -> dict:
    output = getattr(error, 'output', '') or ''
    if isinstance(output, bytes):
        output = output.decode('utf-8', errors='replace')
    code, reason = 'setup_failed', 'Einrichtung fehlgeschlagen.'
    if (isinstance(error, OSError) and error.errno == errno.ENOSPC) or 'No space left on device' in output:
        code, reason = 'disk_full', 'Nicht genügend freier Speicher für die Installation.'
    elif isinstance(error, PermissionError):
        code, reason = 'permission_denied', 'Der Worker darf nicht in das Installationsverzeichnis schreiben.'
    elif isinstance(error, subprocess.TimeoutExpired):
        code, reason = 'timeout', 'Zeitlimit für diesen Installationsschritt überschritten.'
    elif isinstance(error, urllib.error.HTTPError):
        code, reason = 'download_http_error', f'Modelldownload vom Server abgelehnt (HTTP {error.code}).'
    elif isinstance(error, urllib.error.URLError) or any(x in output for x in ('ConnectionError', 'Connection broken', 'Failed to establish a new connection', 'Name or service not known', 'CERTIFICATE_VERIFY_FAILED')):
        code, reason = 'network_error', 'Download fehlgeschlagen: Verbindung, DNS oder TLS-Zertifikat prüfen.'
    elif 'No matching distribution found for ' in output:
        code, reason = 'package_unavailable', 'Für diese Python-Version oder Plattform fehlt ein passendes Binärpaket.'
        package = re.search(r'No matching distribution found for ([A-Za-z0-9][A-Za-z0-9_.-]{0,80})', output)
        if package:
            reason += f' Paket: {package.group(1)}.'
    elif isinstance(error, ValueError) and 'checksum' in str(error).lower():
        code, reason = 'model_checksum_mismatch', 'Das heruntergeladene Modell ist unvollständig oder seine Prüfsumme stimmt nicht.'
    elif step == 'Python-Umgebung':
        code, reason = 'python_environment_failed', 'Python-Umgebung konnte nicht erstellt werden. venv/ensurepip-Unterstützung prüfen.'
    elif isinstance(error, subprocess.CalledProcessError):
        reason = f'Installationsprogramm mit Fehlercode {error.returncode} beendet.'
    return {
        'error_code': code,
        'failed_step': step,
        'message': f'{step}: {reason} Erneuter Versuch nach fünf Minuten oder über „Installation erneut versuchen“.'
    }


class HTTPSRedirects(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, req, fp, code, msg, headers, newurl):
        if urllib.parse.urlsplit(newurl).scheme != 'https':
            raise ValueError('Model redirect must use HTTPS')
        return super().redirect_request(req, fp, code, msg, headers, newurl)


def download_model(target: Path, url: str, expected_bytes: int, expected_sha256: str, label: str, update):
    if target.is_file() and target.stat().st_size == expected_bytes and digest(target) == expected_sha256:
        return target
    target.parent.mkdir(parents=True, exist_ok=True)
    temp = target.with_name(target.name + '.download')
    started = time.monotonic()
    last = 0.0
    received = 0
    sha = hashlib.sha256()
    update(phase='downloading', message=f'{label} wird heruntergeladen …', received_bytes=0, total_bytes=expected_bytes)
    try:
        opener = urllib.request.build_opener(HTTPSRedirects())
        with opener.open(urllib.request.Request(url, headers={'User-Agent': 'NextGallery-faces-setup/1'}), timeout=30) as response, temp.open('wb') as output:
            if response.status != 200:
                raise ValueError('Model download response is not a full file')
            for block in iter(lambda: response.read(1024 * 1024), b''):
                received += len(block)
                if received > expected_bytes or time.monotonic() - started > 900:
                    raise ValueError('Model download exceeds size or time budget')
                output.write(block)
                sha.update(block)
                if time.monotonic() - last >= 1.0:
                    update(phase='downloading', message=f'{label} wird heruntergeladen …', received_bytes=received, total_bytes=expected_bytes)
                    last = time.monotonic()
            output.flush()
            os.fsync(output.fileno())
        update(phase='verifying', message=f'{label} wird geprüft …')
        if received != expected_bytes or sha.hexdigest() != expected_sha256:
            raise ValueError(f'{label} checksum mismatch')
        temp.chmod(0o600)
        temp.replace(target)
        return target
    finally:
        temp.unlink(missing_ok=True)


def install(root: Path):
    root = Path(root).resolve()
    root.mkdir(mode=0o700, exist_ok=True)
    state_dir = root.parent

    def update(**fields):
        data = {**fields, 'updated_at': time.time()}
        atomic(root / 'setup.json', data)
        atomic(state_dir / 'faces-setup.json', data)

    with (root / 'setup.lock').open('a') as lock:
        fcntl.flock(lock, fcntl.LOCK_EX)
        step = 'Python-Umgebung'
        try:
            expected = identity()
            update(phase='runtime', message='Python-Laufzeit für Gesichtserkennung wird eingerichtet …')
            runtime = root / 'runtime'
            python = runtime / 'bin' / 'python'
            if read(root / 'runtime.json').get('identity') != expected or not python.is_file():
                venv.EnvBuilder(with_pip=True, symlinks=True).create(runtime)
                step = 'Paketinstaller aktualisieren'
                update(phase='runtime', message='Python-Paketinstaller wird aktualisiert …')
                run_setup([str(python), '-m', 'pip', 'install', '--upgrade', '--only-binary=:all:',
                           '--index-url', 'https://pypi.org/simple', '--disable-pip-version-check',
                           '--no-input', '--no-cache-dir', '--timeout', '30', '--retries', '2',
                           f'pip=={PIP_VERSION}'], timeout=300)
                step = 'Gesichtserkennungs-Pakete installieren'
                update(phase='runtime', message='OpenCV-Pakete werden installiert …')
                run_setup([str(python), '-m', 'pip', 'install', '--only-binary=:all:', '--index-url', 'https://pypi.org/simple',
                           '--disable-pip-version-check', '--no-input', '--no-cache-dir', '--timeout', '30', '--retries', '2',
                           '-r', str(REQUIREMENTS)], timeout=900)
                step = 'Laufzeit prüfen'
                update(phase='runtime', message='Gesichtserkennungs-Laufzeit wird geprüft …')
                run_setup([str(python), '-c', 'import cv2, numpy; from PIL import Image; import pillow_heif'], timeout=60)
                atomic(root / 'runtime.json', {'identity': expected})

            step = 'Modelle herunterladen und prüfen'
            data = manifest()
            models_dir = root / 'models'
            models_dir.mkdir(mode=0o700, exist_ok=True)

            det_info = data['detector']
            det_target = models_dir / det_info['name']
            download_model(det_target, det_info['url'], det_info['bytes'], det_info['sha256'], 'YuNet-Detektor', update)

            rec_info = data['recognizer']
            rec_target = models_dir / rec_info['name']
            download_model(rec_target, rec_info['url'], rec_info['bytes'], rec_info['sha256'], 'SFace-Erkenner', update)

            atomic(root / 'ready.json', {
                'identity': expected,
                'space': data['space'],
                'detector_sha256': det_info['sha256'],
                'recognizer_sha256': rec_info['sha256'],
            })
            update(phase='installed', installed=True, ready=True, message='Gesichtserkennung und Modelle installiert.')
        except Exception as error:
            update(phase='error', installed=False, ready=False, **setup_failure(error, step))
            raise


def tick(directory: Path | str, requested: bool = False) -> dict:
    global _process, _identity, _retry_at, _request_id
    directory = Path(directory)
    root, python, models_dir = paths(directory)
    root.mkdir(mode=0o700, exist_ok=True)
    expected = identity()

    if cached(directory):
        if _process is not None and _process.poll() is not None:
            _process = None
        clear_request(directory)
        status = {'phase': 'installed', 'installed': True, 'ready': True, 'message': 'Gesichtserkennung ist bereit.'}
        atomic(directory / 'faces-setup.json', status)
        return status

    if _identity is not None and _identity != (str(root), expected):
        stop()

    request = read(directory / 'faces-install.json')
    is_requested = requested or bool(request.get('requested'))

    if not is_requested:
        stop()
        status = {
            'phase': 'not_installed',
            'installed': False,
            'ready': False,
            'message': 'Gesichtserkennung ist noch nicht eingerichtet. Zum Einrichten „Gesichtserkennung installieren“ auswählen.',
        }
        atomic(directory / 'faces-setup.json', status)
        return status

    req_id = request.get('request_id')
    if req_id is not None and req_id != _request_id:
        if _process is not None:
            stop()
        _request_id = req_id
        _retry_at = 0.0

    if _process is not None and _process.poll() is not None:
        if _process.returncode != 0:
            _retry_at = time.monotonic() + 300.0
        _process = None

    if _process is None and time.monotonic() >= _retry_at:
        status_init = {'phase': 'preparing', 'installed': False, 'ready': False, 'message': 'Gesichtserkennung wird vorbereitet …'}
        atomic(root / 'setup.json', status_init)
        atomic(directory / 'faces-setup.json', status_init)

        env = {k: v for k, v in os.environ.items() if not k.startswith(('PIP_', 'PYTHON'))}
        env.update(PIP_CONFIG_FILE=os.devnull, PYTHONDONTWRITEBYTECODE='1')
        with (root / 'setup.log').open('a') as log:
            _process = subprocess.Popen(
                [sys.executable, str(Path(__file__).resolve()), '--directory', str(root)],
                env=env, stdin=subprocess.DEVNULL, stdout=log, stderr=log, start_new_session=True
            )
        _identity = (str(root), expected)

    progress = read(root / 'setup.json')
    if not progress:
        progress = {'phase': 'preparing', 'installed': False, 'ready': False, 'message': 'Gesichtserkennung wird vorbereitet …'}

    if _process is None and progress.get('phase') not in ('installed', 'error'):
        progress = {'phase': 'error', 'installed': False, 'ready': False, 'message': 'Automatische Einrichtung fehlgeschlagen. Der Worker versucht es erneut.'}

    result = {
        'phase': progress.get('phase', 'preparing'),
        'installed': progress.get('phase') == 'installed',
        'ready': progress.get('phase') == 'installed',
        'message': progress.get('message', ''),
    }
    atomic(directory / 'faces-setup.json', result)
    return result


def remove(directory: Path | str):
    directory = Path(directory).resolve()
    root, _, _ = paths(directory)
    if root.is_symlink():
        raise ValueError('Refusing to remove a linked directory')
    if root.parent != directory:
        raise ValueError('Invalid directory structure')
    if _identity is not None and _identity[0] == str(root):
        stop()
    if root.exists():
        shutil.rmtree(root)
    clear_request(directory)
    for p in (directory / 'faces-setup.json',):
        p.unlink(missing_ok=True)


if __name__ == '__main__':
    os.umask(0o077)
    parser = argparse.ArgumentParser()
    parser.add_argument('--directory', type=Path, required=True)
    args = parser.parse_args()
    install(args.directory)
