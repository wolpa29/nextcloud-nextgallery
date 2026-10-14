#!/usr/bin/env python3
"""Exercise the SDK and SQLite search engine from an extracted install archive without media or a server."""
import argparse
import base64
import json
import math
import os
from pathlib import Path
import platform
import struct
import subprocess
import sys
import tarfile
import tempfile
import unittest

parser = argparse.ArgumentParser()
parser.add_argument('archive', type=Path)
args = parser.parse_args()

with tempfile.TemporaryDirectory(prefix='nextgallery-package-check-') as temporary:
    with tarfile.open(args.archive) as archive:
        archive.extractall(temporary, filter='data')

    app_root = Path(temporary) / 'nextgallery'
    worker_dir = app_root / 'worker'
    runtime = worker_dir / 'lmstudio'

    # -------------------------------------------------------------------------
    # 1. Existing LM Studio SDK & embeddings setup tests (preserved)
    # -------------------------------------------------------------------------
    assert (worker_dir / 'requirements-embeddings.txt').is_file(), 'Automatic setup requirements missing'
    assert (worker_dir / 'requirements-embeddings-full.txt').is_file() and (worker_dir / 'embedding-full-model.json').is_file(), 'Full Gemma installer inputs missing'
    subprocess.run([sys.executable, str(worker_dir / 'embedding_setup.py'), '--help'], check=True, stdout=subprocess.DEVNULL)
    subprocess.run(['node', '--input-type=module', '-e', 'await import("@lmstudio/sdk")'], cwd=runtime, check=True)

    # A closed local port must produce the bridge's structured error event.
    # An absent SDK instead crashes before that event and falsely looks like a disconnect.
    request = {'base_url': 'ws://127.0.0.1:1', 'payload': {'model': 'synthetic', 'messages': []}}
    result = subprocess.run(['node', str(runtime / 'stream.mjs')], input=json.dumps(request),
                          text=True, capture_output=True, timeout=30)
    assert result.returncode == 1, 'Expected unavailable synthetic endpoint'
    events = [json.loads(line[6:]) for line in result.stdout.splitlines() if line.startswith('data: ')]
    assert len(events) == 1 and events[0].get('error', {}).get('code') == 'service_unavailable', 'SDK bridge failed before emitting its structured diagnostic'
    print('Extracted worker package: automatic setup entrypoint, SDK import and structured transport error passed')

    # -------------------------------------------------------------------------
    # 2. SQLite search engine, FTS5 Unicode & sqlite-vec 0.1.9 verification
    # -------------------------------------------------------------------------
    arch = platform.machine()
    arch = {'amd64': 'x86_64', 'arm64': 'aarch64'}.get(arch, arch)
    vendor_platform = worker_dir / 'vendor' / arch
    assert vendor_platform.is_dir(), f'Missing vendored sqlite-vec for architecture {arch}'
    assert (vendor_platform / 'sqlite_vec' / '__init__.py').is_file(), f'Missing sqlite_vec __init__.py for {arch}'
    assert (vendor_platform / 'sqlite_vec' / 'vec0.so').is_file(), f'Missing sqlite_vec vec0.so for {arch}'
    assert (worker_dir / 'vendor' / 'sqlite-vec-source' / 'sqlite-vec.c').is_file(), 'Missing sqlite-vec source amalgamation'
    assert (worker_dir / 'vendor' / 'sqlite-vec-source' / 'manifest.json').is_file(), 'Missing sqlite-vec source manifest'
    assert (app_root / 'docs' / 'SQL_SEARCH.md').is_file(), 'Missing docs/SQL_SEARCH.md in package'

    # Setup import paths without any pip install
    sys.path.insert(0, str(vendor_platform))
    sys.path.insert(0, str(app_root))

    import worker.search_index
    from worker.search_index import SearchIndex

    assert worker.search_index.sqlite_vec is not None, 'sqlite_vec failed to load from extracted vendor platform'

    test_db = Path(temporary) / 'test_extracted_search.sqlite3'
    test_space = 'f' * 64

    def encode_vector_128d(floats: list[float]) -> str:
        assert len(floats) == 128, 'Vector must have exactly 128 dimensions'
        norm = math.sqrt(sum(x * x for x in floats))
        assert norm > 1e-12, 'Vector norm must be positive'
        norm_floats = [x / norm for x in floats]
        blob = struct.pack('<128f', *norm_floats)
        return base64.b64encode(blob).decode('ascii')

    vec_doc1 = encode_vector_128d([1.0] + [0.0] * 127)
    vec_doc2_frame0 = encode_vector_128d([0.0, 1.0] + [0.0] * 126)
    vec_doc2_frame1 = encode_vector_128d([0.8, 0.6] + [0.0] * 126)

    class ExtractedSearchIndexUnitTest(unittest.TestCase):
        def test_roundtrip_import_fts_unicode_and_exact_128d_vectors(self):
            index = SearchIndex(test_db)
            try:
                # Ingest synthetic records: 1 image doc, 1 video doc with multiple video frames
                records = [
                    json.dumps({
                        'type': 'document',
                        'file_id': 1,
                        'path': '/Fotos/München/café_schönbrunn.jpg',
                        'name': 'café_schönbrunn.jpg',
                        'kind': 'image',
                        'etag': 'etag_img_1',
                        'mtime': 1715000000,
                        'size': 1024,
                        'tags': 'Sommer Urlaub',
                        'objects': 'Kaffee Gebäck',
                        'ocr': 'Café Schönbrunn Frühstücksmenü'
                    }),
                    json.dumps({
                        'type': 'document',
                        'file_id': 2,
                        'path': '/Videos/Wien/motorrad_donau.mp4',
                        'name': 'motorrad_donau.mp4',
                        'kind': 'video',
                        'etag': 'etag_vid_2',
                        'mtime': 1715000200,
                        'size': 2048,
                        'tags': 'Reise Österreich',
                        'objects': 'Motorrad Helm',
                        'ocr': 'Donaukanal Überblick'
                    }),
                    json.dumps({
                        'type': 'vector',
                        'file_id': 1,
                        'etag': 'etag_img_1',
                        'frame': 0,
                        'space': test_space,
                        'dimensions': 128,
                        'vector_data': vec_doc1
                    }),
                    json.dumps({
                        'type': 'vector',
                        'file_id': 2,
                        'etag': 'etag_vid_2',
                        'frame': 0,
                        'space': test_space,
                        'dimensions': 128,
                        'vector_data': vec_doc2_frame0
                    }),
                    json.dumps({
                        'type': 'vector',
                        'file_id': 2,
                        'etag': 'etag_vid_2',
                        'frame': 1,
                        'space': test_space,
                        'dimensions': 128,
                        'vector_data': vec_doc2_frame1
                    })
                ]

                imported = index.import_ndjson(records)
                self.assertEqual(imported, 5, 'Expected 5 imported NDJSON records')

                # 1. FTS Unicode search test
                fts_res = index.query({'query': 'Schönbrunn', 'roots': ['/'], 'phase': 'text', 'embedding_enabled': False})
                self.assertEqual(fts_res['total'], 1, 'Expected 1 hit for Unicode term Schönbrunn')
                self.assertEqual(fts_res['items'][0]['file_id'], 1)
                self.assertIn('ocr', fts_res['items'][0]['search_match']['sources'])

                # 2. Hybrid search test with exact 128D query vector matching videoFrame 1
                query_vector = [0.79, 0.61] + [0.0] * 126
                hybrid_res = index.query({
                    'query': 'motorrad',
                    'roots': ['/'],
                    'phase': 'hybrid',
                    'embedding_enabled': True,
                    'space': test_space,
                    'vector': query_vector
                })
                self.assertEqual(hybrid_res['mode'], 'hybrid')
                self.assertEqual(hybrid_res['embedding_state'], 'ready')
                self.assertGreaterEqual(hybrid_res['total'], 2)
                # Video document 2 should be ranked top due to frame 1 match
                top_hit = hybrid_res['items'][0]
                self.assertEqual(top_hit['file_id'], 2)
                self.assertIn('embedding', top_hit['search_match']['sources'])
                self.assertIn('objects', top_hit['search_match']['sources'])
            finally:
                index.close()

        def test_fresh_768d_unnormalized_vectors_and_normalization(self):
            fresh_db = Path(temporary) / 'fresh768.sqlite3'
            index = SearchIndex(fresh_db)
            try:
                dim = 768
                space = 'e' * 64
                # Raw unnormalized floats (norm = 5.0)
                raw_floats = [3.0, 4.0] + [0.0] * (dim - 2)
                raw_bytes = struct.pack(f'<{dim}f', *raw_floats)
                b64_vec = base64.b64encode(raw_bytes).decode('ascii')
                records = [
                    json.dumps({
                        'type': 'document',
                        'file_id': 100,
                        'path': '/Photos/landscape768.jpg',
                        'name': 'landscape768.jpg',
                        'kind': 'image',
                        'etag': 'etag_768',
                        'mtime': 1715000000,
                        'size': 4096
                    }),
                    json.dumps({
                        'type': 'vector',
                        'file_id': 100,
                        'etag': 'etag_768',
                        'frame': 0,
                        'space': space,
                        'dimensions': dim,
                        'vector_data': b64_vec
                    })
                ]
                count = index.import_ndjson(records)
                self.assertEqual(count, 2)
                # Query with unnormalized 768D query vector
                q_vec = [6.0, 8.0] + [0.0] * (dim - 2)
                res = index.query({
                    'query': '',
                    'roots': ['/'],
                    'phase': 'hybrid',
                    'embedding_enabled': True,
                    'space': space,
                    'vector': q_vec
                })
                self.assertEqual(res['total'], 1)
                self.assertEqual(res['mode'], 'hybrid')
                self.assertEqual(res['embedding_state'], 'ready')
                self.assertEqual(res['items'][0]['file_id'], 100)
                self.assertIn('embedding', res['items'][0]['search_match']['sources'])
            finally:
                index.close()

    suite = unittest.defaultTestLoader.loadTestsFromTestCase(ExtractedSearchIndexUnitTest)
    runner = unittest.TextTestRunner(verbosity=2)
    test_result = runner.run(suite)
    assert test_result.wasSuccessful(), f'Extracted worker search index unit test failed: {test_result.errors + test_result.failures}'

    # -------------------------------------------------------------------------
    # 3. CLI subprocess execution: integer exit code 0, JSON emission, fresh 768D normalization
    # -------------------------------------------------------------------------
    sub_env = os.environ.copy()
    sub_env['PYTHONPATH'] = f"{vendor_platform}:{app_root}"
    sub_env['PYTHONDONTWRITEBYTECODE'] = '1'

    cli_fresh_db = Path(temporary) / 'cli_fresh768.sqlite3'
    dim768 = 768
    space768 = 'd' * 64
    unnormalized_768 = [5.0, 12.0] + [0.0] * (dim768 - 2)
    b64_768 = base64.b64encode(struct.pack(f'<{dim768}f', *unnormalized_768)).decode('ascii')

    cli_ndjson = '\n'.join([
        json.dumps({
            'type': 'document',
            'file_id': 50,
            'path': '/Gallery/fresh768_cli.jpg',
            'name': 'fresh768_cli.jpg',
            'kind': 'image',
            'etag': 'etag_cli768',
            'mtime': 1715000000,
            'size': 2048,
            'ocr': '768D CLI Ingestion'
        }),
        json.dumps({
            'type': 'vector',
            'file_id': 50,
            'etag': 'etag_cli768',
            'frame': 0,
            'space': space768,
            'dimensions': dim768,
            'vector_data': b64_768
        })
    ])

    # 3a. CLI --import subprocess: must exit with integer 0 and emit valid JSON {"success": true, "imported": 2}
    proc_import = subprocess.run(
        [sys.executable, str(worker_dir / 'search_index.py'), '--database', str(cli_fresh_db), '--import'],
        input=cli_ndjson,
        capture_output=True,
        text=True,
        env=sub_env
    )
    assert proc_import.returncode == 0, f"CLI --import failed with exit code {proc_import.returncode}: {proc_import.stderr}"
    res_import = json.loads(proc_import.stdout)
    assert res_import.get('success') is True and res_import.get('imported') == 2, 'CLI --import failed to emit valid JSON success payload'

    # 3b. CLI --query subprocess with unnormalized 768D query vector: must exit with integer 0 and emit JSON
    query_768_payload = {
        'query': 'fresh768',
        'roots': ['/'],
        'phase': 'hybrid',
        'embedding_enabled': True,
        'space': space768,
        'vector': [10.0, 24.0] + [0.0] * (dim768 - 2)
    }
    proc_query = subprocess.run(
        [sys.executable, str(worker_dir / 'search_index.py'), '--database', str(cli_fresh_db), '--query'],
        input=json.dumps(query_768_payload),
        capture_output=True,
        text=True,
        env=sub_env
    )
    assert proc_query.returncode == 0, f"CLI --query failed with exit code {proc_query.returncode}: {proc_query.stderr}"
    cli_response = json.loads(proc_query.stdout)
    assert cli_response['total'] == 1, 'CLI query returned 0 results'
    assert cli_response['mode'] == 'hybrid', f"Expected hybrid mode, got {cli_response['mode']}"
    assert cli_response['embedding_state'] == 'ready', f"Expected ready state, got {cli_response['embedding_state']}"
    assert cli_response['items'][0]['file_id'] == 50, 'Expected doc 50 as top match for 768D vector query'
    assert 'embedding' in cli_response['items'][0]['search_match']['sources'], 'Expected embedding source in CLI response'

    # 3c. CLI --clean subprocess: must exit with integer 0 and emit valid JSON {"success": true}
    proc_clean = subprocess.run(
        [sys.executable, str(worker_dir / 'search_index.py'), '--database', str(cli_fresh_db), '--clean'],
        capture_output=True,
        text=True,
        env=sub_env
    )
    assert proc_clean.returncode == 0, f"CLI --clean failed with exit code {proc_clean.returncode}: {proc_clean.stderr}"
    res_clean = json.loads(proc_clean.stdout)
    assert res_clean.get('success') is True, 'CLI --clean failed to emit valid JSON success payload'

    # 3d. CLI --query subprocess on 128D database: must exit with integer 0 and emit JSON
    query_128_payload = {
        'query': 'café schönbrunn',
        'roots': ['/'],
        'phase': 'hybrid',
        'embedding_enabled': True,
        'space': test_space,
        'vector': [1.0] + [0.0] * 127
    }
    proc_128 = subprocess.run(
        [sys.executable, str(worker_dir / 'search_index.py'), '--database', str(test_db), '--query'],
        input=json.dumps(query_128_payload),
        capture_output=True,
        text=True,
        env=sub_env
    )
    assert proc_128.returncode == 0, f"CLI 128D query failed with exit code {proc_128.returncode}: {proc_128.stderr}"
    res_128 = json.loads(proc_128.stdout)
    assert res_128['total'] >= 1 and res_128['items'][0]['file_id'] == 1

    print('Extracted worker package: search_index unit tests, FTS5 Unicode, 128D vectors, video frames, fresh 768D normalization, and CLI exit-code 0 JSON subprocesses passed')
