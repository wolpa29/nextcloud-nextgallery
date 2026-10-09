"""Present the official SDK stream to the existing guarded SSE reader."""
from contextlib import contextmanager
import base64
import json
import os
from pathlib import Path
import signal
import subprocess
import tempfile
import threading
from urllib.parse import urlsplit, urlunsplit

from media_ai_budget import check_budget, track_cancel


class SDKResponse:
    headers = {'Content-Type': 'text/event-stream'}

    def __init__(self, process, timeout):
        self.process, self.timeout = process, timeout
        self.lock = threading.Lock()
        self.cancelled = False

    def cancel(self):
        with self.lock:
            if self.cancelled:
                return
            self.cancelled = True
        if self.process.poll() is None:
            # SIGTERM invokes SDK cancellation before the watchdog force-kills.
            try:
                self.process.send_signal(signal.SIGTERM)
            except ProcessLookupError:
                return
            try:
                self.process.wait(timeout=2)
            except subprocess.TimeoutExpired:
                self.process.kill()

    def readline(self, limit):
        timer = threading.Timer(self.timeout, self.cancel)
        timer.daemon = True
        timer.start()
        try:
            line = self.process.stdout.readline(limit)
            check_budget()
            return line
        finally:
            timer.cancel()
            timer.join()


@contextmanager
def open_sdk_stream(url, method, body, headers, timeout, ca_file=None):
    from media_pipeline import PipelineError
    payload = json.loads(body)
    parts = urlsplit(url)
    if parts.path != '/v1/chat/completions':
        raise PipelineError('LM Studio SDK requires a direct server /v1 endpoint')
    with tempfile.TemporaryDirectory(prefix='media-ai-sdk-') as directory:
        for message in payload['messages']:
            if isinstance(message['content'], str):
                message['content'] = [{'type': 'text', 'text': message['content']}]
            for index, part in enumerate(message['content']):
                if part['type'] != 'image_url':
                    continue
                mime, encoded = part['image_url']['url'].split(',', 1)
                suffix = '.png' if mime.startswith('data:image/png;') else '.jpg'
                path = Path(directory) / (str(len(list(Path(directory).iterdir()))) + suffix)
                path.write_bytes(base64.b64decode(encoded, validate=True))
                path.chmod(0o600)
                message['content'][index] = {'type': 'image_path', 'path': str(path)}
        token = headers.get('Authorization', '').removeprefix('Bearer ')
        request = {'base_url':urlunsplit(('wss' if parts.scheme == 'https' else 'ws', parts.netloc, '', '', '')),
                   'api_token':token, 'payload':payload}
        environment = dict(os.environ)
        if ca_file:
            environment['NODE_EXTRA_CA_CERTS'] = str(ca_file)
        try:
            process = subprocess.Popen(['node', str(Path(__file__).with_name('lmstudio') / 'stream.mjs')],
                stdin=subprocess.PIPE, stdout=subprocess.PIPE, stderr=subprocess.DEVNULL, env=environment)
        except FileNotFoundError:
            raise PipelineError('LM Studio SDK transport requires Node.js and npm ci in worker/lmstudio') from None
        response = SDKResponse(process, timeout)
        try:
            with track_cancel(response.cancel):
                process.stdin.write(json.dumps(request).encode())
                process.stdin.close()
                yield response
        finally:
            response.cancel()
            process.wait()
            process.stdout.close()
            if not process.stdin.closed:
                process.stdin.close()
