# Merge validation — 2026-10-09

Validation covers the integration branch, including the retained legacy API adapter. All media fixtures were generated colored PNG blocks in disposable Nextcloud instances. No private photos, videos or production Nextcloud data were accessed.

| Case | Server | Storage | Core assertions | HTTP route checks | Result |
| --- | --- | --- | --- | --- | --- |
| upgrade32 | Nextcloud 32.0.5 | MariaDB 10.11 + Redis 8.2 | 23 | 16 | Passed |
| fresh35 | Nextcloud 35.0.1 | SQLite | 23 | 16 | Passed |

The upgrade case installs both exact predecessor commits first, then migrates the merged app and adapter. Assertions verify unchanged analysis JSON, timestamps, encrypted settings, pause state, queue bytes and gallery identity. They exercise fresh and stale OCR, selected-folder scope, OR matching, pagination, deleted files, account isolation and account deletion. HTTP checks cover old/new OCS metadata and status endpoints, global search, processing workspace, valid/invalid worker tokens, disabled-owner refusal and canonical operation without the adapter. Tests allow the core's three-second AppConfig cache to expire after CLI enable/disable operations.

Additional local checks: 113 Python regression tests, 377 PHP assertions, seven Node tests and PHP syntax checks passed. Frontend bundles build with Node 24.19.0. Real browser verification on Nextcloud 35 covered gallery OCR search, viewer analysis, the processing workspace, Files status columns and the new Files sidebar tab. Nextcloud 33/34 and PostgreSQL were not individually exercised. No new model inference or physical-phone run was performed for this merge.

The Android repository remains clean at `f2d861afdecd1ea3a4e71e62b9e75102db246f8d`. The separate original Media AI repository remains clean at `5f0fe18ebedbd3f8360bde1ca8bb04b6bff1e536`. Both server predecessor histories are present in the combined repository.

Reproduction and deployment/rollback instructions: [MERGE.md](MERGE.md). This validation does not constitute installation on the user's production Nextcloud. Embedding retrieval is a separate future change.
