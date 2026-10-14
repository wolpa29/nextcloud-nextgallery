#!/usr/bin/env python3
"""
NextGallery Search Index - SQLite / FTS5 / sqlite-vec search engine.
Self-contained rebuildable search index and query processor.
"""

from __future__ import annotations

import argparse
import base64
import hashlib
import time
import uuid
from datetime import datetime, timezone as dt_timezone
import json
import math
import os
from pathlib import Path
import re
import struct
import sys
from typing import Any, Iterable, List, Optional
import zoneinfo
import sqlite3

try:
    import sqlite_vec
except ImportError:
    sqlite_vec = None


# ---------------------------------------------------------------------------
# Date Resolution (Port of lib/Service/Dates.php)
# ---------------------------------------------------------------------------

MONTHS = {
    'january': 1, 'januar': 1, 'february': 2, 'februar': 2,
    'march': 3, 'märz': 3, 'maerz': 3, 'april': 4,
    'may': 5, 'mai': 5, 'june': 6, 'juni': 6,
    'july': 7, 'juli': 7, 'august': 8, 'september': 9,
    'october': 10, 'oktober': 10, 'november': 11, 'december': 12, 'dezember': 12
}


def check_date(m: int, d: int, y: int) -> bool:
    if 1 <= m <= 12 and 1 <= d <= 31:
        try:
            datetime(y, m, d)
            return True
        except ValueError:
            return False
    return False


def date_result(y: int, m: int = 0, d: int = 0, time_str: str = '000000', source: str = 'folder') -> dict:
    date_str = f"{y:04d}" + (f"-{m:02d}" if m else "") + (f"-{d:02d}" if d else "")
    precision = 'day' if d else ('month' if m else 'year')
    period = f"{y:04d}-{m:02d}-{d:02d}"
    sort_key = f"{y:04d}{m:02d}{d:02d}{time_str}"
    return {
        'date': date_str,
        'precision': precision,
        'source': source,
        'period': period,
        'sort': sort_key
    }


def resolve_folder_date(path: str) -> Optional[dict]:
    dirname = os.path.dirname(path).strip('/')
    if not dirname:
        return None
    parts = dirname.split('/')
    best = None
    for i, part in enumerate(parts):
        m = re.match(r'^((?:19|20|21)\d{2})(?:[-_.](0?[1-9]|1[0-2]))?(?:[-_.](0?[1-9]|[12]\d|3[01]))?$', part)
        if m:
            y = int(m.group(1))
            mo = int(m.group(2)) if m.group(2) else 0
            d = int(m.group(3)) if m.group(3) else 0
            if not mo and i + 1 < len(parts):
                next_part = parts[i + 1].lower()
                m2 = re.match(r'^(0?[1-9]|1[0-2])(?:[ _.-].*)?$', next_part)
                if m2:
                    mo = int(m2.group(1))
                else:
                    mo = MONTHS.get(next_part, 0)
                if mo and i + 2 < len(parts):
                    m3 = re.match(r'^(0?[1-9]|[12]\d|3[01])$', parts[i + 2])
                    if m3:
                        d = int(m3.group(1))
            if not d or (mo and check_date(mo, d, y)):
                best = date_result(y, mo, d, '000000', 'folder')
    return best


def resolve_name_date(name: str) -> Optional[dict]:
    pattern = r'(?<!\d)((?:19|20|21)\d{2})[-_.]?(0[1-9]|1[0-2])[-_.]?(0[1-9]|[12]\d|3[01])(?:[T _.-]([0-2]\d)[-:]?([0-5]\d)[-:]?([0-5]\d))?(?!\d)'
    m = re.search(pattern, name)
    if not m:
        return None
    y = int(m.group(1))
    mo = int(m.group(2))
    d = int(m.group(3))
    h = int(m.group(4)) if m.group(4) else 0
    mi = int(m.group(5)) if m.group(5) else 0
    s = int(m.group(6)) if m.group(6) else 0
    if not check_date(mo, d, y) or h > 23:
        return None
    time_str = f"{h:02d}{mi:02d}{s:02d}"
    return date_result(y, mo, d, time_str, 'filename')


def resolve_metadata_date(raw: Optional[str], zone: str) -> Optional[dict]:
    if not raw or not isinstance(raw, str):
        return None
    m = re.match(r'^(\d{4})[:-](\d{2})[:-](\d{2})[ T](\d{2}):(\d{2}):(\d{2})(.*)$', raw)
    if not m:
        return None
    y, mo, d = int(m.group(1)), int(m.group(2)), int(m.group(3))
    h, mi, s = int(m.group(4)), int(m.group(5)), int(m.group(6))
    tail = m.group(7).strip()
    if not check_date(mo, d, y) or h > 23 or mi > 59 or s > 59:
        return None
    try:
        target_tz = zoneinfo.ZoneInfo(zone)
    except Exception:
        target_tz = zoneinfo.ZoneInfo("UTC")

    try:
        iso_base = f"{y:04d}-{mo:02d}-{d:02d}T{h:02d}:{mi:02d}:{s:02d}"
        offset_m = re.match(r'(?:[.]\d+)?([+-]\d{2}:?\d{2}|Z)$', tail)
        if offset_m:
            dt = datetime.fromisoformat(iso_base + tail).astimezone(target_tz)
        else:
            dt = datetime(y, mo, d, h, mi, s, tzinfo=target_tz)
        return date_result(dt.year, dt.month, dt.day, dt.strftime('%H%M%S'), 'metadata')
    except Exception:
        return None


def resolve_date(path: str, raw: Optional[str], mtime: int, mode: str, zone: str) -> dict:
    f = resolve_folder_date(path)
    n = resolve_name_date(os.path.basename(path))
    e = resolve_metadata_date(raw, zone)
    try:
        tz = zoneinfo.ZoneInfo(zone)
    except Exception:
        tz = zoneinfo.ZoneInfo("UTC")
    dt = datetime.fromtimestamp(mtime, tz=dt_timezone.utc).astimezone(tz)
    fallback = date_result(dt.year, dt.month, dt.day, dt.strftime('%H%M%S'), 'mtime')

    if mode == 'metadata':
        r = e or n or f or fallback
    elif mode == 'filename':
        r = n or e or f or fallback
    else:  # 'folder' or default
        r = f or e or n or fallback

    if mode == 'folder' and f and f['precision'] != 'day':
        detail = e or n
        if detail and detail['date'].startswith(f['date']):
            r = dict(detail)
            r['source'] = 'folder+' + detail['source']

    detail_cmp = e or n
    r['conflict'] = bool(f and detail_cmp and not detail_cmp['date'].startswith(f['date']))
    return r


# ---------------------------------------------------------------------------
# Scope & Metadata Helpers
# ---------------------------------------------------------------------------

def normalize_root(root: Any) -> str:
    if not isinstance(root, str):
        raise ValueError("Root must be a string")
    if '\0' in root or '\\' in root:
        raise ValueError("Invalid root path")
    if re.search(r'(^|/)(\.|\.\.)(/|$)', root):
        raise ValueError("Root path cannot contain relative components")
    r = '/' + root.strip('/') if root != '/' else '/'
    return r


def check_path_in_roots(path: str, roots: list[str]) -> bool:
    for root in roots:
        if root == '/':
            return True
        if path == root or path.startswith(root + '/'):
            return True
    return False


def extract_metadata_text(metadata: Any) -> str:
    if not metadata or not isinstance(metadata, dict):
        return ""
    tokens: list[str] = []

    def walk(obj: Any):
        if isinstance(obj, dict):
            for v in obj.values():
                walk(v)
        elif isinstance(obj, (list, tuple)):
            for item in obj:
                walk(item)
        elif isinstance(obj, (str, int, float)) and not isinstance(obj, bool):
            s = str(obj).strip()
            if s:
                tokens.append(s)

    walk(metadata)
    return " ".join(tokens)


def extract_query_words(query: str) -> list[str]:
    # Regex /[\p{L}\p{N}_]+/u, lowercase, unique, max 16 words
    words = re.findall(r'[\w]+', query.strip().lower())
    return list(dict.fromkeys(words))[:16]


# ---------------------------------------------------------------------------
# SearchIndex Core Class
# ---------------------------------------------------------------------------

class SearchSessionExpired(ValueError):
    pass


class SearchIndex:
    def __init__(self, db_path: str | Path):
        self.db_path = str(db_path)
        self._conn: Optional[sqlite3.Connection] = None

    def get_connection(self) -> sqlite3.Connection:
        if self._conn is None:
            self._conn = sqlite3.connect(self.db_path)
            self._conn.row_factory = sqlite3.Row
            # Enable sqlite-vec extension
            if sqlite_vec is not None:
                self._conn.enable_load_extension(True)
                sqlite_vec.load(self._conn)
                self._conn.enable_load_extension(False)
            self._init_db(self._conn)
        return self._conn

    def close(self):
        if self._conn is not None:
            try:
                self._conn.close()
            except Exception:
                pass
            self._conn = None

    def __enter__(self):
        self.get_connection()
        return self

    def __exit__(self, exc_type, exc_val, exc_tb):
        self.close()

    @staticmethod
    def _init_db(conn: sqlite3.Connection):
        conn.execute("PRAGMA journal_mode = WAL;")
        conn.execute("PRAGMA synchronous = NORMAL;")
        conn.execute("PRAGMA cache_size = -64000;")  # 64 MiB
        conn.execute("PRAGMA temp_store = FILE;")

        conn.execute("""
        CREATE TABLE IF NOT EXISTS documents (
            file_id INTEGER PRIMARY KEY,
            path TEXT NOT NULL UNIQUE,
            name TEXT NOT NULL,
            kind TEXT NOT NULL,
            etag TEXT NOT NULL,
            mtime INTEGER NOT NULL,
            size INTEGER NOT NULL,
            capture TEXT,
            metadata_json TEXT,
            description TEXT NOT NULL DEFAULT '',
            tags TEXT NOT NULL DEFAULT '',
            objects TEXT NOT NULL DEFAULT '',
            ocr TEXT NOT NULL DEFAULT '',
            ai_etag TEXT,
            ai_updated_at INTEGER,
            name_lower TEXT NOT NULL,
            path_lower TEXT NOT NULL,
            metadata_text_lower TEXT NOT NULL DEFAULT '',
            description_lower TEXT NOT NULL DEFAULT '',
            tags_lower TEXT NOT NULL DEFAULT '',
            objects_lower TEXT NOT NULL DEFAULT '',
            ocr_lower TEXT NOT NULL DEFAULT ''
        );
        """)

        conn.execute("CREATE INDEX IF NOT EXISTS idx_docs_path ON documents(path);")
        conn.execute("CREATE INDEX IF NOT EXISTS idx_docs_kind_path ON documents(kind, path);")

        conn.execute("""
        CREATE TABLE IF NOT EXISTS vectors (
            file_id INTEGER NOT NULL,
            etag TEXT NOT NULL,
            frame INTEGER NOT NULL,
            space TEXT NOT NULL,
            dimensions INTEGER NOT NULL,
            vector_data BLOB NOT NULL,
            PRIMARY KEY (file_id, space, frame)
        );
        """)

        conn.execute("CREATE INDEX IF NOT EXISTS idx_vectors_lookup ON vectors(space, dimensions, file_id, etag);")

        conn.execute("""
        CREATE VIRTUAL TABLE IF NOT EXISTS fts_documents USING fts5(
            name,
            path,
            metadata_text,
            description,
            tags,
            objects,
            ocr,
            tokenize='trigram'
        );
        """)

    def _session_schema(self, conn):
        if 'session_store' not in {row[1] for row in conn.execute('PRAGMA database_list')}:
            session_path = self.db_path + '.sessions'
            if os.path.islink(session_path):
                raise ValueError('Unsafe search sessions')
            conn.execute('ATTACH DATABASE ? AS session_store',(session_path,))
            os.chmod(session_path,0o600)
            conn.execute('PRAGMA session_store.journal_mode=WAL')
        conn.execute("CREATE TABLE IF NOT EXISTS session_store.search_sessions(token TEXT PRIMARY KEY, fingerprint TEXT NOT NULL, created_at INTEGER NOT NULL, total INTEGER NOT NULL, mode TEXT NOT NULL, state TEXT NOT NULL, space TEXT)")
        conn.execute("CREATE TABLE IF NOT EXISTS session_store.search_results(token TEXT NOT NULL, position INTEGER NOT NULL, file_id INTEGER NOT NULL, path TEXT NOT NULL, etag TEXT NOT NULL, sources TEXT NOT NULL, PRIMARY KEY(token,position))")

    @staticmethod
    def _page_session(conn, token, query, offset, limit):
        header = conn.execute("SELECT * FROM session_store.search_sessions WHERE token=?", (token,)).fetchone()
        rows = conn.execute("SELECT file_id,path,etag,sources FROM session_store.search_results WHERE token=? AND position>=? ORDER BY position LIMIT ?", (token,offset,limit))
        items = [{"file_id":row["file_id"],"path":row["path"],"etag":row["etag"],"search_match":{"query":query.strip(),"sources":json.loads(row["sources"])}} for row in rows]
        cursor = offset + len(items)
        return {"items":items,"next":cursor if cursor<header["total"] else None,"more":cursor<header["total"],"total":header["total"],"mode":header["mode"],"embedding_state":header["state"],"embedding_space":header["space"],"search_token":token}

    def import_ndjson(self, lines: Iterable[str | bytes]) -> int:
        conn = self.get_connection()
        count = 0
        conn.execute("BEGIN;")
        try:
            for line in lines:
                if isinstance(line, bytes):
                    line = line.decode('utf-8', errors='replace')
                line = line.strip()
                if not line:
                    continue
                record = json.loads(line)
                self._import_record(conn, record)
                count += 1
                if count % 1000 == 0:
                    conn.commit()
                    conn.execute("BEGIN;")
            conn.commit()
        except Exception:
            conn.rollback()
            raise
        finally:
            if os.path.exists(self.db_path):
                try:
                    os.chmod(self.db_path, 0o600)
                except OSError:
                    pass
        return count

    def update_ndjson(self, lines: Iterable[str | bytes]) -> int:
        conn = self.get_connection()
        count = 0
        conn.execute("BEGIN;")
        try:
            for line in lines:
                if isinstance(line, bytes):
                    line = line.decode('utf-8', errors='replace')
                line = line.strip()
                if not line:
                    continue
                record = json.loads(line)
                self._update_record(conn, record)
                count += 1
            conn.commit()
        except Exception:
            conn.rollback()
            raise
        finally:
            if os.path.exists(self.db_path):
                try:
                    os.chmod(self.db_path, 0o600)
                except OSError:
                    pass
        return count

    def _is_control_record(self, record: dict) -> bool:
        rec_type = str(record.get('type', '')).lower()
        if rec_type in ('cursor', 'schema_version', 'schemaversion', 'namespace', 'meta', 'checkpoint'):
            return True
        if 'schemaVersion' in record or 'getCursor' in record or ('namespace' in record and rec_type not in ('document', 'vector', 'delete')):
            return True
        return False

    def _import_record(self, conn: sqlite3.Connection, record: dict):
        if self._is_control_record(record):
            return
        rec_type = record.get('type')
        if rec_type == 'document':
            self._import_document(conn, record)
        elif rec_type == 'vector':
            self._import_vector(conn, record)
        elif rec_type == 'delete':
            self._delete_record(conn, record)
        else:
            raise ValueError(f"Invalid record type: {rec_type}")

    def _update_record(self, conn: sqlite3.Connection, record: dict):
        if self._is_control_record(record):
            return
        rec_type = record.get('type')
        if rec_type == 'document':
            file_id = record.get('file_id')
            if isinstance(file_id, int) and file_id > 0:
                conn.execute("DELETE FROM vectors WHERE file_id = ?", (file_id,))
            self._import_document(conn, record)
        elif rec_type == 'vector':
            self._import_vector(conn, record)
        elif rec_type == 'delete':
            self._delete_record(conn, record)
        else:
            raise ValueError(f"Invalid record type: {rec_type}")

    def _delete_record(self, conn: sqlite3.Connection, record: dict):
        file_id = record.get('file_id')
        if not isinstance(file_id, int) or file_id <= 0:
            raise ValueError("delete file_id must be a positive integer")
        conn.execute("DELETE FROM documents WHERE file_id = ?", (file_id,))
        conn.execute("DELETE FROM vectors WHERE file_id = ?", (file_id,))
        conn.execute("DELETE FROM fts_documents WHERE rowid = ?", (file_id,))

    def _import_document(self, conn: sqlite3.Connection, doc: dict):
        file_id = doc.get('file_id')
        if not isinstance(file_id, int) or file_id <= 0:
            raise ValueError("file_id must be a positive integer")

        path = doc.get('path')
        if not isinstance(path, str) or not path.startswith('/'):
            raise ValueError("path must be an absolute normalized path")
        if '\0' in path or '\\' in path or re.search(r'(^|/)(\.|\.\.)(/|$)', path):
            raise ValueError("path contains invalid segments")

        name = doc.get('name') or os.path.basename(path)
        if not isinstance(name, str) or not name:
            raise ValueError("name must be a non-empty string")

        kind = doc.get('kind')
        if kind not in ('image', 'video'):
            raise ValueError("kind must be 'image' or 'video'")

        etag = doc.get('etag')
        if not isinstance(etag, str) or not etag:
            raise ValueError("etag must be a non-empty string")

        mtime = int(doc.get('mtime', 0))
        size = int(doc.get('size', 0))
        if size < 0:
            raise ValueError("size cannot be negative")

        metadata = doc.get('metadata') or {}
        capture = metadata.get('capture') if isinstance(metadata, dict) else None
        if capture is not None and not isinstance(capture, str):
            capture = str(capture)

        desc = doc.get('description', '')
        if not isinstance(desc, str):
            desc = str(desc)

        tags = doc.get('tags', '')
        if isinstance(tags, list):
            tags = " ".join(str(t) for t in tags)
        elif not isinstance(tags, str):
            tags = str(tags)

        objects = doc.get('objects', '')
        if isinstance(objects, list):
            objects = " ".join(str(o) for o in objects)
        elif not isinstance(objects, str):
            objects = str(objects)

        ocr = doc.get('ocr', '')
        if not isinstance(ocr, str):
            ocr = str(ocr)
        if len(ocr) > 500000:
            raise ValueError("OCR text exceeds maximum allowed length of 500k")

        meta_text = extract_metadata_text(metadata)
        ai_etag = doc.get('ai_etag')
        ai_updated_at = doc.get('ai_updated_at')
        if ai_updated_at is not None:
            ai_updated_at = int(ai_updated_at)

        name_lower = name.lower()
        path_lower = path.lower()
        meta_lower = meta_text.lower()
        desc_lower = desc.lower()
        tags_lower = tags.lower()
        objects_lower = objects.lower()
        ocr_lower = ocr.lower()

        conn.execute("""
        INSERT OR REPLACE INTO documents (
            file_id, path, name, kind, etag, mtime, size, capture, metadata_json,
            description, tags, objects, ocr, ai_etag, ai_updated_at,
            name_lower, path_lower, metadata_text_lower, description_lower,
            tags_lower, objects_lower, ocr_lower
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?);
        """, (
            file_id, path, name, kind, etag, mtime, size, capture, json.dumps(metadata, ensure_ascii=False),
            desc, tags, objects, ocr, ai_etag, ai_updated_at,
            name_lower, path_lower, meta_lower, desc_lower, tags_lower, objects_lower, ocr_lower
        ))

        conn.execute("""
        INSERT OR REPLACE INTO fts_documents (
            rowid, name, path, metadata_text, description, tags, objects, ocr
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?);
        """, (
            file_id, name_lower, path_lower, meta_lower, desc_lower, tags_lower, objects_lower, ocr_lower
        ))

    def _import_vector(self, conn: sqlite3.Connection, vec: dict):
        file_id = vec.get('file_id')
        if not isinstance(file_id, int) or file_id <= 0:
            raise ValueError("vector file_id must be a positive integer")

        etag = vec.get('etag')
        if not isinstance(etag, str) or not etag:
            raise ValueError("vector etag must be a non-empty string")

        frame = vec.get('frame')
        if not isinstance(frame, int) or frame < 0 or frame > 19:
            raise ValueError("vector frame must be an integer between 0 and 19")

        space = vec.get('space')
        if not isinstance(space, str) or len(space) != 64 or not re.match(r'^[0-9a-fA-F]{64}$', space):
            raise ValueError("vector space must be a 64-character hex string")

        dimensions = vec.get('dimensions')
        if not isinstance(dimensions, int) or dimensions < 128 or dimensions > 4096:
            raise ValueError("vector dimensions must be between 128 and 4096")

        wire_data = vec.get('vector_data')
        if not isinstance(wire_data, str) or not wire_data:
            raise ValueError("vector_data must be a non-empty base64 string")

        try:
            raw = base64.b64decode(wire_data, validate=True)
        except Exception:
            raise ValueError("vector_data has invalid base64 encoding")

        if len(raw) != 4 * dimensions:
            raise ValueError(f"vector_data byte length {len(raw)} does not match 4 * {dimensions}")

        floats = struct.unpack(f'<{dimensions}f', raw)
        norm_sq = 0.0
        for x in floats:
            if not math.isfinite(x):
                raise ValueError("vector contains non-finite float value")
            norm_sq += x * x

        if norm_sq < 1e-16 or not math.isfinite(norm_sq):
            raise ValueError("vector norm is zero or non-finite")

        norm = math.sqrt(norm_sq)
        norm_floats = [x / norm for x in floats]
        blob = struct.pack(f'<{dimensions}f', *norm_floats)

        # Duplicate frame rejection
        cur = conn.execute("SELECT 1 FROM vectors WHERE file_id = ? AND space = ? AND frame = ?", (file_id, space, frame))
        if cur.fetchone() is not None:
            raise ValueError(f"Duplicate frame {frame} rejected for file_id {file_id} in space {space}")

        conn.execute("""
        INSERT INTO vectors (file_id, etag, frame, space, dimensions, vector_data)
        VALUES (?, ?, ?, ?, ?, ?);
        """, (file_id, etag, frame, space, dimensions, blob))

    def query(self, req: dict) -> dict:
        conn = self.get_connection()
        self._session_schema(conn)
        conn.execute("BEGIN IMMEDIATE")
        try:
            result = self._query(req)
            conn.commit()
            return result
        except Exception:
            conn.rollback()
            raise

    def _query(self, req: dict) -> dict:
        conn = self.get_connection()

        # 1. Validate request parameters
        raw_query = req.get('query', '')
        if not isinstance(raw_query, str):
            raise ValueError("query must be a string")
        if len(raw_query) > 200:
            raise ValueError("query exceeds maximum length of 200 characters")

        raw_roots = req.get('roots', ['/'])
        if not isinstance(raw_roots, list) or len(raw_roots) > 32 or not raw_roots:
            raise ValueError("roots must be a non-empty list of at most 32 paths")
        normalized_roots = [normalize_root(r) for r in raw_roots]

        kind = req.get('kind', '')
        if kind not in ('', 'image', 'video'):
            raise ValueError("kind must be '', 'image', or 'video'")

        offset = req.get('offset', 0)
        if not isinstance(offset, int) or offset < 0:
            raise ValueError("offset must be an integer >= 0")

        limit = req.get('limit', 60)
        if not isinstance(limit, int) or limit < 1 or limit > 200:
            raise ValueError("limit must be an integer between 1 and 200")

        phase = req.get('phase', 'hybrid')
        if phase not in ('text', 'hybrid'):
            raise ValueError("phase must be 'text' or 'hybrid'")

        period = req.get('period', '')
        if not isinstance(period, str) or (period != '' and not re.match(r'^\d{4}(?:-\d{2})?(?:-\d{2})?$', period)):
            raise ValueError("period format is invalid")

        mode = req.get('mode', 'folder')
        if mode not in ('folder', 'metadata', 'filename'):
            raise ValueError("mode must be 'folder', 'metadata', or 'filename'")

        timezone_str = req.get('timezone', 'UTC')
        try:
            zoneinfo.ZoneInfo(timezone_str)
        except Exception:
            raise ValueError("timezone is unsupported")

        embedding_enabled = bool(req.get('embedding_enabled', False))
        embedding_error = bool(req.get('embedding_error', False))
        space = req.get('space')
        if space is not None:
            if not isinstance(space, str) or len(space) != 64 or not re.match(r'^[0-9a-fA-F]{64}$', space):
                raise ValueError("space must be a 64-character hex string")

        raw_vector = req.get('vector')
        query_vector: Optional[list[float]] = None
        if raw_vector is not None:
            if not isinstance(raw_vector, list) or len(raw_vector) < 128 or len(raw_vector) > 4096:
                raise ValueError("vector must be a list of 128..4096 floats")
            v_floats = []
            v_norm_sq = 0.0
            for val in raw_vector:
                if not isinstance(val, (int, float)) or not math.isfinite(float(val)):
                    raise ValueError("vector contains invalid float value")
                fval = float(val)
                v_floats.append(fval)
                v_norm_sq += fval * fval
            if v_norm_sq < 1e-16 or not math.isfinite(v_norm_sq):
                raise ValueError("vector norm is zero")
            v_norm = math.sqrt(v_norm_sq)
            query_vector = [x / v_norm for x in v_floats]

        # An opaque session freezes ranks across paging while the index is updated.
        signature = {key:req.get(key, default) for key,default in [("query", ""),("roots", []),("kind", ""),("phase", "text"),("period", ""),("mode", "folder"),("timezone", "UTC"),("embedding_enabled", False),("context", ""),("vector_only", False)]}
        fingerprint = hashlib.sha256(json.dumps(signature,sort_keys=True,ensure_ascii=False).encode()).hexdigest()
        token = req.get("search_token", "")
        if token:
            if not isinstance(token,str) or not re.fullmatch(r"[a-f0-9]{32}",token):
                raise ValueError("Invalid search session")
            header = conn.execute("SELECT * FROM session_store.search_sessions WHERE token=?",(token,)).fetchone()
            if not header or header["fingerprint"] != fingerprint or header["created_at"] < time.time()-900:
                raise SearchSessionExpired("Expired or incompatible search session")
            return self._page_session(conn,token,raw_query,offset,limit)

        # 2. Register SQLite helper functions for this connection
        conn.create_function("path_in_scope", 1, lambda p: 1 if check_path_in_roots(p, normalized_roots) else 0)
        conn.create_function("date_matches", 6, lambda p, c, m, mo, z, pr: 1 if (not pr or resolve_date(p, c, m, mo, z)['date'].startswith(pr)) else 0)

        # 3. Lexical search evaluation
        words = extract_query_words(raw_query)
        doc_cache: dict[int, dict] = {}
        sources_map: dict[int, set[str]] = {}
        lexical_results: list[tuple[int, int]] = []  # (file_id, score)

        if words and not req.get("vector_only", False):
            # Build SQL scoring expression for all extracted words
            score_clauses = []
            src_clauses = {
                'name': [], 'path': [], 'metadata': [],
                'description': [], 'tags': [], 'objects': [], 'ocr': []
            }
            params: list[Any] = []

            for w in words:
                score_clauses.append(f"""(
                    (CASE WHEN INSTR(name_lower, ?) > 0 THEN 8 ELSE 0 END) +
                    (CASE WHEN INSTR(path_lower, ?) > 0 THEN 2 ELSE 0 END) +
                    (CASE WHEN INSTR(metadata_text_lower, ?) > 0 THEN 1 ELSE 0 END) +
                    (CASE WHEN INSTR(description_lower, ?) > 0 THEN 1 ELSE 0 END) +
                    (CASE WHEN INSTR(tags_lower, ?) > 0 THEN 3 ELSE 0 END) +
                    (CASE WHEN INSTR(objects_lower, ?) > 0 THEN 3 ELSE 0 END) +
                    (CASE WHEN INSTR(ocr_lower, ?) > 0 THEN 2 ELSE 0 END)
                )""")
                params.extend([w] * 7)

                src_clauses['name'].append("INSTR(name_lower, ?) > 0")
                src_clauses['path'].append("INSTR(path_lower, ?) > 0")
                src_clauses['metadata'].append("INSTR(metadata_text_lower, ?) > 0")
                src_clauses['description'].append("INSTR(description_lower, ?) > 0")
                src_clauses['tags'].append("INSTR(tags_lower, ?) > 0")
                src_clauses['objects'].append("INSTR(objects_lower, ?) > 0")
                src_clauses['ocr'].append("INSTR(ocr_lower, ?) > 0")

            full_score_sql = " + ".join(score_clauses)
            select_src_cols = []
            src_params: list[Any] = []
            for src_name, tests in src_clauses.items():
                select_src_cols.append(f"({' OR '.join(tests)}) AS has_{src_name}")
                src_params.extend(words)

            where_conditions = [
                "path_in_scope(path) = 1",
                "(? = '' OR kind = ?)",
                "date_matches(path, capture, mtime, ?, ?, ?) = 1"
            ]
            filter_params = [kind, kind, mode, timezone_str, period]

            # FTS5 acceleration: if all words are >= 3 characters, use FTS5 candidate lookup
            use_fts = all(len(w) >= 3 for w in words)
            fts_params = []
            if use_fts:
                fts_expr = " OR ".join(f'"{w.replace(chr(34), chr(34)+chr(34))}"' for w in words)
                where_conditions.append("file_id IN (SELECT rowid FROM fts_documents WHERE fts_documents MATCH ?)")
                fts_params.append(fts_expr)

            sql = f"""
            SELECT
                file_id, path, etag,
                ({full_score_sql}) AS score,
                {', '.join(select_src_cols)}
            FROM documents
            WHERE {' AND '.join(where_conditions)}
              AND ({full_score_sql}) > 0
            ORDER BY score DESC, file_id ASC;
            """
            all_params = params + src_params + filter_params + fts_params + params

            cur = conn.execute(sql, all_params)
            for row in cur.fetchall():
                fid = int(row['file_id'])
                score = int(row['score'])
                doc_cache[fid] = {'file_id': fid, 'path': row['path'], 'etag': row['etag']}
                lexical_results.append((fid, score))

                s_set = sources_map.setdefault(fid, set())
                for s_key in ('name', 'path', 'metadata', 'description', 'tags', 'objects', 'ocr'):
                    if row[f'has_{s_key}']:
                        s_set.add(s_key)

        lexical_ids = [fid for fid, _ in lexical_results]

        # 4. Helper to check if any valid vector rows exist in scope
        def has_any_vectors_in_scope() -> bool:
            check_sql = """
            SELECT 1 FROM vectors v
            JOIN documents d ON d.file_id = v.file_id AND d.etag = v.etag
            WHERE path_in_scope(d.path) = 1
              AND (? = '' OR d.kind = ?)
              AND date_matches(d.path, d.capture, d.mtime, ?, ?, ?) = 1
            LIMIT 1;
            """
            r = conn.execute(check_sql, [kind, kind, mode, timezone_str, period]).fetchone()
            return r is not None

        # 5. Embedding state & Vector search execution
        vector_ids: list[int] = []
        embedding_state: str = 'disabled'
        search_mode: str = 'lexical'

        if not embedding_enabled:
            embedding_state = 'disabled'
        elif embedding_error:
            embedding_state = 'unavailable'
        elif phase == 'text':
            embedding_state = 'pending' if has_any_vectors_in_scope() else 'not_indexed'
        else:  # phase == 'hybrid'
            if query_vector is None or space is None or sqlite_vec is None:
                embedding_state = 'unavailable'
            else:
                dim = len(query_vector)
                q_blob = struct.pack(f'<{dim}f', *query_vector)

                vec_sql = """
                SELECT
                    d.file_id,
                    d.path,
                    d.etag,
                    MIN(vec_distance_cosine(v.vector_data, ?)) AS min_dist
                FROM vectors v
                JOIN documents d ON d.file_id = v.file_id AND d.etag = v.etag
                WHERE v.space = ?
                  AND v.dimensions = ?
                  AND path_in_scope(d.path) = 1
                  AND (? = '' OR d.kind = ?)
                  AND date_matches(d.path, d.capture, d.mtime, ?, ?, ?) = 1
                GROUP BY d.file_id
                ORDER BY min_dist ASC, d.file_id ASC;
                """
                vec_params = [q_blob, space, dim, kind, kind, mode, timezone_str, period]
                v_rows = conn.execute(vec_sql, vec_params).fetchall()

                if v_rows:
                    embedding_state = 'ready'
                    search_mode = 'hybrid'
                    for v_row in v_rows:
                        fid = int(v_row['file_id'])
                        vector_ids.append(fid)
                        if fid not in doc_cache:
                            doc_cache[fid] = {'file_id': fid, 'path': v_row['path'], 'etag': v_row['etag']}
                        sources_map.setdefault(fid, set()).add('embedding')
                else:
                    if has_any_vectors_in_scope():
                        embedding_state = 'model_changed'
                    else:
                        embedding_state = 'not_indexed'

        # 6. Rank Fusion (RRF k=60) or Lexical Ranking
        if search_mode == 'hybrid' and embedding_state == 'ready':
            rrf_scores: dict[int, float] = {}
            for rank, fid in enumerate(lexical_ids):
                rrf_scores[fid] = rrf_scores.get(fid, 0.0) + 1.0 / (60.0 + rank + 1.0)
            for rank, fid in enumerate(vector_ids):
                rrf_scores[fid] = rrf_scores.get(fid, 0.0) + 1.0 / (60.0 + rank + 1.0)

            # Sort by RRF score DESC, then file_id ASC
            final_ids = sorted(rrf_scores.keys(), key=lambda fid: (-rrf_scores[fid], fid))
        else:
            final_ids = list(lexical_ids)

        # Bound retained sessions and keep only small identifying records, never OCR/vectors.
        expired = conn.execute("SELECT token FROM session_store.search_sessions WHERE created_at<?",(int(time.time())-900,)).fetchall()
        for old in expired:
            conn.execute("DELETE FROM session_store.search_results WHERE token=?",(old["token"],))
            conn.execute("DELETE FROM session_store.search_sessions WHERE token=?",(old["token"],))
        # Limit count as well as age to prevent a query flood from growing disk indefinitely.
        older = conn.execute("SELECT token FROM session_store.search_sessions ORDER BY created_at DESC,rowid DESC LIMIT -1 OFFSET 15").fetchall()
        for old in older:
            conn.execute("DELETE FROM session_store.search_results WHERE token=?",(old["token"],))
            conn.execute("DELETE FROM session_store.search_sessions WHERE token=?",(old["token"],))
        token = uuid.uuid4().hex
        conn.execute("INSERT INTO session_store.search_sessions VALUES(?,?,?,?,?,?,?)",(token,fingerprint,int(time.time()),len(final_ids),search_mode,embedding_state,space))
        order = ('name','path','metadata','description','tags','objects','ocr','embedding')
        records = ((token,position,fid,doc_cache[fid]['path'],doc_cache[fid]['etag'],json.dumps([source for source in order if source in sources_map.get(fid,set())])) for position,fid in enumerate(final_ids))
        conn.executemany("INSERT INTO session_store.search_results VALUES(?,?,?,?,?,?)",records)
        return self._page_session(conn,token,raw_query,offset,limit)


# ---------------------------------------------------------------------------
# CLI Entry Point
# ---------------------------------------------------------------------------

def main():
    parser = argparse.ArgumentParser(description="NextGallery SQLite / FTS5 / sqlite-vec search engine")
    parser.add_argument("--database", required=True, help="Path to SQLite search index database")
    action_group = parser.add_mutually_exclusive_group(required=True)
    action_group.add_argument("--import", dest="do_import", action="store_true", help="Import NDJSON stream from stdin")
    action_group.add_argument("--update", dest="do_update", action="store_true", help="Incrementally update database from stdin NDJSON")
    action_group.add_argument("--clean", dest="do_clean", action="store_true", help="Remove expired private search sessions")
    action_group.add_argument("--query", dest="do_query", action="store_true", help="Execute single JSON query from stdin")

    args = parser.parse_args()

    if args.do_import:
        index = SearchIndex(args.database)
        try:
            count = index.import_ndjson(sys.stdin)
            print(json.dumps({"success": True, "imported": count}))
            sys.exit(0)
        except Exception as err:
            sys.stderr.write(json.dumps({"error": f"Import failed: {err.__class__.__name__}"}) + "\n")
            sys.exit(1)
        finally:
            index.close()

    elif args.do_update:
        index = SearchIndex(args.database)
        try:
            count = index.update_ndjson(sys.stdin)
            print(json.dumps({"success": True, "updated": count}))
            sys.exit(0)
        except Exception as err:
            sys.stderr.write(json.dumps({"error": f"Update failed: {err.__class__.__name__}"}) + "\n")
            sys.exit(1)
        finally:
            index.close()

    elif args.do_clean:
        with SearchIndex(args.database) as index:
            conn = index.get_connection()
            index._session_schema(conn)
            conn.execute("BEGIN IMMEDIATE")
            cutoff = int(time.time()) - 900
            conn.execute("DELETE FROM session_store.search_results WHERE token IN (SELECT token FROM session_store.search_sessions WHERE created_at<?)",(cutoff,))
            conn.execute("DELETE FROM session_store.search_sessions WHERE created_at<?",(cutoff,))
            conn.commit()
            print(json.dumps({"success":True}))

    elif args.do_query:
        index = SearchIndex(args.database)
        try:
            line = sys.stdin.read().strip()
            if not line:
                raise ValueError("Empty request on stdin")
            request = json.loads(line)
            res = index.query(request)
            print(json.dumps(res, separators=(',', ':'), ensure_ascii=False))
            sys.exit(0)
        except Exception as err:
            sys.stderr.write(json.dumps({"error": f"Query failed: {err.__class__.__name__}"}) + "\n")
            sys.exit(1)
        finally:
            index.close()


if __name__ == '__main__':
    main()
