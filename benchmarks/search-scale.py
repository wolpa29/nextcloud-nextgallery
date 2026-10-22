#!/usr/bin/env python3
"""Measure the real SQLite search using disposable generated data, never a library."""
import argparse
import base64
from concurrent.futures import ProcessPoolExecutor
import json
import math
import multiprocessing
from pathlib import Path
import platform
import random
import resource
import shutil
import struct
import sys
import tempfile
import time

sys.path.insert(0, str(Path(__file__).resolve().parents[1] / 'worker'))
from search_index import SearchIndex, sqlite_vec

SPACE = 'a' * 64


def records(count, dimensions, frames, seed):
    rng = random.Random(seed)
    for file_id in range(1, count + 1):
        yield json.dumps({'type': 'document', 'file_id': file_id,
                          'path': f'/Synthetic/2026/10/synthetic-{file_id}.jpg',
                          'name': f'synthetic-{file_id}.jpg', 'etag': 'generated-v1',
                          'kind': 'image' if frames == 1 else 'video', 'mtime': 1,
                          'size': 1, 'metadata': {}})
        for frame in range(frames):
            vector = [rng.gauss(0, 1) for _ in range(dimensions)]
            norm = math.sqrt(sum(value * value for value in vector))
            vector = [value / norm for value in vector]
            yield json.dumps({'type': 'vector', 'file_id': file_id, 'etag': 'generated-v1',
                              'frame': frame, 'space': SPACE, 'dimensions': dimensions,
                              'vector_data': base64.b64encode(struct.pack(f'<{dimensions}f', *vector)).decode()})


def measure(args):
    path, dimensions, queries, seed = args
    rng = random.Random(seed)
    vector = [rng.gauss(0, 1) for _ in range(dimensions)]
    timings, first = [], None
    with SearchIndex(path) as index:
        for query in range(queries + 1):
            request = {'query': 'synthetic motif', 'roots': ['/Synthetic'], 'phase': 'hybrid',
                       'embedding_enabled': True, 'vector': vector, 'space': SPACE,
                       'vector_only': True, 'limit': 60, 'context': f'measure-{query}'}
            started = time.perf_counter()
            result = index.query(request)
            elapsed = time.perf_counter() - started
            if result['embedding_state'] != 'ready':
                raise RuntimeError(f"Vector search did not run: {result['embedding_state']}")
            if query == 0:
                first = elapsed
            else:
                timings.append(elapsed)
    ordered = sorted(timings)
    return {'first_query_seconds': first, 'warm_p50_seconds': ordered[(len(ordered) - 1) // 2],
            'warm_p95_seconds': ordered[max(0, math.ceil(.95 * len(ordered)) - 1)],
            'query_seconds': timings, 'peak_process_rss_mib': resource.getrusage(resource.RUSAGE_SELF).ru_maxrss / 1024,
            'returned_first_page': len(result['items'])}


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--items', type=int, default=10000)
    parser.add_argument('--dimensions', type=int, default=128)
    parser.add_argument('--frames', type=int, default=1)
    parser.add_argument('--accounts', type=int, default=2)
    parser.add_argument('--queries', type=int, default=5)
    parser.add_argument('--seed', type=int, default=20261022)
    parser.add_argument('--output', type=Path)
    args = parser.parse_args()
    if not (1 <= args.items <= 1000000 and 128 <= args.dimensions <= 4096 and 1 <= args.frames <= 20
            and 1 <= args.accounts <= 8 and 1 <= args.queries <= 100):
        parser.error('Invalid size, dimensions, frames, accounts or query count')
    if sqlite_vec is None:
        parser.error('Use an existing NextGallery Python runtime containing sqlite-vec')
    with tempfile.TemporaryDirectory(prefix='nextgallery-generated-search-') as folder:
        database = Path(folder) / 'generated.sqlite3'
        started = time.perf_counter()
        with SearchIndex(database) as index:
            index.import_ndjson(records(args.items, args.dimensions, args.frames, args.seed))
        build = time.perf_counter() - started
        accounts = []
        for number in range(args.accounts):
            target = Path(folder) / f'account-{number}.sqlite3'
            shutil.copyfile(database, target)
            accounts.append((str(target), args.dimensions, args.queries, args.seed))
        started = time.perf_counter()
        with ProcessPoolExecutor(max_workers=args.accounts, mp_context=multiprocessing.get_context('spawn')) as pool:
            results = list(pool.map(measure, accounts))
        result = {'kind': 'generated vector-search load; not image relevance or face accuracy',
                  'parameters': {key: value for key, value in vars(args).items() if key != 'output'},
                  'platform': platform.platform(), 'python': platform.python_version(),
                  'generation_and_import_seconds': build, 'index_bytes': database.stat().st_size,
                  'parallel_wall_seconds': time.perf_counter() - started, 'accounts': results,
                  'limits': 'First query is a new process/connection, not a guaranteed cold OS disk cache. Each account owns a private index.'}
    output = json.dumps(result, indent=2) + '\n'
    if args.output:
        args.output.write_text(output)
    print(output, end='')


if __name__ == '__main__':
    main()
