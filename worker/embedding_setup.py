"""Explicit Gemma installation in the worker's private, writable state directory.

The PHP request never installs Python packages or downloads models. A separate
process keeps the worker responsive and reports progress through an atomic file.
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
import signal
import shutil
import subprocess
import sys
import time
import urllib.parse
import urllib.error
import urllib.request
import uuid
import venv

MODEL_NAME = 'embeddinggemma-2-text-vision-440m.litertlm'
MODEL_SHA256 = '92dcbea108899e5d6e30d919b0744f90d9967e80c67a4ab5503ac16d54f62eb0'
MODEL_BYTES = 387710976
MODEL_URL = ('https://huggingface.co/litert-community/embeddinggemma-2-text-vision-440m-litert-lm/resolve/'
             'e301f74d5551b0c2641bd5cb4652a76239d5c5f8/' + MODEL_NAME)
REQUIREMENTS = Path(__file__).with_name('requirements-embeddings.txt')
FULL_REQUIREMENTS = Path(__file__).with_name('requirements-embeddings-full.txt')
FULL_MANIFEST = Path(__file__).with_name('embedding-full-model.json')
PIP_VERSION = '26.2.1'


def run_setup(command, timeout):
    """Keep subprocess diagnostics private; retain output for error classification."""
    try:
        result = subprocess.run(command, stdout=subprocess.PIPE, stderr=subprocess.STDOUT,
                                text=True, timeout=timeout)
    except subprocess.TimeoutExpired as error:
        output = error.output or ''
        if isinstance(output, bytes): output = output.decode('utf-8', errors='replace')
        if output: print(output, end='', flush=True)
        raise
    if result.stdout:
        print(result.stdout, end='', flush=True)
    if result.returncode:
        raise subprocess.CalledProcessError(result.returncode, command, output=result.stdout)


def setup_failure(error, step):
    """Expose a useful cause without command lines, URLs, credentials or host paths."""
    output = getattr(error, 'output', '') or ''
    if isinstance(output, bytes): output = output.decode('utf-8', errors='replace')
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
    elif 'inconsistent Name' in output:
        code, reason = 'package_metadata_mismatch', 'Der Paketinstaller verwirft eine Abhängigkeit wegen abweichender Paketnamen in den Metadaten.'
    elif 'No matching distribution found for ' in output:
        code, reason = 'package_unavailable', 'Für diese Python-Version oder Plattform fehlt ein passendes Binärpaket.'
        package = re.search(r'No matching distribution found for ([A-Za-z0-9][A-Za-z0-9_.-]{0,80})(?=[<>=!~\s]|$)', output)
        if package: reason += f' Paket: {package.group(1)}.'
    elif isinstance(error, ValueError) and str(error) == 'Model checksum mismatch':
        code, reason = 'model_checksum_mismatch', 'Das heruntergeladene Modell ist unvollständig oder seine Prüfsumme stimmt nicht.'
    elif step == 'Python-Umgebung':
        code, reason = 'python_environment_failed', 'Python-Umgebung konnte nicht erstellt werden. venv/ensurepip-Unterstützung prüfen.'
    elif isinstance(error, subprocess.CalledProcessError):
        reason = f'Installationsprogramm mit Fehlercode {error.returncode} beendet.'
    return {'error_code': code, 'failed_step': step,
            'message': f'{step}: {reason} Details im privaten setup.log der gewählten Variante. Erneuter Versuch nach fünf Minuten oder über „Installation erneut versuchen“.'}

def paths(directory, profile='lite'):
    if profile not in ('lite','full'): raise ValueError('Invalid Gemma profile')
    root=Path(directory)/('embedding-local' if profile=='lite' else 'embedding-full')
    return root, root/'runtime/bin/python', root/(MODEL_NAME if profile=='lite' else 'model')

def model_bytes(profile):
    return MODEL_BYTES if profile=='lite' else sum(f['bytes'] for f in read(FULL_MANIFEST)['files'])

_process = None
_identity = None
_retry_at = 0.
_request_id = None


def atomic(path, value):
    temp = path.with_name(path.name + '.' + uuid.uuid4().hex)
    temp.write_text(json.dumps(value, ensure_ascii=False))
    temp.chmod(0o600)
    temp.replace(path)


def read(path):
    try: return json.loads(path.read_text())
    except (OSError, ValueError): return {}


def identity(profile='lite'):
    if profile=='full':
        return hashlib.sha256(FULL_REQUIREMENTS.read_bytes()+FULL_MANIFEST.read_bytes()+REQUIREMENTS.with_name('requirements.txt').read_bytes()+f'{sys.version_info.major}.{sys.version_info.minor}|full-fp32-vision280-v1'.encode()).hexdigest()
    digest = hashlib.sha256(REQUIREMENTS.read_bytes() + REQUIREMENTS.with_name('requirements.txt').read_bytes()).hexdigest()
    return f'{sys.version_info.major}.{sys.version_info.minor}|{digest}|{MODEL_SHA256}'


def stop():
    global _process, _identity, _retry_at, _request_id
    if _process is not None and _process.poll() is None:
        os.killpg(_process.pid, signal.SIGTERM)
        try: _process.wait(timeout=5)
        except subprocess.TimeoutExpired:
            os.killpg(_process.pid, signal.SIGKILL); _process.wait(timeout=5)
    _process = None; _identity = None; _retry_at = 0.; _request_id = None


def clear_request(directory, profile=None):
    """Keep installation requests separate from media start/pause controls."""
    directory=Path(directory)
    if not (directory/'embedding-install.json').is_file(): return
    with (directory/'control.lock').open('a') as lock:
        fcntl.flock(lock,fcntl.LOCK_EX)
        value=read(directory/'embedding-install.json')
        if profile is not None and value.get('profile','lite')!=profile:return
        value['requested']=False
        atomic(directory/'embedding-install.json',value)


def cached(directory, profile='lite'):
    root,python,model=paths(directory,profile)
    try:
        ready=read(root/'ready.json').get('identity')==identity(profile) and python.is_file()
        if profile=='lite':return ready and model.is_file() and model.stat().st_size==MODEL_BYTES
        return ready and all((model/f['name']).is_file() and (model/f['name']).stat().st_size==f['bytes'] for f in read(FULL_MANIFEST)['files'])
    except OSError:return False


def poll(directory, profile='lite'):
    """Return (progress, Python executable, model). Retry failures after 5 min."""
    global _process, _identity, _retry_at, _request_id
    root,python,model=paths(directory,profile)
    root.mkdir(mode=0o700, exist_ok=True)
    expected = identity(profile)
    if cached(directory,profile):
        if _process is not None and _process.poll() is not None: _process = None
        clear_request(directory,profile)
        return {'phase':'installed','installed':True,'profile':profile}, python, model
    if _identity is not None and _identity != (str(root), expected): stop()
    request=read(Path(directory)/'embedding-install.json')
    if not request.get('requested') or request.get('profile','lite')!=profile:
        stop()
        return {'phase':'not_installed','installed':False,'profile':profile,'message':'Gemma ist noch nicht installiert. Zum Einrichten „Gemma installieren“ auswählen.'},None,None
    if request.get('request_id')!=_request_id:
        if _process is not None:stop()
        _request_id=request.get('request_id');_retry_at=0.
    if _process is not None and _process.poll() is not None:
        if _process.returncode != 0:
            _retry_at = time.monotonic() + 300
        _process = None
    if _process is None and time.monotonic() >= _retry_at:
        atomic(root/'setup.json', {'phase':'preparing', 'message':'Lokale Bildsuche wird eingerichtet …'})
        env = {k:v for k,v in os.environ.items() if not k.startswith(('PIP_', 'PYTHON')) and k != 'NEXTGALLERY_EMBEDDING_KEY'}
        env.update(PIP_CONFIG_FILE=os.devnull, PYTHONDONTWRITEBYTECODE='1')
        with (root/'setup.log').open('a') as log:
            _process = subprocess.Popen([sys.executable, str(Path(__file__).resolve()), '--directory', str(root), '--profile', profile],
                env=env, stdin=subprocess.DEVNULL, stdout=log, stderr=log, start_new_session=True)
        _identity = (str(root), expected)
    progress = read(root/'setup.json')
    if not progress: progress = {'phase':'preparing', 'message':'Lokale Bildsuche wird eingerichtet …'}
    if progress.get('phase') == 'installed': progress = {'phase':'preparing', 'message':'Lokale Bildsuche wird eingerichtet …'}
    if _process is None and progress.get('phase') != 'error':
        progress = {'phase':'error', 'message':'Automatische Einrichtung fehlgeschlagen. Der Worker versucht es erneut.'}
    return {**progress,'installed':False,'profile':profile}, None, None



def installations(directory):
    return {profile:{'installed':cached(directory,profile),'download_bytes':model_bytes(profile)} for profile in ('lite','full')}

def remove(directory,profile):
    """Remove only the selected managed runtime/model; never media or vector stores."""
    directory=Path(directory).resolve(strict=True)
    root,_,_=paths(directory,profile)
    if root.is_symlink():raise ValueError('Refusing to remove a linked model directory')
    if root.parent!=directory:raise ValueError('Invalid model directory')
    if _identity is not None and _identity[0]==str(root):stop()
    if root.exists():shutil.rmtree(root)
    clear_request(directory,profile)


class HTTPSRedirects(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, req, fp, code, msg, headers, newurl):
        if urllib.parse.urlsplit(newurl).scheme != 'https': raise ValueError('Model redirect must use HTTPS')
        return super().redirect_request(req, fp, code, msg, headers, newurl)


def digest(path):
    sha = hashlib.sha256()
    with path.open('rb') as stream:
        for block in iter(lambda: stream.read(1024*1024), b''): sha.update(block)
    return sha.hexdigest()


def download(root, update):
    model = root / MODEL_NAME
    if model.is_file() and model.stat().st_size == MODEL_BYTES and digest(model) == MODEL_SHA256: return model
    temp = root / (MODEL_NAME + '.download')
    started = time.monotonic(); last = 0.; received = 0; sha = hashlib.sha256()
    update(phase='downloading', message='Gemma-Modell wird heruntergeladen …', received_bytes=0, total_bytes=MODEL_BYTES)
    try:
        opener = urllib.request.build_opener(HTTPSRedirects())
        with opener.open(urllib.request.Request(MODEL_URL, headers={'User-Agent':'NextGallery-local-setup/1'}), timeout=30) as response, temp.open('wb') as output:
            if response.status != 200: raise ValueError('Model download response is not a full file')
            for block in iter(lambda: response.read(1024*1024), b''):
                received += len(block)
                if received > MODEL_BYTES or time.monotonic() - started > 900: raise ValueError('Model download exceeds size or time budget')
                output.write(block); sha.update(block)
                if time.monotonic() - last >= 1:
                    update(phase='downloading', message='Gemma-Modell wird heruntergeladen …', received_bytes=received, total_bytes=MODEL_BYTES)
                    last = time.monotonic()
            output.flush(); os.fsync(output.fileno())
        update(phase='verifying', message='Modellpaket wird geprüft …')
        if received != MODEL_BYTES or sha.hexdigest() != MODEL_SHA256: raise ValueError('Model checksum mismatch')
        temp.chmod(0o600); temp.replace(model)
        return model
    finally: temp.unlink(missing_ok=True)


def download_full(root, update):
    manifest=read(FULL_MANIFEST);model=root/'model';total=model_bytes('full');received=0
    model.mkdir(mode=0o700,exist_ok=True)
    for entry in manifest['files']:
        target=model/entry['name'];target.parent.mkdir(mode=0o700,parents=True,exist_ok=True)
        if target.is_file() and target.stat().st_size==entry['bytes'] and digest(target)==entry['sha256']:
            received+=entry['bytes'];continue
        temp=target.with_name(target.name+'.download');count=0;sha=hashlib.sha256();started=time.monotonic();last=0.
        try:
            opener=urllib.request.build_opener(HTTPSRedirects())
            url=f"https://huggingface.co/{manifest['repository']}/resolve/{manifest['revision']}/{entry['name']}"
            with opener.open(urllib.request.Request(url,headers={'User-Agent':'NextGallery-local-setup/1'}),timeout=30) as response,temp.open('wb') as output:
                if response.status!=200:raise ValueError('Model download response is not a full file')
                for block in iter(lambda:response.read(1024*1024),b''):
                    count+=len(block)
                    if count>entry['bytes'] or time.monotonic()-started>1800:raise ValueError('Model download exceeds size or time budget')
                    output.write(block);sha.update(block)
                    if time.monotonic()-last>=1:
                        update(phase='downloading',message='Gemma-Original wird heruntergeladen …',received_bytes=received+count,total_bytes=total);last=time.monotonic()
                output.flush();os.fsync(output.fileno())
            if count!=entry['bytes'] or sha.hexdigest()!=entry['sha256']:raise ValueError('Model checksum mismatch')
            temp.chmod(0o600);temp.replace(target);received+=count
        finally:temp.unlink(missing_ok=True)
    return model

def install(root, profile='lite'):
    root.mkdir(mode=0o700, exist_ok=True)
    update = lambda **fields: atomic(root/'setup.json', {**fields, 'updated_at':time.time()})
    with (root/'setup.lock').open('a') as lock:
        fcntl.flock(lock, fcntl.LOCK_EX)
        step = 'Python-Umgebung'
        try:
            expected = identity(profile)
            update(phase='runtime', message='Python-Laufzeit wird eingerichtet …')
            runtime = root/'runtime'; python = runtime/'bin/python'
            if read(root/'runtime.json').get('identity') != expected or not python.is_file():
                venv.EnvBuilder(with_pip=True,symlinks=True).create(runtime)
                step = 'Paketinstaller aktualisieren'
                update(phase='runtime', message='Python-Paketinstaller wird aktualisiert …')
                # Debian ensurepip can seed pip 23.0.1, which rejects the CPU
                # index's typing_extensions metadata as typing-extensions.
                run_setup([str(python), '-m', 'pip', 'install', '--upgrade', '--only-binary=:all:',
                           '--index-url', 'https://pypi.org/simple', '--disable-pip-version-check',
                           '--no-input', '--no-cache-dir', '--timeout', '30', '--retries', '2',
                           f'pip=={PIP_VERSION}'], timeout=300)
                if profile=='full':
                    step = 'PyTorch-CPU-Pakete installieren'
                    update(phase='runtime', message='PyTorch-CPU-Pakete werden installiert …')
                    run_setup([str(python),'-m','pip','install','--only-binary=:all:','--index-url','https://download.pytorch.org/whl/cpu','--disable-pip-version-check','--no-input','--no-cache-dir','--timeout','30','--retries','2','torch==2.14.1','torchvision==0.29.1'],timeout=900)
                step = 'Modell-Laufzeit installieren'
                update(phase='runtime', message='Modell-Laufzeit wird installiert …')
                run_setup([str(python), '-m', 'pip', 'install', '--only-binary=:all:', '--index-url', 'https://pypi.org/simple',
                    '--disable-pip-version-check', '--no-input', '--no-cache-dir', '--timeout', '30', '--retries', '2',
                    '-r', str(REQUIREMENTS if profile=='lite' else FULL_REQUIREMENTS)], timeout=900)
                step = 'Laufzeit prüfen'
                update(phase='runtime', message='Modell-Laufzeit wird geprüft …')
                run_setup([str(python), '-c', 'import litert_lm; from PIL import Image; import pillow_heif' if profile=='lite' else 'import torch, torchvision, transformers, sentence_transformers; from PIL import Image; import pillow_heif'], timeout=60)
                atomic(root/'runtime.json', {'identity':expected})
            step = 'Modell herunterladen und prüfen'
            model = download(root, update) if profile=='lite' else download_full(root, update)
            atomic(root/'ready.json', {'identity':expected, 'model_sha256':MODEL_SHA256 if profile=='lite' else digest(FULL_MANIFEST)})
            update(phase='installed', message='Modell und Laufzeit installiert.')
        except Exception as error:
            update(phase='error', **setup_failure(error, step))
            raise


if __name__ == '__main__':
    os.umask(0o077)
    parser = argparse.ArgumentParser(); parser.add_argument('--directory', type=Path, required=True);parser.add_argument('--profile',choices=['lite','full'],default='lite')
    args=parser.parse_args();install(args.directory,args.profile)
