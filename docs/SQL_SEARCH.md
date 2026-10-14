# SQL Search Architecture: SQLite, FTS5, and sqlite-vec

[Back to the overview](../README.md) · [Administration and technical reference](ADMINISTRATION.md) · [Visual Embeddings](EMBEDDINGS.md)

NextGallery uses a derived, transactional per-account search index based on SQLite 3, FTS5 (Trigram), and the native C extension `sqlite-vec` (version 0.1.9) for full-text and hybrid vector search.

---

## 1. Core Principles and Data Ownership

- **Canonical data:** Original media remains in Nextcloud storage; cached EXIF/GPS remains in Nextcloud file metadata. Descriptions, tags, object labels and OCR remain in `*PREFIX*nextgallery_ai_meta`, and published vectors remain in `*PREFIX*nextgallery_ai_vectors`. Indexing does not replace or migrate these sources.
- **Derived Per-Account Search Index (`index.sqlite3`):** The search index file (`index.sqlite3`) and its accompanying session file (`index.sqlite3.sessions`) are derived, disposable per-user caches located under `DATADIRECTORY/.nextgallery_search/SHA256(account-id)/` with restrictive file permissions (`0600`).
- **No AI Reanalysis:** Existing metadata, OCR, and embedding vectors are never re-analyzed or recomputed when creating or updating the search index. The index is populated entirely by streaming existing data from the canonical Nextcloud database.
- **Account Deletion and Cleanup Isolation:**
  - When NextGallery cleans up or rebuilds a search index, it only manipulates the derived `index.sqlite3` and `index.sqlite3.sessions` database files; the cleanup process itself never touches original media files.
  - When an account is deleted, Nextcloud's user lifecycle listeners (`UserDeletedEvent`) trigger the removal of the user's derived search index directory (`SearchIndexChanges::forget`). Any deletion of original files is governed exclusively by standard Nextcloud user lifecycle management, not by the search indexer.
  - Index maintenance uses revision fingerprints, query initialization, and background cron jobs.

---

## 2. Indexing and Data Synchronization

### Streamed Export in 20-Item Batches
The PHP service (`OCA\Nextgallery\Service\SearchIndex`) exports indexed media and metadata in small batches of 20 items:
- The readable Nextcloud catalog is traversed in bounded 2,000-file pages; AI metadata is still fetched in batches of 20. Larger catalog pages reduce repeated SQL offset scans on large accounts while retaining Nextcloud's normal permission-aware search for shared and mounted folders.
- Written as line-delimited JSON (NDJSON) to a private `0600` temporary file inside the account's index directory, then streamed as a file resource over standard input (`stdin`) to Python. The temporary export is removed on success and failure. This avoids Symfony's 200 ms polling delay for every individual generator record.
- Keeps PHP memory consumption (`memory_limit`) strictly bounded, allowing smooth indexing even across large collections with tens of thousands of media files.
- Emits separate record types for documents (`{"type": "document", ...}`), vectors (`{"type": "vector", ...}`), and deletions (`{"type": "delete", "file_id": N}`).

### Automatic Per-Account Indexing and Verification
- **Search-Time Verification:** Every query automatically verifies the account's revision fingerprint (combining file catalog revisions with `MAX(updated_at)` from `nextgallery_ai_meta` and `nextgallery_ai_vectors`).
- **Atomic Rebuilding:** When an index is missing or out of date, it is built atomically (writing to a temporary `.sqlite3` file and renaming it on completion) or incrementally updated via `--update`.
- **Background Cron Jobs:** Regular Nextcloud cron jobs (`IndexJob`) update pending changes, refresh catalogs older than five minutes, and purge expired sessions. Normal queries do not trigger a full catalog sweep merely because five minutes have elapsed; missing indexes, recorded catalog changes and out-of-band AI changes still trigger immediate synchronization.

---

## 3. Search Engine and Process Architecture

### No Permanent Daemon
NextGallery runs **no permanent background daemon** for search. Instead, it invokes the standalone Python search engine (`worker/search_index.py`) on demand as a short-lived CLI process via Symfony's `Process`:

```sh
python3 worker/search_index.py --database /path/to/user/index.sqlite3 --query
```

The JSON request is written to `stdin`, and the structured JSON result is returned via `stdout` with integer exit code `0`.

### No Raw Vector Calculations in PHP
PHP never computes high-dimensional vector math or cosine similarities in memory. The query embedding vector is passed directly to the Python engine, which executes native C cosine distance calculations inside SQLite via `sqlite-vec`:

```sql
SELECT d.file_id, d.path, d.etag, MIN(vec_distance_cosine(v.vector_data, ?)) AS min_dist
FROM vectors v
JOIN documents d ON d.file_id = v.file_id AND d.etag = v.etag
WHERE v.space = ? AND v.dimensions = ?
GROUP BY d.file_id
ORDER BY min_dist ASC;
```

### Python Runtime Requirements and Path Discovery
The search engine requires a standard Python 3 runtime (Python 3.11+ recommended) with `sqlite3` built with FTS5 support:
1. **Candidate Path Discovery:**
   - `/opt/nextgallery-ai-venv/bin/python` (preferred isolated worker environment)
   - `/usr/bin/python3` (system Python)
2. **System Configuration Override:**
   Administrators can explicitly configure the Python binary path:
   ```sh
   sudo -u www-data php occ config:system:set nextgallery_search_python --value=/custom/bin/python3
   ```

### Bundled Manylinux Binaries for Linux x86_64 and aarch64
Precompiled `vec0.so` binaries are bundled directly within the app:
- `worker/vendor/x86_64/sqlite_vec/vec0.so`
- `worker/vendor/aarch64/sqlite_vec/vec0.so`

PHP inspects the host CPU architecture (`php_uname('m')`) and configures `PYTHONPATH=$worker/vendor/$arch`. No `pip install` and no compiler are needed on supported Linux architectures.

> [!IMPORTANT]
> **Honest System Requirements Notice:**
> NextGallery does **not** provide a universal rootless installer that installs Python runtimes or compilers on arbitrary operating systems. The host system must provide a working Python 3 installation with SQLite FTS5 trigram support (SQLite 3.34 or newer). Unsupported operating systems (such as macOS or Windows servers) require manual provisioning of compatible `sqlite-vec` binaries.

---

## 4. Search Semantics and Scoring

### Lexical Full-Text Search (FTS5 and Exact Substring Fallback)
- **Tokenization:** Queries are split into up to 16 distinct alphanumeric tokens.
- **Trigram Acceleration:** Words with 3 or more characters utilize SQLite's FTS5 trigram virtual table (`tokenize='trigram'`).
- **Exact Substring Fallback:** Shorter terms (< 3 characters) fall back to exact case-insensitive substring matching (`INSTR()`).
- **Field Weights:**
  - File name: **8**
  - Tags: **3**
  - Detected objects: **3**
  - Relative path: **2**
  - OCR text: **2**
  - Metadata (EXIF/GPS): **1**
  - AI Description: **1**

### Vector Search and Video Frame Aggregation
- **Images:** Single 128D–4096D vector per image (typically 768D for Gemma models).
- **Videos with Multiple Frames:** Up to 20 frames sampled per video.
- **Maxframe Aggregation:** The video's similarity score is determined by its **single best-matching frame** (`MIN(vec_distance_cosine)` grouped by `file_id`), preventing dilution from averaging irrelevant scenes.
- **Normalization:** Vectors are automatically normalized to unit length on import and query.

### Hybrid Rank Fusion (Reciprocal Rank Fusion, RRF k=60)
When both lexical and semantic candidates exist, scores are combined using RRF with constant $k = 60$:

$$\text{Score}_{\text{RRF}}(d) = \sum_{m \in \{\text{lexical}, \text{vector}\}} \frac{1}{60 + \text{Rank}_m(d)}$$

Ties are deterministically broken by `file_id ASC`. All eligible items remain pageable without arbitrary cutoffs.

### Graceful Lexical Fallback
If visual embeddings are disabled, the model is changed, or the inference endpoint is temporarily unreachable (`not_indexed`, `model_changed`, `unavailable`), the search engine seamlessly falls back to pure lexical ranking without failing the user request.

---

## 5. Pagination, Session Security, and Isolation

### Opaque Frozen Page Sessions
To guarantee consistent ordering and pagination across updates, query results are snapshotted in an attached database (`index.sqlite3.sessions`):
- **Opaque Token:** Paged queries return a 32-character hexadecimal token (`uuid.uuid4().hex`).
- **15-Minute TTL:** Sessions expire automatically after 15 minutes.
- **16 Snapshot Limit:** A maximum of 16 sessions are retained per account to prevent disk consumption from query bursts.
- **Zero Sensitive Data in Session Store:**
  - Sessions store only a SHA-256 fingerprint of the query arguments for validation.
  - Result rows store only `file_id`, `path`, `etag`, and matched `sources` names. No query text, OCR content, or embedding vectors are stored in the session database.
- **Automated Cleanup:** Stale sessions are purged automatically during queries, via `--clean`, and during background cron runs.

---

## 6. Upstream Provenance and Licensing

- **Upstream Project:** [asg017/sqlite-vec](https://github.com/asg017/sqlite-vec)
- **Version:** `0.1.9`
- **Git Commit:** `e9f598abfa0c06b328d8fe5da9c3760cce74be10`
- **Author:** Alex Garcia ([alexgarcia.xyz](https://alexgarcia.xyz/))
- **Licenses:** Dual-licensed under the **MIT License** ([docs/licenses/sqlite-vec-mit.txt](licenses/sqlite-vec-mit.txt)) and the **Apache License, Version 2.0** ([docs/licenses/sqlite-vec-apache.txt](licenses/sqlite-vec-apache.txt)).
- **Corresponding Source:** Amalgamation C source (`sqlite-vec.c`, `sqlite-vec.h`), licenses, and build manifests are located under `worker/vendor/sqlite-vec-source/`.
- **Legal Notices:** Recorded in [NOTICE](../NOTICE).

---

## Deutsche Kurzübersicht (Zusammenfassung)

NextGallery implementiert die Volltext- und Vektorsuche über einen flüchtigen, abgeleiteten SQLite-Index (`index.sqlite3` und `index.sqlite3.sessions`) pro Benutzerkonto. Alle kanonischen Originaldaten und Vektoren verbleiben in der Nextcloud-Hauptdatenbank; es findet keine vollständige Datenbankmigration statt. Der Suchdienst startet keinen permanenten Daemon, sondern führt kurze Python-Prozesse über Symfony `Process` aus (`worker/search_index.py`). Vorkompilierte `vec0.so`-Bibliotheken für Linux x86_64 und aarch64 sind gebündelt. Die Hostumgebung muss Python 3 mit FTS5-Unterstützung bereitstellen. Suchergebnisse werden über RRF ($k=60$) kombiniert; paginierte Sitzungen verfallen nach 15 Minuten und speichern weder Abfragetexte noch Vektoren. Bei Kontolöschung wird der abgeleitete Index bereinigt; die Löschung von Originalmedien unterliegt dem regulären Nextcloud-Konto-Lebenszyklus.
