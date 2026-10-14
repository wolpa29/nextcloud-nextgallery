#!/usr/bin/env python3
"""
Unit and integration tests for NextGallery SQLite / FTS5 / sqlite-vec search engine.
"""

import base64
import json
import math
import os
from pathlib import Path
import random
import struct
import subprocess
import sys
import tempfile
import time
import unittest

# Ensure worker/ is in python path
ROOT_DIR = Path(__file__).resolve().parent.parent.parent
sys.path.insert(0, str(ROOT_DIR / "worker"))

import search_index
from search_index import SearchIndex, resolve_date


def make_vector(floats: list[float]) -> tuple[str, int]:
    dim = len(floats)
    norm = math.sqrt(sum(x * x for x in floats))
    if norm > 0:
        floats = [x / norm for x in floats]
    blob = struct.pack(f'<{dim}f', *floats)
    return base64.b64encode(blob).decode('ascii'), dim


class TestSearchIndex(unittest.TestCase):
    def setUp(self):
        self.temp_dir = tempfile.TemporaryDirectory()
        self.db_path = Path(self.temp_dir.name) / "test_search.db"
        self.index = SearchIndex(self.db_path)
        self.test_space = "a" * 64

    def tearDown(self):
        self.index.close()
        self.temp_dir.cleanup()

    # -----------------------------------------------------------------------
    # 1. Dates Resolution (PHP Dates.php match)
    # -----------------------------------------------------------------------
    def test_dates_resolution_exact_php_semantics(self):
        # 1. Folder date year/month/day
        r = resolve_date("/Photos/2024/05/12/img.jpg", None, 1715524200, "folder", "UTC")
        self.assertEqual(r['date'], "2024-05-12")
        self.assertEqual(r['precision'], "day")
        self.assertEqual(r['source'], "folder")

        # 2. Month names in folder (German & English)
        r_de = resolve_date("/Photos/2023/März/img.jpg", None, 1678880000, "folder", "UTC")
        self.assertEqual(r_de['date'], "2023-03")
        r_en = resolve_date("/Photos/2023/October/img.jpg", None, 1697300000, "folder", "UTC")
        self.assertEqual(r_en['date'], "2023-10")

        # 3. Filename date extraction
        r_name = resolve_date("/Photos/RandomFolder/IMG_20220815_143000.jpg", None, 1660566600, "filename", "UTC")
        self.assertEqual(r_name['date'], "2022-08-15")
        self.assertEqual(r_name['source'], "filename")

        # 4. Folder refinement when year/month agree
        r_refine = resolve_date("/Photos/2024-05/IMG_20240510.jpg", None, 1715340000, "folder", "UTC")
        self.assertEqual(r_refine['date'], "2024-05-10")
        self.assertEqual(r_refine['source'], "folder+filename")

        # 5. Conflict when folder and detail disagree
        r_conflict = resolve_date("/Photos/2024-05/IMG_20230101.jpg", None, 1672531200, "folder", "UTC")
        self.assertEqual(r_conflict['date'], "2024-05")
        self.assertTrue(r_conflict['conflict'])

        # 6. Metadata date with timezone conversion
        # 01:30:00 on May 12 with +02:00 -> in UTC it is May 11 23:30:00
        r_meta_utc = resolve_date("/Photos/img.jpg", "2024:05:12 01:30:00+02:00", 1715470000, "metadata", "UTC")
        self.assertEqual(r_meta_utc['date'], "2024-05-11")
        # in Berlin it is May 12
        r_meta_berlin = resolve_date("/Photos/img.jpg", "2024:05:12 01:30:00+02:00", 1715470000, "metadata", "Europe/Berlin")
        self.assertEqual(r_meta_berlin['date'], "2024-05-12")

    # -----------------------------------------------------------------------
    # 2. Schema Validation & Rejections
    # -----------------------------------------------------------------------
    def test_schema_validations(self):
        # Invalid file_id
        with self.assertRaises(ValueError):
            self.index.import_ndjson([json.dumps({'type': 'document', 'file_id': -1, 'path': '/a.jpg', 'name': 'a.jpg', 'kind': 'image', 'etag': 'e1', 'mtime': 100, 'size': 100})])

        # Relative path
        with self.assertRaises(ValueError):
            self.index.import_ndjson([json.dumps({'type': 'document', 'file_id': 1, 'path': '../a.jpg', 'name': 'a.jpg', 'kind': 'image', 'etag': 'e1', 'mtime': 100, 'size': 100})])

        # Invalid kind
        with self.assertRaises(ValueError):
            self.index.import_ndjson([json.dumps({'type': 'document', 'file_id': 1, 'path': '/a.jpg', 'name': 'a.jpg', 'kind': 'audio', 'etag': 'e1', 'mtime': 100, 'size': 100})])

        # OCR over 500k
        with self.assertRaises(ValueError):
            self.index.import_ndjson([json.dumps({
                'type': 'document', 'file_id': 1, 'path': '/a.jpg', 'name': 'a.jpg', 'kind': 'image', 'etag': 'e1',
                'mtime': 100, 'size': 100, 'ocr': 'x' * 500001
            })])

        # Valid doc
        self.index.import_ndjson([json.dumps({
            'type': 'document', 'file_id': 1, 'path': '/a.jpg', 'name': 'a.jpg', 'kind': 'image', 'etag': 'e1',
            'mtime': 100, 'size': 100, 'ocr': 'x' * 1000
        })])

        # Vector invalid dimensions (< 128)
        with self.assertRaises(ValueError):
            self.index.import_ndjson([json.dumps({
                'type': 'vector', 'file_id': 1, 'etag': 'e1', 'frame': 0, 'space': self.test_space,
                'dimensions': 64, 'vector_data': base64.b64encode(b'\x00' * 256).decode()
            })])

        # Vector non-finite / NaN
        nan_bytes = struct.pack('<128f', *([float('nan')] + [0.0] * 127))
        with self.assertRaises(ValueError):
            self.index.import_ndjson([json.dumps({
                'type': 'vector', 'file_id': 1, 'etag': 'e1', 'frame': 0, 'space': self.test_space,
                'dimensions': 128, 'vector_data': base64.b64encode(nan_bytes).decode()
            })])

        # Vector zero norm
        zero_bytes = struct.pack('<128f', *([0.0] * 128))
        with self.assertRaises(ValueError):
            self.index.import_ndjson([json.dumps({
                'type': 'vector', 'file_id': 1, 'etag': 'e1', 'frame': 0, 'space': self.test_space,
                'dimensions': 128, 'vector_data': base64.b64encode(zero_bytes).decode()
            })])

        # Valid vector
        v_valid, _ = make_vector([1.0] + [0.0] * 127)
        self.index.import_ndjson([json.dumps({
            'type': 'vector', 'file_id': 1, 'etag': 'e1', 'frame': 0, 'space': self.test_space,
            'dimensions': 128, 'vector_data': v_valid
        })])

        # Duplicate frame rejection
        with self.assertRaises(ValueError):
            self.index.import_ndjson([json.dumps({
                'type': 'vector', 'file_id': 1, 'etag': 'e1', 'frame': 0, 'space': self.test_space,
                'dimensions': 128, 'vector_data': v_valid
            })])

    # -----------------------------------------------------------------------
    # 3. Roots, Scoping, and Punctuation (%, _)
    # -----------------------------------------------------------------------
    def test_roots_scoping_and_punctuation(self):
        docs = [
            {'type': 'document', 'file_id': 10, 'path': '/Photos/motorrad.jpg', 'name': 'motorrad.jpg', 'kind': 'image', 'etag': 'e10', 'mtime': 100, 'size': 100},
            {'type': 'document', 'file_id': 11, 'path': '/Photos/2024/sub/motorrad.jpg', 'name': 'motorrad.jpg', 'kind': 'image', 'etag': 'e11', 'mtime': 100, 'size': 100},
            {'type': 'document', 'file_id': 12, 'path': '/Photos2/motorrad.jpg', 'name': 'motorrad.jpg', 'kind': 'image', 'etag': 'e12', 'mtime': 100, 'size': 100},
            {'type': 'document', 'file_id': 13, 'path': '/100%_MyPhotos/img_test%1.jpg', 'name': 'img_test%1.jpg', 'kind': 'image', 'etag': 'e13', 'mtime': 100, 'size': 100, 'description': 'motorrad tour'},
            {'type': 'document', 'file_id': 14, 'path': '/100_MyPhotos/other.jpg', 'name': 'other.jpg', 'kind': 'image', 'etag': 'e14', 'mtime': 100, 'size': 100, 'description': 'motorrad tour'},
        ]
        self.index.import_ndjson([json.dumps(d) for d in docs])

        # Scoped to /Photos: matches 10 and 11, but NOT 12
        res = self.index.query({'query': 'motorrad', 'roots': ['/Photos'], 'phase': 'text'})
        matched_ids = [it['file_id'] for it in res['items']]
        self.assertEqual(sorted(matched_ids), [10, 11])

        # Literal % and _ in roots: /100%_MyPhotos should match 13, but NOT 14
        res_punct = self.index.query({'query': 'motorrad', 'roots': ['/100%_MyPhotos'], 'phase': 'text'})
        self.assertEqual([it['file_id'] for it in res_punct['items']], [13])

    # -----------------------------------------------------------------------
    # 4. Lexical Sources, Weights, and Short Terms
    # -----------------------------------------------------------------------
    def test_lexical_sources_and_weights(self):
        docs = [
            # Matches name only (weight 8)
            {'type': 'document', 'file_id': 1, 'path': '/Photos/pic1.jpg', 'name': 'motorrad.jpg', 'kind': 'image', 'etag': 'e1', 'mtime': 100, 'size': 100},
            # Matches path only (weight 2)
            {'type': 'document', 'file_id': 2, 'path': '/Photos/motorrad_trip/pic.jpg', 'name': 'pic.jpg', 'kind': 'image', 'etag': 'e2', 'mtime': 100, 'size': 100},
            # Matches tags only (weight 3)
            {'type': 'document', 'file_id': 3, 'path': '/Photos/pic3.jpg', 'name': 'pic3.jpg', 'kind': 'image', 'etag': 'e3', 'mtime': 100, 'size': 100, 'tags': 'motorrad enduro'},
            # Matches objects only (weight 3)
            {'type': 'document', 'file_id': 4, 'path': '/Photos/pic4.jpg', 'name': 'pic4.jpg', 'kind': 'image', 'etag': 'e4', 'mtime': 100, 'size': 100, 'objects': 'motorrad helm'},
            # Matches ocr only (weight 2)
            {'type': 'document', 'file_id': 5, 'path': '/Photos/pic5.jpg', 'name': 'pic5.jpg', 'kind': 'image', 'etag': 'e5', 'mtime': 100, 'size': 100, 'ocr': 'motorrad club münchen'},
            # Matches description only (weight 1)
            {'type': 'document', 'file_id': 6, 'path': '/Photos/pic6.jpg', 'name': 'pic6.jpg', 'kind': 'image', 'etag': 'e6', 'mtime': 100, 'size': 100, 'description': 'ein motorrad am straßenrand'},
            # Matches metadata values only (weight 1)
            {'type': 'document', 'file_id': 7, 'path': '/Photos/pic7.jpg', 'name': 'pic7.jpg', 'kind': 'image', 'etag': 'e7', 'mtime': 100, 'size': 100, 'metadata': {'exif': {'Camera': 'motorrad cam'}}},
            # Has protocol keys like 'exif', 'capture' in metadata: must NOT match if user searches for 'exif'
            {'type': 'document', 'file_id': 8, 'path': '/Photos/pic8.jpg', 'name': 'pic8.jpg', 'kind': 'image', 'etag': 'e8', 'mtime': 100, 'size': 100, 'metadata': {'exif': {'Camera': 'Nikon'}}},
            # Unicode match with Umlauts
            {'type': 'document', 'file_id': 9, 'path': '/Photos/pic9.jpg', 'name': 'pic9.jpg', 'kind': 'image', 'etag': 'e9', 'mtime': 100, 'size': 100, 'description': 'Reise nach MÜNCHEN'},
            # Short term (2 chars) e.g. "vw"
            {'type': 'document', 'file_id': 10, 'path': '/Photos/pic10.jpg', 'name': 'pic10.jpg', 'kind': 'image', 'etag': 'e10', 'mtime': 100, 'size': 100, 'description': 'Mein VW Bus'},
        ]
        self.index.import_ndjson([json.dumps(d) for d in docs])

        # Test query "motorrad":
        # Document 1 (name: 8) must come before Document 3,4 (tags/obj: 3), then 2,5 (path/ocr: 2), then 6,7 (desc/meta: 1)
        res = self.index.query({'query': 'motorrad', 'phase': 'text'})
        ids = [it['file_id'] for it in res['items']]
        self.assertEqual(ids[0], 1)
        self.assertEqual(res['items'][0]['search_match']['sources'], ['name'])

        # Check metadata source without protocol flag pollution:
        # Searching for 'exif' should NOT match Document 8!
        res_exif = self.index.query({'query': 'exif', 'phase': 'text'})
        self.assertNotIn(8, [it['file_id'] for it in res_exif['items']])

        # Searching for 'nikon' matches Document 8
        res_nikon = self.index.query({'query': 'nikon', 'phase': 'text'})
        self.assertIn(8, [it['file_id'] for it in res_nikon['items']])
        item8 = next(it for it in res_nikon['items'] if it['file_id'] == 8)
        self.assertEqual(item8['search_match']['sources'], ['metadata'])

        # Unicode case insensitivity: search 'münchen' matches 'MÜNCHEN'
        res_muc = self.index.query({'query': 'münchen', 'phase': 'text'})
        self.assertIn(9, [it['file_id'] for it in res_muc['items']])

        # Short term: 'vw' (2 chars) triggers exact fallback
        res_vw = self.index.query({'query': 'vw', 'phase': 'text'})
        self.assertIn(10, [it['file_id'] for it in res_vw['items']])

    # -----------------------------------------------------------------------
    # 5. Vectors, Stale ETag, Incompatible Space & Video Frame-Max
    # -----------------------------------------------------------------------
    def test_vectors_frame_max_and_state_transitions(self):
        v_high, dim = make_vector([1.0, 0.0] + [0.0] * 126)
        v_med, _ = make_vector([0.7071, 0.7071] + [0.0] * 126)
        v_low, _ = make_vector([0.0, 1.0] + [0.0] * 126)

        query_vec = [1.0, 0.0] + [0.0] * 126

        docs = [
            {'type': 'document', 'file_id': 100, 'path': '/Photos/v100.jpg', 'name': 'v100.jpg', 'kind': 'image', 'etag': 'e100', 'mtime': 100, 'size': 100},
            # Video with 2 frames: frame 0 has low similarity, frame 1 has HIGH similarity
            {'type': 'document', 'file_id': 200, 'path': '/Photos/video.mp4', 'name': 'video.mp4', 'kind': 'video', 'etag': 'e200', 'mtime': 100, 'size': 100},
            # Stale vector: document etag is e300_new, but vector will have e300_old
            {'type': 'document', 'file_id': 300, 'path': '/Photos/stale.jpg', 'name': 'stale.jpg', 'kind': 'image', 'etag': 'e300_new', 'mtime': 100, 'size': 100},
            # Document with vector in another space
            {'type': 'document', 'file_id': 400, 'path': '/Photos/other_space.jpg', 'name': 'other_space.jpg', 'kind': 'image', 'etag': 'e400', 'mtime': 100, 'size': 100},
        ]
        self.index.import_ndjson([json.dumps(d) for d in docs])

        vecs = [
            {'type': 'vector', 'file_id': 100, 'etag': 'e100', 'frame': 0, 'space': self.test_space, 'dimensions': dim, 'vector_data': v_med},
            {'type': 'vector', 'file_id': 200, 'etag': 'e200', 'frame': 0, 'space': self.test_space, 'dimensions': dim, 'vector_data': v_low},
            {'type': 'vector', 'file_id': 200, 'etag': 'e200', 'frame': 1, 'space': self.test_space, 'dimensions': dim, 'vector_data': v_high},
            # Stale vector
            {'type': 'vector', 'file_id': 300, 'etag': 'e300_old', 'frame': 0, 'space': self.test_space, 'dimensions': dim, 'vector_data': v_high},
            # Other space vector
            {'type': 'vector', 'file_id': 400, 'etag': 'e400', 'frame': 0, 'space': 'b' * 64, 'dimensions': dim, 'vector_data': v_high},
        ]
        self.index.import_ndjson([json.dumps(v) for v in vecs])

        # Text phase: valid vectors exist in scope -> pending
        res_text = self.index.query({'query': '', 'phase': 'text', 'embedding_enabled': True})
        self.assertEqual(res_text['embedding_state'], 'pending')

        # Hybrid query with matching space
        res_hybrid = self.index.query({
            'query': '',
            'phase': 'hybrid',
            'embedding_enabled': True,
            'space': self.test_space,
            'vector': query_vec
        })
        self.assertEqual(res_hybrid['embedding_state'], 'ready')
        self.assertEqual(res_hybrid['mode'], 'hybrid')
        # Video 200 (frame 1 = v_high) must rank BEFORE 100 (v_med)
        ids = [it['file_id'] for it in res_hybrid['items']]
        self.assertEqual(ids, [200, 100])
        # Stale 300 must not be included
        self.assertNotIn(300, ids)
        # Check that 'embedding' is in sources
        self.assertEqual(res_hybrid['items'][0]['search_match']['sources'], ['embedding'])

        # Hybrid query with a space where no vectors exist, but vectors in scope exist -> model_changed
        res_changed = self.index.query({
            'query': '',
            'phase': 'hybrid',
            'embedding_enabled': True,
            'space': 'c' * 64,
            'vector': query_vec
        })
        self.assertEqual(res_changed['embedding_state'], 'model_changed')
        self.assertEqual(res_changed['mode'], 'lexical')

        # Embedding outage: embedding_error = True -> preserves lexical search and sets unavailable
        res_err = self.index.query({
            'query': '',
            'phase': 'hybrid',
            'embedding_enabled': True,
            'embedding_error': True
        })
        self.assertEqual(res_err['embedding_state'], 'unavailable')

    # -----------------------------------------------------------------------
    # 6. RRF Fusion, Deterministic Tie-Breaking, and Pagination
    # -----------------------------------------------------------------------
    def test_rrf_fusion_and_pagination(self):
        v1, dim = make_vector([1.0, 0.0] + [0.0] * 126)
        v2, _ = make_vector([0.0, 1.0] + [0.0] * 126)

        docs = [
            # Doc 1: lexical match on description (weight 1)
            {'type': 'document', 'file_id': 1, 'path': '/Photos/doc1.jpg', 'name': 'doc1.jpg', 'kind': 'image', 'etag': 'e1', 'mtime': 100, 'size': 100, 'description': 'motorrad'},
            # Doc 2: lexical match on tags (weight 3) + better vector match
            {'type': 'document', 'file_id': 2, 'path': '/Photos/doc2.jpg', 'name': 'doc2.jpg', 'kind': 'image', 'etag': 'e2', 'mtime': 100, 'size': 100, 'tags': 'motorrad'},
        ]
        self.index.import_ndjson([json.dumps(d) for d in docs])

        # Vector: Doc 2 matches query vector better than Doc 1
        vecs = [
            {'type': 'vector', 'file_id': 1, 'etag': 'e1', 'frame': 0, 'space': self.test_space, 'dimensions': dim, 'vector_data': v2},
            {'type': 'vector', 'file_id': 2, 'etag': 'e2', 'frame': 0, 'space': self.test_space, 'dimensions': dim, 'vector_data': v1},
        ]
        self.index.import_ndjson([json.dumps(v) for v in vecs])

        # Query vector identical to Doc 2
        res = self.index.query({
            'query': 'motorrad',
            'phase': 'hybrid',
            'embedding_enabled': True,
            'space': self.test_space,
            'vector': [1.0, 0.0] + [0.0] * 126,
            'offset': 0,
            'limit': 1
        })
        self.assertEqual(res['mode'], 'hybrid')
        self.assertEqual(res['total'], 2)
        self.assertEqual(res['more'], True)
        self.assertEqual(res['next'], 1)
        self.assertEqual(len(res['items']), 1)
        # Doc 2 should win because it has equal lexical score and better vector rank
        self.assertEqual(res['items'][0]['file_id'], 2)
        self.assertIn('embedding', res['items'][0]['search_match']['sources'])
        self.assertIn('tags', res['items'][0]['search_match']['sources'])

        # Next page
        res_p2 = self.index.query({
            'query': 'motorrad',
            'phase': 'hybrid',
            'embedding_enabled': True,
            'space': self.test_space,
            'vector': [1.0, 0.0] + [0.0] * 126,
            'offset': 1,
            'limit': 1
        })
        self.assertEqual(res_p2['more'], False)
        self.assertIsNone(res_p2['next'])
        self.assertEqual(res_p2['items'][0]['file_id'], 1)

    # -----------------------------------------------------------------------
    # 7. CLI Subprocess Interface
    # -----------------------------------------------------------------------
    def test_cli_import_and_query(self):
        cli_db = Path(self.temp_dir.name) / "cli_test.db"

        # 1. Import via CLI stdin
        doc_line = json.dumps({'type': 'document', 'file_id': 77, 'path': '/cli.jpg', 'name': 'cli.jpg', 'kind': 'image', 'etag': 'e77', 'mtime': 100, 'size': 100, 'description': 'cli test'})
        proc_import = subprocess.run(
            [sys.executable, str(ROOT_DIR / "worker" / "search_index.py"), "--database", str(cli_db), "--import"],
            input=doc_line + "\n",
            capture_output=True,
            text=True
        )
        self.assertEqual(proc_import.returncode, 0)
        out_import = json.loads(proc_import.stdout)
        self.assertEqual(out_import['imported'], 1)

        # Verify chmod 0600
        mode = oct(os.stat(cli_db).st_mode & 0o777)
        self.assertEqual(mode, '0o600')

        # 2. Query via CLI stdin
        query_json = json.dumps({'query': 'cli', 'phase': 'text'})
        proc_query = subprocess.run(
            [sys.executable, str(ROOT_DIR / "worker" / "search_index.py"), "--database", str(cli_db), "--query"],
            input=query_json + "\n",
            capture_output=True,
            text=True
        )
        self.assertEqual(proc_query.returncode, 0)
        res = json.loads(proc_query.stdout)
        self.assertEqual(len(res['items']), 1)
        self.assertEqual(res['items'][0]['file_id'], 77)

        # 3. Invalid CLI query returns non-zero and sanitized JSON
        proc_bad = subprocess.run(
            [sys.executable, str(ROOT_DIR / "worker" / "search_index.py"), "--database", str(cli_db), "--query"],
            input="invalid json\n",
            capture_output=True,
            text=True
        )
        self.assertNotEqual(proc_bad.returncode, 0)
        err_out = json.loads(proc_bad.stderr)
        self.assertIn("error", err_out)

    # -----------------------------------------------------------------------
    # 8. Large Synthetic Corpus (10k Vectors) Benchmark & Verification
    # -----------------------------------------------------------------------
    def test_large_synthetic_corpus_10k_vectors(self):
        corpus_db = Path(self.temp_dir.name) / "corpus_10k.db"
        idx = SearchIndex(corpus_db)
        dim = 128
        N = 10000

        print(f"\n[BENCHMARK] Generating {N} synthetic documents and vectors (dim={dim})...")
        t0 = time.perf_counter()

        # Seed for determinism
        rng = random.Random(42)

        def generate_ndjson():
            # One special target document to test search accuracy
            yield json.dumps({
                'type': 'document', 'file_id': 1, 'path': '/Photos/target_motorrad.jpg',
                'name': 'target_motorrad.jpg', 'kind': 'image', 'etag': 'etag_1',
                'mtime': 1700000000, 'size': 500000, 'description': 'Besonderes Motorrad in den Alpen'
            }) + "\n"
            target_vec, _ = make_vector([1.0] + [0.0] * (dim - 1))
            yield json.dumps({
                'type': 'vector', 'file_id': 1, 'etag': 'etag_1', 'frame': 0,
                'space': self.test_space, 'dimensions': dim, 'vector_data': target_vec
            }) + "\n"

            for i in range(2, N + 1):
                doc = {
                    'type': 'document',
                    'file_id': i,
                    'path': f'/Photos/dir_{i % 50}/img_{i}.jpg',
                    'name': f'img_{i}.jpg',
                    'kind': 'image' if i % 10 != 0 else 'video',
                    'etag': f'etag_{i}',
                    'mtime': 1700000000 + (i % 100000),
                    'size': 100000 + (i * 37) % 500000,
                    'description': f'Random description {i}',
                    'ocr': f'OCR text snippet {i}'
                }
                yield json.dumps(doc) + "\n"

                # Random unit vector
                floats = [rng.gauss(0, 1) for _ in range(dim)]
                v_data, _ = make_vector(floats)
                vec = {
                    'type': 'vector',
                    'file_id': i,
                    'etag': f'etag_{i}',
                    'frame': 0,
                    'space': self.test_space,
                    'dimensions': dim,
                    'vector_data': v_data
                }
                yield json.dumps(vec) + "\n"

        import_count = idx.import_ndjson(generate_ndjson())
        t_import = time.perf_counter() - t0
        print(f"[BENCHMARK] Imported {import_count} records in {t_import:.2f}s ({import_count/t_import:.0f} rec/s)")

        # Verify file size
        db_size_mb = os.path.getsize(corpus_db) / (1024 * 1024)
        print(f"[BENCHMARK] Database file size: {db_size_mb:.2f} MiB")

        # 1. Text Query Benchmark
        t0 = time.perf_counter()
        res_text = idx.query({'query': 'motorrad', 'phase': 'text', 'limit': 20})
        t_text = time.perf_counter() - t0
        print(f"[BENCHMARK] Text search across {N} docs: {t_text*1000:.2f} ms (Matches: {res_text['total']})")
        self.assertEqual(res_text['total'], 1)
        self.assertEqual(res_text['items'][0]['file_id'], 1)

        # 2. Hybrid Query Benchmark (Cosine similarity over 10,000 vectors)
        query_v = [1.0] + [0.0] * (dim - 1)
        t0 = time.perf_counter()
        res_hybrid = idx.query({
            'query': 'motorrad',
            'phase': 'hybrid',
            'embedding_enabled': True,
            'space': self.test_space,
            'vector': query_v,
            'limit': 20
        })
        t_hybrid = time.perf_counter() - t0
        print(f"[BENCHMARK] Hybrid search (RRF + 10k vec distance): {t_hybrid*1000:.2f} ms (Total candidates: {res_hybrid['total']})")
        self.assertEqual(res_hybrid['mode'], 'hybrid')
        self.assertEqual(res_hybrid['embedding_state'], 'ready')
        # Target document 1 matches both exact text and exact vector (sim 1.0) -> must be #1
        self.assertEqual(res_hybrid['items'][0]['file_id'], 1)
        self.assertIn('embedding', res_hybrid['items'][0]['search_match']['sources'])
        self.assertIn('description', res_hybrid['items'][0]['search_match']['sources'])

        idx.close()

    def test_query_input_validations(self):
        # 1. query > 200 chars
        with self.assertRaises(ValueError):
            self.index.query({'query': 'a' * 201})

        # 2. roots > 32
        with self.assertRaises(ValueError):
            self.index.query({'query': 'test', 'roots': [f'/r{i}' for i in range(33)]})

        # 3. unnormalized root with ..
        with self.assertRaises(ValueError):
            self.index.query({'query': 'test', 'roots': ['/Photos/..']})

        # 4. limit > 200 or < 1
        with self.assertRaises(ValueError):
            self.index.query({'query': 'test', 'limit': 201})
        with self.assertRaises(ValueError):
            self.index.query({'query': 'test', 'limit': 0})

        # 5. offset < 0
        with self.assertRaises(ValueError):
            self.index.query({'query': 'test', 'offset': -1})

        # 6. invalid timezone
        with self.assertRaises(ValueError):
            self.index.query({'query': 'test', 'timezone': 'Invalid/Zone'})

        # 7. invalid period format
        with self.assertRaises(ValueError):
            self.index.query({'query': 'test', 'period': 'not-a-date'})

    def test_long_ocr_multilingual_unicode(self):
        long_ocr = ("Ein langer deutscher Rechnungstext mit Umlauten ÄÖÜß und Ziffern 123456. " * 5000) + " GEHEIMER_BELEG_9999 " + ("Abschlusszeile. " * 5000)
        self.assertTrue(len(long_ocr) > 300000)
        self.assertTrue(len(long_ocr) < 500000)

        doc = {
            'type': 'document',
            'file_id': 99999,
            'path': '/Documents/invoice_huge.pdf',
            'name': 'invoice_huge.pdf',
            'kind': 'image',
            'etag': 'e_huge',
            'mtime': 1700000000,
            'size': 1000000,
            'ocr': long_ocr
        }
        self.index.import_ndjson([json.dumps(doc)])

        res = self.index.query({'query': 'geheimer_beleg_9999', 'phase': 'text'})
        self.assertEqual(len(res['items']), 1)
        self.assertEqual(res['items'][0]['file_id'], 99999)
        self.assertEqual(res['items'][0]['search_match']['sources'], ['ocr'])

    def test_no_media_filesystem_access(self):
        # File paths do not exist on disk, proving search_index never reads or opens media files
        doc = {
            'type': 'document',
            'file_id': 88888,
            'path': '/non_existent_mount/no_such_file_12345.jpg',
            'name': 'no_such_file_12345.jpg',
            'kind': 'image',
            'etag': 'e888',
            'mtime': 100,
            'size': 100,
            'description': 'completely virtual path'
        }
        self.index.import_ndjson([json.dumps(doc)])
        res = self.index.query({'query': 'virtual', 'phase': 'text'})
        self.assertEqual(len(res['items']), 1)
        self.assertEqual(res['items'][0]['file_id'], 88888)

    def test_incremental_update_and_delete(self):
        v1, dim = make_vector([1.0, 0.0] + [0.0] * 126)
        v2, _ = make_vector([0.0, 1.0] + [0.0] * 126)

        # 1. Initial document + vector
        doc = {
            'type': 'document',
            'file_id': 500,
            'path': '/Photos/update_test.jpg',
            'name': 'update_test.jpg',
            'kind': 'image',
            'etag': 'etag_initial',
            'mtime': 100,
            'size': 100,
            'description': 'alte beschreibung'
        }
        vec = {
            'type': 'vector',
            'file_id': 500,
            'etag': 'etag_initial',
            'frame': 0,
            'space': self.test_space,
            'dimensions': dim,
            'vector_data': v1
        }
        self.index.import_ndjson([json.dumps(doc), json.dumps(vec)])

        # Search finds "alte"
        r1 = self.index.query({'query': 'alte', 'phase': 'text'})
        self.assertEqual(len(r1['items']), 1)

        # 2. Update document with same etag, new description:
        # Document record replaces old doc and ALL old vectors of this file_id
        update_lines = [
            # Control records should be skipped silently
            json.dumps({'type': 'schemaVersion', 'version': 1}),
            json.dumps({'type': 'cursor', 'getCursor': 'abc'}),
            json.dumps({'type': 'namespace', 'ns': 'default'}),
            # Updated document
            json.dumps({
                'type': 'document',
                'file_id': 500,
                'path': '/Photos/update_test.jpg',
                'name': 'update_test.jpg',
                'kind': 'image',
                'etag': 'etag_initial',
                'mtime': 200,
                'size': 200,
                'description': 'neue beschreibung'
            }),
            # New vector for the updated document
            json.dumps({
                'type': 'vector',
                'file_id': 500,
                'etag': 'etag_initial',
                'frame': 0,
                'space': self.test_space,
                'dimensions': dim,
                'vector_data': v2
            })
        ]
        self.index.update_ndjson(update_lines)

        # "alte" should NOT match anymore; "neue" MUST match
        r_old = self.index.query({'query': 'alte', 'phase': 'text'})
        self.assertEqual(len(r_old['items']), 0)
        r_new = self.index.query({'query': 'neue', 'phase': 'text'})
        self.assertEqual(len(r_new['items']), 1)
        self.assertEqual(r_new['items'][0]['file_id'], 500)

        # Hybrid query matching the new vector v2 ([0.0, 1.0])
        r_hybrid = self.index.query({
            'query': 'neue',
            'phase': 'hybrid',
            'embedding_enabled': True,
            'space': self.test_space,
            'vector': [0.0, 1.0] + [0.0] * 126
        })
        self.assertEqual(r_hybrid['embedding_state'], 'ready')
        self.assertIn('embedding', r_hybrid['items'][0]['search_match']['sources'])

        # 3. Delete record
        del_line = json.dumps({'type': 'delete', 'file_id': 500})
        self.index.update_ndjson([del_line])

        # Document and vectors should be completely gone
        r_del = self.index.query({'query': 'neue', 'phase': 'text'})
        self.assertEqual(len(r_del['items']), 0)

        # 4. CLI --update subprocess test
        cli_db = Path(self.temp_dir.name) / "cli_update.db"
        p_init = subprocess.run(
            [sys.executable, str(ROOT_DIR / "worker" / "search_index.py"), "--database", str(cli_db), "--import"],
            input=json.dumps(doc) + "\n",
            capture_output=True,
            text=True
        )
        self.assertEqual(p_init.returncode, 0)

        p_up = subprocess.run(
            [sys.executable, str(ROOT_DIR / "worker" / "search_index.py"), "--database", str(cli_db), "--update"],
            input=json.dumps({'type': 'delete', 'file_id': 500}) + "\n",
            capture_output=True,
            text=True
        )
        self.assertEqual(p_up.returncode, 0)
        up_out = json.loads(p_up.stdout)
        self.assertEqual(up_out.get('updated'), 1)


if __name__ == '__main__':
    unittest.main()
