# NextGallery Server 0.3.0 migration

One maintained app combines the gallery, AI processing, descriptions, tags, objects and OCR. Repository: `wolpa29/nextcloud-nextgallery`; Nextcloud ID: `nextgallery`; PHP namespace: `OCA\Nextgallery`. The predecessor pipeline repository is archived. Both Git histories remain available.

## Canonical interfaces

| Purpose | Address |
| --- | --- |
| Gallery | `/index.php/apps/nextgallery/` |
| Gallery search | `/index.php/apps/nextgallery/api/search?query=…` |
| Viewer metadata | `/index.php/apps/nextgallery/api/analysis/{id}` |
| Processing workspace | `/index.php/apps/nextgallery/ai` |
| OCS metadata | `/ocs/v2.php/apps/nextgallery/ai/api/v1/{metadata,statuses,resolve}` |
| Worker | `/index.php/apps/nextgallery/ai/worker/…` |
| Setup | `occ nextgallery:ai-setup` |
| Global search provider | `nextgallery_ai` |

No legacy redirects, forwarding app or command aliases are installed. Android source must use these addresses; old Android builds need an update for server mode and AI imports. The Android application package remains unchanged so installed data and upgrade signing continue to work. No Android release or forced-update policy is published by this migration.

## Preserved data

The one-time migrations copy catalog rows, analysis IDs, ETags, SHA-256 values, revisions, complete JSON and timestamps to `nextgallery_media`, `nextgallery_users` and `nextgallery_ai_meta`. Before dropping predecessor tables, all their columns and row counts must produce identical fingerprints. A mismatch aborts cleanup.

Gallery preferences, scan cursors and cache epoch are copied. AI settings and encrypted credentials retain their bytes and Nextcloud encryption context. Previously cleared integrated secrets take precedence over predecessor settings. AI language preferences are transferred. Old app registrations and migration entries are then retired; uninstall hooks are never invoked.

Before enabling the new app, pause the existing worker at a file boundary and stop its service. Back up the complete database, app directories, configuration, service and worker state. Move `data/.media_ai` to `data/.nextgallery_ai` without changing its SQLite queue or saved results. Move the environment to `/opt/nextgallery-ai-venv`, recreate it at the new path if its generated entry points contain old paths, and use `nextgallery-ai.service`. Keep rollback backups outside Nextcloud's app directories.

Run `occ nextgallery:ai-setup --url https://YOUR-NEXTCLOUD --ca-file /path/to/public-ca.crt`. The command preserves the existing queue/publication identity in `scope_identity`, while all network requests use the canonical new worker endpoint. That opaque identity prevents completed files from being analyzed again merely because the app name changed. A new installation uses its canonical endpoint for both identities.

Compare all metadata, catalog/preferences, credential/settings fingerprints and the queue contents before resuming processing. Resume only the previous processing state; do not reset or retry the whole queue. User media is never moved or modified by this migration.

## Behavior and verification

Gallery folders determine displayed content; the AI owner's selected folders determine processing. AI processing remains optional and single-owner. Gallery search combines fresh filenames, description, tags, objects and OCR with OR matching and ranking, validates access and ETags, and supports pagination. Embeddings remain a separate future change.

Build with Node.js 24+: `npm ci --ignore-scripts && npm run build && npm test`. Package with `python3 scripts/package-app.py OUTPUT`. Only one `nextgallery` archive is produced. Install the bundled worker using `pipeline/scripts/install-worker.sh` after migration and backup.

Disposable synthetic integration tests cover the original two apps on Nextcloud 32/MariaDB, the previous merged app on Nextcloud 32/MariaDB, and a fresh Nextcloud 35/SQLite installation: `python3 scripts/test-integration.py --case upgrade32`, `--case rename32`, `--case fresh35`. They check byte-preserving migration, encryption, preferences, queue preservation, scope/freshness, user isolation and rejection of retired endpoints. No private photos are used. Nextcloud 33/34 and physical Android devices are not independently covered.
