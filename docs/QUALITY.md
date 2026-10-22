# Repeatable quality and load checks

NextGallery's checks distinguish API correctness, recognition/retrieval quality,
and resource use. A passing publication test is not a recognition accuracy score.
All commands below can use generated data; no private library is required.

## Browser and storage behavior

Install the locked development dependencies and the browser used by CI:

```sh
npm ci --ignore-scripts
npx playwright install --with-deps chromium
node scripts/required-browser-tests.mjs
```

The gallery resilience tests render the actual PHP template and bundle the current
frontend sources. PHP CLI must be available. `NEXTGALLERY_TEST_PHP` can specify a
JSON command array when PHP is provided by an existing disposable test container.
`PLAYWRIGHT_MODULE` and `PLAYWRIGHT_EXECUTABLE` can select an existing browser
runtime. Required CI browser execution must not silently skip unavailable tests.

The browser contracts cover retained photos after failed refreshes, page retries,
offline cancellation/download errors/quota errors, atomic publication rollback,
removal during staging, compatibility with previously saved version-one offline shells, overlapping preview IDs, concurrent stages, viewer page boundaries,
the dismissible processing hint, and pausing hidden map pagination. They use generated SVGs. Existing People
contracts additionally cover account/gallery scope, delayed responses and merges.

Offline updates stage previews separately. A complete download replaces the active
collection in one IndexedDB transaction. Cancellation or a failed preview keeps
the preceding collection; the UI explains this. Staging requires temporary extra
space. Browser eviction and downloaded HTML copies retain their documented device
storage behavior. A completed collection is still a preview collection, not an
original-media backup.

## Labelled retrieval and identity results

Run the fixed example without downloading media or invoking a model:

```sh
python3 benchmarks/evaluate.py benchmarks/evaluation-example.json
```

For an actual evaluation, record immutable dataset provenance, labels, model
version, settings and preprocessing/vector-space revision before examining the
results. Use a separate labelled evaluation set when tuning thresholds. The
example is deliberately imperfect so that missed hits and false merges are
visible; it is not evidence of model quality.

Each search query records its ID, all relevant media IDs, and the ordered returned
IDs. Queries with no matching motif require `absent: true`. The evaluator reports
macro Recall@1/5/10/20/50, the fraction of queries containing every labelled hit,
MRR, candidate coverage and the absent-query candidate rate. An absent motif that
still returns similar suggestions is explicitly counted rather than described as
a verified content match. No-data metrics are `null`, not a perfect score.

Identity evaluation uses detection IDs, reference identities and assigned groups.
It reports pair precision/recall and numbers of false merge/split pairs. Unassigned
detections remain individual singletons. This avoids accidentally treating all
unassigned faces as one person. Original images are not read by the evaluator.

Suitable permitted datasets should include side views, occlusion, low light,
similar people, different ages, and known negative examples. Search evaluation
should include German/English phrases, exact OCR values, multiple relevant files,
brief video scenes, and motifs absent from the collection. Public model benchmarks
and four formal public portraits remain smoke evidence only.

## SQLite search load

Use an existing NextGallery Python runtime containing `sqlite-vec`:

```sh
python3 benchmarks/search-scale.py --items 10000 --accounts 2 --queries 5
python3 benchmarks/search-scale.py --items 100000 --accounts 2 --queries 5
python3 benchmarks/search-scale.py --items 10000 --frames 5 --accounts 2 --queries 5
```

The script builds disposable private indexes, executes the actual vector-search
engine in parallel processes and removes the indexes afterwards. It reports
generation/import duration, index size, per-account first-query time, warm p50/p95,
peak process RSS, dimensions, frames, seed and runtime platform. The first query
uses a fresh connection/process, but is not a guaranteed cold OS disk-cache run.

Local generated checks on 22 October 2026, 128 dimensions, two parallel accounts:

| Input per account | Warm p50 | Warm p95 | Peak process RSS |
| --- | --- | --- | --- |
| 10,000 generated media, one vector each | 0.064–0.065 s | 0.065 s | about 65 MiB |
| 100,000 generated media, one vector each | 0.724–0.739 s | 0.767–0.771 s | about 216 MiB |
| 10,000 generated videos, five vectors each | 0.134–0.135 s | 0.144–0.148 s | about 119 MiB |

These are small local load samples, not production service guarantees. The first
case used three warm queries; the larger and video cases used five. Real model
dimensions, OCR, file authorization, Python process startup from PHP, storage and
other workloads add costs. This benchmark measures SQLite engine work, not the
complete HTTP request or image relevance. Do not introduce arbitrary candidate
limits or change model spaces merely to improve this number.

## Review and deployment boundaries

Permission/ETag/share-revocation checks must pass after performance changes.
Generation and rule-revision checks remain authoritative for stale worker results.
No test may reset production state. Deployment, model rebuilds, publishing a mobile
release and measuring on a physical device are separate actions and evidence.
