"""Portable, incremental media analysis controller. Originals are read only.

POST requests go to inference endpoints and, if enabled, the custom metadata
app. Nextcloud originals use PROPFIND, GET and HEAD and are never edited.
"""
from __future__ import annotations

import argparse
import base64
from collections import Counter
import contextlib
import gzip
import hashlib
import io
import ipaddress
import json
import os
from pathlib import Path
import re
import sqlite3
import ssl
import time
import urllib.error
import urllib.parse
import urllib.request
import uuid
import xml.etree.ElementTree as ET
from dataclasses import asdict, dataclass
from media_ai_budget import ImageBudget, ImageTimeout, check_budget, network_timeout, track_response
from media_ai_stream import chat_stream, StreamFailure, StreamLoop, RepetitionGuard

VERSION = "structured-stream-2"
IMAGE_SUFFIXES = {".jpg", ".jpeg", ".png", ".webp", ".heic", ".heif", ".tif", ".tiff", ".bmp", ".gif", ".avif"}
KINDS = {"foto", "screenshot", "dokument", "meme", "grafik", "unklar"}
DEFAULT_PROMPTS = json.loads(Path(__file__).with_name("default_prompts.json").read_text(encoding="utf-8"))
VISION_PROMPT = DEFAULT_PROMPTS["vision"]["prompt"]
OCR_PROMPT = DEFAULT_PROMPTS["ocr"]["prompt"]


class PipelineError(RuntimeError):
    pass


def digest(value) -> str:
    data = json.dumps(value, sort_keys=True, ensure_ascii=False, separators=(",", ":")).encode()
    return hashlib.sha256(data).hexdigest()


def bounded_read(stream, maximum):
    check_budget()
    data = stream.read(maximum + 1)
    check_budget()
    if len(data) > maximum:
        raise PipelineError("Response exceeds configured size limit")
    return data


class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, req, fp, code, msg, headers, newurl):
        raise PipelineError("Redirect refused; configure the canonical endpoint")


def request(url, method="GET", body=None, headers=None, timeout=60, maximum=64 * 1024 * 1024, ca_file=None):
    parts = urllib.parse.urlsplit(url)
    if parts.scheme not in {"http", "https"} or parts.username or parts.password or parts.fragment:
        raise PipelineError("Unsupported URL or credentials in URL")
    # Avoid environment proxy settings forwarding private media/credentials.
    context = ssl.create_default_context(cafile=ca_file) if ca_file else ssl.create_default_context()
    opener = urllib.request.build_opener(urllib.request.ProxyHandler({}), NoRedirect(), urllib.request.HTTPSHandler(context=context))
    req = urllib.request.Request(url, data=body, headers=headers or {}, method=method)
    try:
        with opener.open(req, timeout=network_timeout(timeout)) as response, track_response(response):
            payload = bounded_read(response, maximum)
            if response.headers.get("Content-Encoding", "").lower() == "gzip":
                payload = bounded_read(gzip.GzipFile(fileobj=io.BytesIO(payload)), maximum)
            return payload, dict(response.headers.items())
    except urllib.error.HTTPError as error:
        check_budget()
        raise PipelineError(f"HTTP {error.code} from {parts.hostname}") from None
    except (urllib.error.URLError, TimeoutError, OSError) as error:
        check_budget()
        raise PipelineError(f"Connection unavailable: {parts.hostname} ({type(error).__name__})") from None


@contextlib.contextmanager
def open_stream(url, method, body, headers, timeout=60, ca_file=None):
    parts = urllib.parse.urlsplit(url)
    if parts.scheme not in {'http', 'https'} or parts.username or parts.password or parts.fragment:
        raise PipelineError('Unsupported URL or credentials in URL')
    context = ssl.create_default_context(cafile=ca_file) if ca_file else ssl.create_default_context()
    opener = urllib.request.build_opener(urllib.request.ProxyHandler({}), NoRedirect(), urllib.request.HTTPSHandler(context=context))
    req = urllib.request.Request(url, data=body, headers=headers, method=method)
    try:
        with opener.open(req, timeout=network_timeout(timeout)) as response, track_response(response):
            yield response
    except urllib.error.HTTPError as error:
        check_budget()
        raise PipelineError(f'HTTP {error.code} from {parts.hostname}') from None
    except (urllib.error.URLError, TimeoutError, OSError) as error:
        check_budget()
        raise PipelineError(f'Connection unavailable: {parts.hostname} ({type(error).__name__})') from None


def env_header(name):
    value = os.environ.get(name or "", "")
    return {"Authorization": f"Bearer {value}"} if value else {}


def etag(value):
    return (value or "").strip().removeprefix("W/").strip('"')


@dataclass
class Item:
    key: str
    path: str
    locator: str
    etag: str
    size: int
    mime: str = ""


class DirectorySource:
    def __init__(self, config):
        self.root = Path(config["directory"]).expanduser().resolve(strict=True)
        self.scope = digest(["directory", str(self.root)])

    def _item(self, p):
        s = p.stat()
        relative = str(p.relative_to(self.root))
        # A hard link is still a separate visible path. Content caching avoids repeat inference.
        return Item(self.scope + ':' + digest(relative), relative, str(p),
                    f"{s.st_dev}:{s.st_ino}:{s.st_mtime_ns}:{s.st_ctime_ns}:{s.st_size}", s.st_size)

    def inventory(self):
        items = []
        def failed(error):
            raise error
        for parent, dirs, files in os.walk(self.root, followlinks=False, onerror=failed):
            dirs[:] = sorted(d for d in dirs if not (Path(parent) / d).is_symlink())
            for name in sorted(files):
                p = Path(parent) / name
                if p.is_symlink() or p.suffix.lower() not in IMAGE_SUFFIXES:
                    continue
                items.append(self._item(p))
        return items

    def read(self, item, maximum):
        p = Path(item.locator)
        resolved = p.resolve(strict=True)
        if p.is_symlink() or not resolved.is_relative_to(self.root):
            raise PipelineError("Source path escaped the configured directory")
        current = self._item(resolved)
        if (current.key, current.etag) != (item.key, item.etag):
            raise PipelineError("File changed before read")
        with resolved.open("rb") as stream:
            return bounded_read(stream, maximum)

    def unchanged(self, item):
        try:
            p = Path(item.locator)
            if p.is_symlink() or not p.resolve(strict=True).is_relative_to(self.root):
                return False
            current = self._item(p)
            return (current.key, current.etag) == (item.key, item.etag)
        except FileNotFoundError:
            return False


class WebDAVSource:
    def __init__(self, config):
        self.config = config
        self.root = config["url"].rstrip("/") + "/"
        u = urllib.parse.urlsplit(self.root)
        if u.scheme != "https" and u.hostname not in {"127.0.0.1", "localhost", "::1"}:
            raise PipelineError("Nextcloud WebDAV must use HTTPS")
        password = os.environ.get(config.get("password_env", "NEXTCLOUD_APP_PASSWORD"))
        if not password:
            raise PipelineError("Nextcloud app password environment variable is missing")
        token = base64.b64encode(f"{config['username']}:{password}".encode()).decode()
        self.headers = {"Authorization": "Basic " + token}
        self.scope = digest(["webdav", self.root, config["username"]])

    def _url(self, href):
        url = urllib.parse.urljoin(self.root, href)
        a, b = urllib.parse.urlsplit(self.root), urllib.parse.urlsplit(url)
        decoded = urllib.parse.unquote(b.path)
        if (a.scheme, a.netloc) != (b.scheme, b.netloc) or not decoded.startswith(urllib.parse.unquote(a.path)):
            raise PipelineError("WebDAV resource is outside the configured scope")
        if any(part in {".", ".."} for part in decoded.split("/")) or b.query or b.fragment:
            raise PipelineError("Unsafe WebDAV resource path")
        return url

    def _request(self, url, method="GET", body=None, extra=None, maximum=64*1024*1024):
        return request(url, method, body, self.headers | (extra or {}), maximum=maximum, ca_file=self.config.get("ca_file"))

    def inventory(self):
        body = b'''<?xml version="1.0"?><d:propfind xmlns:d="DAV:" xmlns:oc="http://owncloud.org/ns"><d:prop><d:resourcetype/><d:getetag/><d:getcontentlength/><d:getcontenttype/><oc:fileid/></d:prop></d:propfind>'''
        pending, seen, items, item_keys = [self.root], set(), [], set()
        while pending:
            url = pending.pop()
            if url in seen:
                continue
            seen.add(url)
            xml, _ = self._request(url, "PROPFIND", body, {"Depth": "1", "Content-Type": "application/xml", "Accept-Encoding": "gzip"})
            if b"<!DOCTYPE" in xml.upper() or b"<!ENTITY" in xml.upper():
                raise PipelineError("Unexpected XML declarations")
            tree = ET.fromstring(xml)
            responses = tree.findall("{DAV:}response")
            if tree.tag != "{DAV:}multistatus":
                raise PipelineError("Invalid WebDAV multistatus response")
            if not responses:
                raise PipelineError('Empty WebDAV response; refusing to retire files')
            for response in responses:
                href = response.findtext("{DAV:}href")
                if not href:
                    raise PipelineError("Missing WebDAV href")
                child = self._url(href)
                props = None
                for propstat in response.findall("{DAV:}propstat"):
                    if re.search(r"\s200(?:\s|$)", propstat.findtext("{DAV:}status", "")):
                        props = propstat.find("{DAV:}prop")
                        break
                if props is None:
                    raise PipelineError("Incomplete WebDAV listing; refusing to mark absent files")
                if props.find("{DAV:}resourcetype/{DAV:}collection") is not None:
                    child = child.rstrip("/") + "/"
                    if child not in seen:
                        pending.append(child)
                    continue
                mime = props.findtext("{DAV:}getcontenttype", "")
                path = urllib.parse.unquote(urllib.parse.urlsplit(child).path)[len(urllib.parse.unquote(urllib.parse.urlsplit(self.root).path)):]
                if not mime.startswith("image/") and Path(path).suffix.lower() not in IMAGE_SUFFIXES:
                    continue
                version = etag(props.findtext("{DAV:}getetag"))
                if not version:
                    raise PipelineError("Image has no ETag; refusing unsafe incremental indexing")
                identifier = props.findtext("{http://owncloud.org/ns}fileid") or child
                item = Item(self.scope + ":" + identifier, path, child, version,
                            int(props.findtext("{DAV:}getcontentlength", "0")), mime)
                if item.key not in item_keys:
                    items.append(item)
                    item_keys.add(item.key)
            time.sleep(self.config.get("request_pause_seconds", 0.05))
        return items

    def read(self, item, maximum):
        data, headers = self._request(self._url(item.locator), maximum=maximum)
        current = etag(next((v for k, v in headers.items() if k.lower() == "etag"), ""))
        if current and current != item.etag:
            raise PipelineError("File changed while downloading")
        return data

    def unchanged(self, item):
        _, headers = self._request(self._url(item.locator), "HEAD")
        current = etag(next((v for k, v in headers.items() if k.lower() == "etag"), ""))
        return current == item.etag


def prepare_image(data, max_edge, max_pixels, stage, report=None):
    from PIL import Image, ImageOps
    try:
        import pillow_heif
        pillow_heif.register_heif_opener()
    except ImportError:
        pass
    if not isinstance(max_edge, int) or max_edge < 1:
        raise PipelineError("Invalid model image edge limit")
    Image.MAX_IMAGE_PIXELS = max_pixels
    with Image.open(io.BytesIO(data)) as original:
        if original.width * original.height > max_pixels:
            raise PipelineError("Source image exceeds decode memory guard; this is not the model image limit")
        original.seek(0)  # Animated images: first frame only in this pilot.
        raw_exif = original.getexif()
        exif = {name: str(raw_exif[tag]) for tag, name in [(271,"camera_make"),(272,"camera_model"),(306,"file_datetime")] if tag in raw_exif}
        if 34665 in raw_exif:
            details = raw_exif.get_ifd(34665)
            for tag, name in [(36867,"datetime_original"),(36868,"datetime_digitized")]:
                if tag in details:
                    exif[name] = str(details[tag])
        image = ImageOps.exif_transpose(original).convert("RGB")
        oriented_size = image.size
        # Fit the entire oriented frame. Never crop, stretch or upscale.
        image.thumbnail((max_edge, max_edge), Image.Resampling.LANCZOS)
        buffer = io.BytesIO()
        if stage == "ocr":
            image.save(buffer, format="PNG")
            mime = "image/png"
        else:
            image.save(buffer, format="JPEG", quality=90)
            mime = "image/jpeg"
        wire = buffer.getvalue()
        if report is not None:
            report.update(source_width=original.width, source_height=original.height,
                oriented_width=oriented_size[0], oriented_height=oriented_size[1],
                sent_width=image.width, sent_height=image.height, sent_pixels=image.width*image.height,
                max_edge=max_edge, resized=image.size != oriented_size, full_frame=True,
                frame_index=0, mime=mime, sent_bytes=len(wire), sha256=hashlib.sha256(wire).hexdigest())
        return wire, mime, exif


def assert_no_repetition(text, label, numeric_repetitions=40, repetitions=5):
    """Reject dominant repetition loops; preserve legitimate repeated text."""
    lines = [" ".join(line.casefold().split()) for line in text.splitlines() if line.strip()]
    if len(lines) >= 20:
        count = Counter(lines).most_common(1)[0][1]
        if count >= 20 and count / len(lines) >= 0.8:
            raise PipelineError(f"Wiederholungsschleife in {label} erkannt ({count} gleiche Zeilen). Bitte erneut versuchen.")
    # Runaway generation can vary only a counter: JUSB1 ... JUSB1000.
    # Require adjacent alphanumeric labels, a stable affix and 40 consecutive
    # increments. Plain numbered steps and varied receipt rows do not qualify.
    folded = text.casefold()
    previous, end, run = None, 0, 0
    for match in re.finditer(r"\b(?:[^\W\d_]+\d+|\d+[^\W\d_]+)\b", folded):
        parts = re.fullmatch(r"([^\W\d_]*)(\d+)([^\W\d_]*)", match.group())
        current = (parts[1], int(parts[2]), parts[3])
        adjacent = not re.search(r"\w", folded[end:match.start()])
        follows = previous and current[0] == previous[0] and current[2] == previous[2] and current[1] == previous[1] + 1
        run = run + 1 if adjacent and follows else 1
        if run >= numeric_repetitions:
            raise PipelineError(f"Wiederholungsschleife in {label} erkannt (fortlaufend nummerierte Textbloecke). Bitte erneut versuchen.")
        previous, end = current, match.end()
    try:
        RepetitionGuard(repetitions,numeric_repetitions).feed(text,final=True)
    except StreamLoop as error:
        raise PipelineError(str(error)+' ['+label+']') from None
    tokens = re.findall(r"\w+|[^\w\s]", text.casefold())
    if len(tokens) < 40:
        return
    for period in range(1, min(16, len(tokens) // 20) + 1):
        equal_run = 0
        for index in range(period, len(tokens)):
            equal_run = equal_run + 1 if tokens[index] == tokens[index-period] else 0
            covered = equal_run + period
            if equal_run and covered >= period * 20 and covered >= len(tokens) * 0.8:
                raise PipelineError(f"Wiederholungsschleife in {label} erkannt (dominanter wiederholter Textblock). Bitte erneut versuchen.")


def validate_vision(value, config=None):
    config=config or {}
    expected = {"type", "description", "tags", "objects", "uncertain_details"}
    if not isinstance(value, dict) or set(value) != expected or not isinstance(value['type'], str) or value["type"] not in KINDS:
        raise PipelineError("Vision output did not match the metadata schema")
    if not isinstance(value["description"], str) or not 1 <= len(value["description"]) <= 12000:
        raise PipelineError("Invalid description")
    for field in ("tags", "objects", "uncertain_details"):
        if not isinstance(value[field], list) or len(value[field]) > 64 or any(not isinstance(x, str) or len(x) > 500 for x in value[field]):
            raise PipelineError(f"Invalid metadata field: {field}")
    assert_no_repetition(value["description"], "Bildbeschreibung", config.get('numeric_loop_repetitions',40),config.get('loop_repetitions',5))
    for field in ("tags", "objects", "uncertain_details"):
        assert_no_repetition("\n".join(value[field]), "Bildanalyse", config.get('numeric_loop_repetitions',40),config.get('loop_repetitions',5))
    return value


RETRY_PROMPT = DEFAULT_PROMPTS["vision"]["retry_prompt"]


class Model:
    def __init__(self, config, stage):
        self.config, self.stage = config, stage
        self.url = config['url'].rstrip('/')
        self.prompt = config.get('prompt') or (VISION_PROMPT if stage == 'vision' else OCR_PROMPT)
        self.revision = digest([VERSION, stage, config, self.prompt])
        self.observer = None
        self.usage = {}
        self.attempt_history = []
        self.retry_messages = []
        self.last_stream_response = {}

    def emit(self, event, **fields):
        if self.observer is not None:
            self.observer(event, **fields)

    def health(self):
        data, _ = request(self.url + '/models', headers=env_header(self.config.get('api_key_env')), timeout=5, maximum=1024*1024, ca_file=self.config.get('ca_file'))
        ids = {model['id'] for model in json.loads(data)['data']}
        if self.config['model'] not in ids:
            raise PipelineError(f'Configured {self.stage} model is not available at its endpoint')

    def schema(self):
        if self.stage == 'ocr':
            return {'type': 'object', 'additionalProperties': False,
                    'required': ['text'], 'properties': {'text': {'type': 'string'}}}
        return {'type': 'object', 'additionalProperties': False,
                'required': ['type', 'description', 'tags', 'objects', 'uncertain_details'],
                'properties': {'type': {'type': 'string', 'enum': sorted(KINDS)},
                    'description': {'type': 'string'},
                    **{k: {'type': 'array', 'maxItems': 20, 'items': {'type': 'string'}}
                       for k in ['tags', 'objects', 'uncertain_details']}}}

    def decode(self, response):
        if not isinstance(response, dict) or not isinstance(response.get('choices'), list) or not response['choices']:
            raise PipelineError('Model response has no choices')
        usage = response.get('usage') or {}
        if not isinstance(usage, dict): raise PipelineError('Invalid model usage object')
        self.usage = {key: value for key, value in usage.items()
                      if key in {'prompt_tokens', 'completion_tokens', 'total_tokens'}
                      and isinstance(value, int) and value >= 0}
        details = usage.get('completion_tokens_details', {})
        if isinstance(details, dict) and isinstance(details.get('reasoning_tokens'), int):
            self.usage['reasoning_tokens'] = details['reasoning_tokens']
        choice = response['choices'][0]
        if not isinstance(choice, dict) or not isinstance(choice.get('message'), dict):
            raise PipelineError('Invalid model message')
        if choice.get('finish_reason') != 'stop':
            raise PipelineError('Model response did not finish normally: '
                                + str(choice.get('finish_reason'))
                                + '; output_tokens=' + str(self.usage.get('completion_tokens', 'unknown')))
        message = choice['message']
        if message.get('tool_calls'):
            raise PipelineError('Model returned tool calls; actions are not supported')
        content = message.get('content')
        self.output_channel = 'content'
        if not content and self.config.get('allow_reasoning_json', False):
            content = message.get('reasoning_content')
            self.output_channel = 'reasoning_json_workaround'
        if not isinstance(content, str) or not content.strip():
            raise PipelineError('Model returned no final JSON result')
        def unique(pairs):
            result = {}
            for key, value in pairs:
                if key in result: raise PipelineError('Duplicate JSON fields in model result')
                result[key] = value
            return result
        value = json.loads(content, object_pairs_hook=unique)
        if self.stage == 'vision':
            result = validate_vision(value,self.config)
            if len(set(s.casefold() for s in result['tags'] + result['objects'])) > 20:
                raise PipelineError('Vision result exceeds 20 distinct search terms')
            return result
        if not isinstance(value, dict) or set(value) != {'text'} or not isinstance(value['text'], str):
            raise PipelineError('OCR output did not match the JSON schema')
        assert_no_repetition(value['text'], 'OCR', self.config.get('numeric_loop_repetitions', 40),self.config.get('loop_repetitions',5))
        return {'text': value['text'], 'source': 'ocr_model_json'}

    def analyze(self, data, mime):
        self.attempt_history, self.retry_messages, self.usage = [], [], {}
        messages = [{'role': 'user', 'content': [
            {'type': 'text', 'text': self.prompt},
            {'type': 'image_url', 'image_url': {'url': f'data:{mime};base64,' + base64.b64encode(data).decode()}}]}]
        self.retry_messages = messages
        retries = max(0, min(3, int(self.config.get('loop_retries', 3))))
        threshold = max(3, min(20, int(self.config.get('loop_repetitions', 5))))
        numeric_threshold = max(20, min(200, int(self.config.get('numeric_loop_repetitions', 40))))
        headers = env_header(self.config.get('api_key_env')) | {'Content-Type': 'application/json', 'Accept': 'text/event-stream'}
        for attempt in range(1, retries + 2):
            check_budget()
            start, response = time.monotonic(), {}
            self.usage = {}
            self.last_stream_response = {}
            self.output_channel = None
            temperature = self.config.get('temperature', 0.2) if attempt == 1 else self.config.get('retry_temperature', self.config.get('temperature', 0.2))
            payload = {'model': self.config['model'], 'temperature': temperature,
                'stream': self.config.get('stream', True), 'messages': messages,
                'response_format': {'type': 'json_schema', 'json_schema': {
                    'name': 'ocr_text' if self.stage == 'ocr' else 'image_metadata',
                    'strict': True, 'schema': self.schema()}}}
            if payload['stream']: payload['stream_options'] = {'include_usage': True}
            if self.config.get('max_tokens', 0): payload['max_tokens'] = self.config['max_tokens']
            payload.update(self.config.get('extra_body', {}))
            self.emit('inference_attempt_start', attempt=attempt, total_attempts=retries+1, temperature=temperature)
            try:
                def progress(partial):
                    self.last_stream_response = partial
                    msg=partial['choices'][0]['message']
                    self.emit('inference_progress', attempt=attempt, total_attempts=retries+1,
                        seconds=round(time.monotonic()-start,3), reasoning_chars=len(msg['reasoning_content']), content_chars=len(msg['content']))
                opener = lambda *args: open_stream(*args, ca_file=self.config.get('ca_file'))
                response = chat_stream(opener, self.url+'/chat/completions', payload, headers,
                    self.config.get('timeout_seconds', 7200), threshold, numeric_threshold, progress)
                check_budget()
                result = self.decode(response)
                diagnostic = {'attempt': attempt, 'total_attempts': retries+1, 'state': 'valid', 'seconds': round(time.monotonic()-start, 3),
                    'streamed': response.get('streamed', False), 'finish_reason': 'stop',
                    'temperature': temperature,
                    'output_channel': self.output_channel, 'usage': self.usage,
                    'empty_text': self.stage == 'ocr' and result['text'] == ''}
                self.attempt_history.append(diagnostic)
                self.emit('inference_attempt_finished', **diagnostic)
                return result
            except (StreamFailure, PipelineError, ValueError, KeyError, TypeError) as error:
                check_budget()
                if isinstance(error, PipelineError) and str(error).startswith(('HTTP ', 'Connection unavailable:', 'Redirect refused')):
                    raise
                if isinstance(error, StreamFailure): response = error.response
                choices = response.get('choices') if isinstance(response, dict) else None
                failed_choice = choices[0] if isinstance(choices,list) and choices and isinstance(choices[0],dict) else {}
                message = failed_choice.get('message')
                if not isinstance(message,dict): message={}
                is_loop = isinstance(error, StreamLoop) or 'Wiederholungsschleife' in str(error)
                diagnostic = {'attempt': attempt, 'total_attempts': retries+1, 'state': 'loop' if is_loop else 'invalid',
                    'seconds': round(time.monotonic()-start, 3), 'channel': getattr(error, 'channel', None) or (
                        'reasoning_content' if not message.get('content') and message.get('reasoning_content') else 'content'),
                    'error': str(error)[:300], 'reasoning_chars': len(message.get('reasoning_content') or ''),
                    'temperature': temperature,
                    'finish_reason': failed_choice.get('finish_reason'),
                    'streamed': response.get('streamed') if isinstance(response,dict) else None, 'usage': self.usage,
                    'content_chars': len(message.get('content') or ''), 'retrying': attempt <= retries}
                self.attempt_history.append(diagnostic)
                self.emit('inference_attempt_error', level='warning' if attempt <= retries else 'error', **diagnostic)
                if attempt > retries:
                    raise PipelineError(f'Model returned no valid {self.stage} JSON after {attempt} attempts: {error}') from None
                # A fresh correction refers only to this image, not previous files.
                # Do not replay the runaway reasoning and inflate the context.
                messages.append({'role': 'assistant', 'content': 'Der vorige Versuch wurde abgebrochen: '
                    + ('Wiederholungsschleife.' if is_loop else 'Keine gueltige vollstaendige JSON-Antwort.')})
                correction = self.config.get('retry_prompt') or DEFAULT_PROMPTS[self.stage]['retry_prompt']
                messages.append({'role': 'user', 'content': correction})


class Store:
    def __init__(self, path):
        p = Path(path)
        p.parent.mkdir(parents=True, exist_ok=True)
        self.db = sqlite3.connect(p, timeout=10)
        self.db.row_factory = sqlite3.Row
        self.db.executescript("""
        PRAGMA journal_mode=WAL;
        CREATE TABLE IF NOT EXISTS locks(name TEXT PRIMARY KEY, owner TEXT, expires REAL);
        CREATE TABLE IF NOT EXISTS files(id TEXT PRIMARY KEY, scope TEXT, path TEXT, locator TEXT,
          etag TEXT, size INTEGER, mime TEXT, active INTEGER, scan TEXT, status TEXT DEFAULT 'pending',
          revision TEXT, sha256 TEXT, exif TEXT, metadata TEXT, error TEXT, attempts INTEGER DEFAULT 0,
          retry_at REAL DEFAULT 0, analyzed_at REAL);
        CREATE TABLE IF NOT EXISTS results(sha256 TEXT, stage TEXT, revision TEXT, result TEXT,
          created_at REAL, PRIMARY KEY(sha256,stage,revision));
        CREATE VIRTUAL TABLE IF NOT EXISTS search USING fts5(id UNINDEXED, path, description, tags, ocr,
          tokenize='unicode61 remove_diacritics 2');
        """)
        columns = {r[1] for r in self.db.execute('PRAGMA table_info(files)')}
        for column in ('published_revision', 'publish_error'):
            if column not in columns:
                self.db.execute(f'ALTER TABLE files ADD COLUMN {column} TEXT')
        self.db.commit()

    @contextlib.contextmanager
    def lease(self, duration=1800):
        owner = uuid.uuid4().hex
        with self.db:
            self.db.execute("BEGIN IMMEDIATE")
            self.db.execute("DELETE FROM locks WHERE expires < ?", (time.time(),))
            try:
                self.db.execute("INSERT INTO locks VALUES('run',?,?)", (owner, time.time()+duration))
            except sqlite3.IntegrityError:
                raise PipelineError("Another controller is already running") from None
        try:
            def heartbeat():
                with self.db:
                    changed = self.db.execute("UPDATE locks SET expires=? WHERE name='run' AND owner=?", (time.time()+duration, owner)).rowcount
                    if changed != 1:
                        raise PipelineError("Controller lease was lost")
            yield heartbeat
        finally:
            with self.db:
                self.db.execute("DELETE FROM locks WHERE owner=?", (owner,))

    def discover(self, items, scope, revision):
        scan = uuid.uuid4().hex
        with self.db:
            self.db.execute('CREATE TEMP TABLE IF NOT EXISTS inventory_seen(id TEXT PRIMARY KEY)')
            self.db.execute('DELETE FROM inventory_seen')
            for item in items:
                self.db.execute('INSERT OR IGNORE INTO inventory_seen VALUES(?)', (item.key,))
                row = self.db.execute("SELECT etag,revision,path FROM files WHERE id=?", (item.key,)).fetchone()
                changed = row is None or row["etag"] != item.etag or row["revision"] != revision
                self.db.execute("""INSERT INTO files(id,scope,path,locator,etag,size,mime,active,scan,status,revision)
                VALUES(?,?,?,?,?,?,?,1,?,'pending',?) ON CONFLICT(id) DO UPDATE SET
                path=excluded.path,locator=excluded.locator,etag=excluded.etag,size=excluded.size,
                mime=excluded.mime,active=1,scan=excluded.scan,revision=excluded.revision
                WHERE files.path<>excluded.path OR files.locator<>excluded.locator
                OR files.etag<>excluded.etag OR files.revision<>excluded.revision
                OR files.size<>excluded.size OR files.mime<>excluded.mime OR files.active<>1""",
                (item.key, scope, item.path, item.locator, item.etag, item.size, item.mime, scan, revision))
                if changed:
                    self.db.execute("UPDATE files SET status='pending',error=NULL,attempts=0,retry_at=0,published_revision=NULL WHERE id=?", (item.key,))
                # Rename/move without changed content or model: update the search path too.
                if row is not None and row['path'] != item.path:
                    self.db.execute("UPDATE search SET path=? WHERE id=?", (item.path, item.key))
            self.db.execute("UPDATE files SET active=0 WHERE scope=? AND active=1 AND id NOT IN (SELECT id FROM inventory_seen)", (scope,))

    def cached(self, hash_, stage, revision):
        row = self.db.execute("SELECT result FROM results WHERE sha256=? AND stage=? AND revision=?", (hash_, stage, revision)).fetchone()
        return json.loads(row[0]) if row else None

    def cache(self, hash_, stage, revision, result):
        with self.db:
            self.db.execute("INSERT OR REPLACE INTO results VALUES(?,?,?,?,?)", (hash_, stage, revision, json.dumps(result, ensure_ascii=False), time.time()))

    def finish(self, item, hash_, metadata, exif):
        vision, ocr = metadata["vision"], metadata["ocr"]
        with self.db:
            self.db.execute("UPDATE files SET status='done',sha256=?,metadata=?,exif=?,error=NULL,attempts=0,retry_at=0,analyzed_at=? WHERE id=?",
                            (hash_, json.dumps(metadata, ensure_ascii=False), json.dumps(exif, ensure_ascii=False), time.time(), item.key))
            self.db.execute("DELETE FROM search WHERE id=?", (item.key,))
            self.db.execute("INSERT INTO search VALUES(?,?,?,?,?)", (item.key, item.path, vision["description"],
                             " ".join(vision["tags"] + vision["objects"]), ocr["text"]))

    def fail(self, item, error):
        with self.db:
            self.db.execute("UPDATE files SET status='error',error=?,attempts=attempts+1,retry_at=? WHERE id=?",
                            (str(error)[:300], time.time()+3600, item.key))

    def stats(self):
        return dict(self.db.execute("SELECT status,COUNT(*) FROM files WHERE active=1 GROUP BY status").fetchall())

    def search(self, query, limit=20):
        terms = re.findall(r"\w+", query, flags=re.UNICODE)
        if not terms:
            return []
        expression = " AND ".join('"' + word + '"' for word in terms[:32])
        return [dict(row) for row in self.db.execute("""SELECT f.id,f.path,f.sha256,
          snippet(search,2,'[',']','...',24) AS description, f.metadata
          FROM search JOIN files f ON f.id=search.id WHERE search MATCH ? AND f.active=1
          AND f.status='done' ORDER BY bm25(search) LIMIT ?""", (expression, limit))]


class Publisher:
    """Authenticated custom-app API; never writes Nextcloud core tables/files."""
    def __init__(self, config):
        self.config = config
        self.url = config['url'].rstrip('/') + '/ocs/v2.php/apps/media_ai/api/v1/'
        parts = urllib.parse.urlsplit(self.url)
        if parts.scheme != 'https' and parts.hostname not in {'127.0.0.1', 'localhost', '::1'}:
            raise PipelineError('Nextcloud publishing requires HTTPS')
        secret = os.environ.get(config.get('password_env', 'NEXTCLOUD_APP_PASSWORD'))
        if not secret:
            raise PipelineError('Nextcloud app password environment variable is missing')
        token = base64.b64encode(f"{config['username']}:{secret}".encode()).decode()
        self.headers = {'Authorization': 'Basic ' + token, 'OCS-APIRequest': 'true', 'Accept': 'application/json'}

    def api(self, endpoint, body=None):
        wire, _ = request(self.url + endpoint, 'POST' if body is not None else 'GET',
                          json.dumps(body, ensure_ascii=False).encode() if body is not None else None,
                          self.headers | {'Content-Type': 'application/json'}, timeout=30,
                          maximum=2*1024*1024, ca_file=self.config.get('ca_file'))
        envelope = json.loads(wire)['ocs']
        if int(envelope['meta']['statuscode']) not in {100, 200}:
            raise PipelineError('Nextcloud metadata API refused the request')
        return envelope['data']

    def capture(self, item, source):
        path = self.config.get('path_prefix', '').rstrip('/') + '/' + item.path.lstrip('/')
        target = self.api('resolve?' + urllib.parse.urlencode({'format': 'json', 'path': path}))
        if isinstance(source, WebDAVSource) and etag(target['etag']) != item.etag:
            raise PipelineError('Nextcloud file changed before analysis')
        return {'file_id': int(target['file_id']), 'etag': etag(target['etag'])}

    def send(self, row):
        metadata = json.loads(row['metadata'])
        target = metadata.get('nextcloud_target')
        if not target:
            raise PipelineError('Result has no captured Nextcloud identity; re-analyze with publisher enabled')
        self.api('metadata?format=json', {**target, 'sha256': row['sha256'],
                 'metadata': metadata, 'exif': json.loads(row['exif']), 'revision': row['revision']})


def publish_pending(store, publisher, limit=100, heartbeat=lambda: None):
    if publisher is None:
        return {'published': 0, 'publish_errors': 0}
    good = bad = 0
    rows = store.db.execute("SELECT * FROM files WHERE active=1 AND status='done' AND COALESCE(published_revision,'')<>revision ORDER BY path LIMIT ?", (limit or -1,)).fetchall()
    for row in rows:
        heartbeat()
        try:
            publisher.send(row)
            with store.db:
                store.db.execute('UPDATE files SET published_revision=revision,publish_error=NULL WHERE id=?', (row['id'],))
            good += 1
        except (PipelineError, ValueError, KeyError, TypeError, OSError) as error:
            with store.db:
                store.db.execute('UPDATE files SET publish_error=? WHERE id=?', (str(error)[:300], row['id']))
            bad += 1
            if 'Connection unavailable' in str(error):
                break
    return {'published': good, 'publish_errors': bad}


def run(config, store, source, models, limit=100, max_seconds=3600, preprocess=prepare_image, publisher=None, observe=lambda event, **fields: None, should_stop=lambda: False):
    revision = digest({'models': {stage: model.revision for stage, model in models.items()},
                       'publication': publisher.config if publisher else None})
    start = time.monotonic()
    with store.lease(duration=max(1800, 2*sum(m.config.get('timeout_seconds', 300) for m in models.values())+300)) as heartbeat:
        publication = publish_pending(store, publisher, limit, heartbeat)
        # Offline GPU host: no full inventory or image downloads that day.
        try:
            for model in models.values():
                model.health()
        except PipelineError as error:
            return {"state": "deferred", "reason": str(error), "processed": 0, "stats": store.stats(), **publication}
        observe("inventory_start")
        items = source.inventory()  # Only complete successful snapshots may mark absent files.
        heartbeat()
        store.discover(items, source.scope, revision)
        todo = store.db.execute("SELECT id,path,locator,etag,size,mime FROM files WHERE scope=? AND active=1 AND status<>'done' AND retry_at<=? ORDER BY path", (source.scope, time.time())).fetchall()
        observe("inventory_finished", stats=store.stats(), total=len(items))
        completed, errors, cache_hits = 0, 0, 0
        for row in todo:
            if should_stop() or (limit and completed + errors >= limit) or (max_seconds and time.monotonic()-start >= max_seconds):
                break
            heartbeat()
            item = Item(row["id"], row["path"], row["locator"], row["etag"], row["size"], row["mime"])
            observe("file_start", path=item.path, file_id=item.locator, stage="download")
            current_stage, image_start = 'download', time.monotonic()
            try:
                with ImageBudget(config.get('image_timeout_seconds', 0)):
                    target = publisher.capture(item, source) if publisher else None
                    maximum = config.get("max_download_bytes", 64*1024*1024)
                    if item.size > maximum:
                        raise PipelineError("Image exceeds configured download limit")
                    data = source.read(item, maximum)
                    hash_ = hashlib.sha256(data).hexdigest()
                    metadata, exif = {'provenance': {'pipeline': VERSION, 'analyzed_at': time.time(),
                        'models': {s: {'id': m.config.get('model'), 'weights': m.config.get('model_revision'),
                                      'fingerprint': m.revision, 'max_edge': m.config.get('max_edge', 2048)} for s, m in models.items()}}}, {}
                    if target:
                        metadata['nextcloud_target'] = target
                    for stage, model in models.items():
                        observe("stage_start", path=item.path, file_id=item.locator, stage=stage)
                        stage_start = time.monotonic()
                        image_info = {}
                        if isinstance(model, Model):
                            def model_event(event, _item=item, _stage=stage, **fields):
                                heartbeat()  # Renew the lease during healthy long reasoning/retries.
                                observe(event, path=_item.path, file_id=_item.locator, stage=_stage, image=dict(image_info), **fields)
                            model.observer = model_event
                        heartbeat()
                        current_stage = stage
                        check_budget()
                        preparation_args = (data, model.config.get("max_edge", 2048), config.get("max_image_pixels", 80_000_000), stage)
                        resized, mime, exif = (preprocess(*preparation_args, report=image_info)
                            if preprocess is prepare_image else preprocess(*preparation_args))
                        metadata['provenance'].setdefault('images', {})[stage] = image_info
                        check_budget()
                        result = store.cached(hash_, stage, model.revision)
                        was_cached = result is not None
                        if result is None:
                            result = model.analyze(resized, mime)
                            if stage == "ocr": assert_no_repetition(result["text"], "OCR", model.config.get('numeric_loop_repetitions', 40),model.config.get('loop_repetitions',5))
                            else: validate_vision(result,model.config)
                            check_budget()
                            store.cache(hash_, stage, model.revision, result)
                        else:
                            try:
                                if stage == "ocr": assert_no_repetition(result["text"], "OCR", model.config.get('numeric_loop_repetitions', 40),model.config.get('loop_repetitions',5))
                                else: validate_vision(result,model.config)
                            except PipelineError:
                                with store.db:
                                    store.db.execute("DELETE FROM results WHERE sha256=? AND stage=? AND revision=?", (hash_, stage, model.revision))
                                raise
                            cache_hits += 1
                        observe("stage_finished", path=item.path, file_id=item.locator, stage=stage, seconds=round(time.monotonic()-stage_start,3), cached=was_cached, image=image_info, usage={} if was_cached else getattr(model, "usage", {}))
                        metadata[stage] = result
                        metadata['provenance'].setdefault('output_channels', {})[stage] = (
                            'cache' if was_cached else getattr(model, 'output_channel', 'content'))
                        if not was_cached and getattr(model, 'attempt_history', None):
                            metadata['provenance'].setdefault('inference_attempts', {})[stage] = model.attempt_history
                    if not source.unchanged(item):
                        raise PipelineError("Source changed during analysis; result not published")
                    check_budget()
                    store.finish(item, hash_, metadata, exif)
                completed += 1
                if publisher is not None:
                    immediate = publish_pending(store, publisher, 1, heartbeat)
                    publication = {key: publication[key] + immediate[key] for key in publication}
                attempts = metadata['provenance'].get('inference_attempts', {})
                observe("file_finished", path=item.path, file_id=item.locator, stats=store.stats(),
                    corrective_retries=sum(max(0,len(history)-1) for history in attempts.values()),
                    detected_loops=sum(a['state']=='loop' for history in attempts.values() for a in history),
                    images=metadata['provenance'].get('images', {}))
            except (ImageTimeout, PipelineError, OSError, ValueError, KeyError, TypeError) as error:
                store.fail(item, error)
                errors += 1
                observe("file_error", level="error", path=item.path, file_id=item.locator, stage=current_stage, state='timeout' if isinstance(error,ImageTimeout) else 'error', seconds=round(time.monotonic()-image_start,3), error=str(error)[:300], stats=store.stats())
                if 'Connection unavailable' in str(error):
                    break
        new_publication = publish_pending(store, publisher, limit, heartbeat)
        publication = {key: publication[key]+new_publication[key] for key in publication}
        return {"state": "finished", "processed": completed, "errors": errors,
                "cached_stage_results": cache_hits, "seconds": round(time.monotonic()-start, 3), "stats": store.stats(), **publication}


def main(argv=None):
    os.umask(0o077)
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("command", choices=["run", "publish", "status", "search", "export"])
    parser.add_argument("query", nargs="?", default="")
    parser.add_argument("--config", default="config.json")
    parser.add_argument("--limit", type=int, default=100)
    parser.add_argument("--max-seconds", type=float, default=3600)
    args = parser.parse_args(argv)
    if args.limit < 0 or args.max_seconds < 0:
        parser.error("Limits must be nonnegative; zero means unlimited")
    config_path = Path(args.config).resolve()
    config = json.loads(config_path.read_text())
    for key in ("database",):
        config[key] = str((config_path.parent / config[key]).resolve())
    source_config = config["source"]
    for section in ('source', 'vision', 'ocr', 'nextcloud'):
        settings = config.get(section, {})
        if settings.get('ca_file'):
            settings['ca_file'] = str((config_path.parent / settings['ca_file']).resolve())
    if source_config['kind'] == 'directory':
        root = Path(source_config['directory']).expanduser().resolve()
        if Path(config['database']).is_relative_to(root):
            raise PipelineError('Store the analysis database outside the original files directory')
    store = Store(config["database"])
    try:
        if args.command == "status":
            output = store.stats()
        elif args.command == "search":
            output = store.search(args.query, args.limit)
        elif args.command == "export":
            for row in store.db.execute("SELECT id,path,etag,sha256,metadata,exif,analyzed_at FROM files WHERE active=1 AND status='done' ORDER BY path"):
                out = dict(row)
                out["metadata"] = json.loads(out["metadata"])
                out["exif"] = json.loads(out["exif"])
                print(json.dumps(out, ensure_ascii=False))
            return 0
        elif args.command == 'publish':
            settings = config.get('nextcloud', {})
            if not settings.get('enabled'):
                raise PipelineError('Nextcloud metadata publishing is disabled')
            with store.lease() as heartbeat:
                output = publish_pending(store, Publisher(settings), args.limit, heartbeat)
        else:
            source = DirectorySource(source_config) if source_config["kind"] == "directory" else WebDAVSource(source_config)
            models = {stage: Model(config[stage], stage) for stage in ["vision", "ocr"]}
            settings = config.get('nextcloud', {})
            publisher = Publisher(settings) if settings.get('enabled') else None
            output = run(config, store, source, models, args.limit, args.max_seconds, publisher=publisher)
        print(json.dumps(output, ensure_ascii=False, indent=2))
        return 1 if isinstance(output, dict) and (output.get("errors", 0) or output.get('publish_errors', 0)) else 0
    except (PipelineError, ValueError, KeyError, OSError) as error:
        print(json.dumps({"state": "error", "reason": str(error)}, ensure_ascii=False))
        return 1
    finally:
        store.db.close()


if __name__ == "__main__":
    raise SystemExit(main())
