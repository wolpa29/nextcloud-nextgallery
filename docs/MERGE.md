# Unified NextGallery Server 0.2.0

NextGallery Server now contains the browser gallery and the former Media AI extension. The native Android repository is unchanged. Both predecessor Git histories are reachable from the integration branch: gallery `bd8e034fcf9f1244f620ae96f725d2770a75c6be` and Media AI `5f0fe18ebedbd3f8360bde1ca8bb04b6bff1e536`.

## Repository and runtime

- `appinfo/`, `lib/`, `templates/`, `frontend/`: one Nextcloud app, technical ID `chronicle`, namespace `OCA\Chronicle`.
- `lib/AI/` and `lib/Controller/Ai*`: analysis, OCR, file metadata, inventory, worker control and the existing Nextcloud Files integration.
- `worker/`: the original Python worker and LM Studio transport, running in the existing separate background service.
- `pipeline/`: portable controller, examples, regression suites and historical documentation. It imports `../worker/`; there is no second worker implementation.
- `compat/media_ai/`: optional forwarding adapter for the existing `media_ai` addresses. It has no gallery, worker, settings, migrations, search provider or processing logic. Existing Android versions need this adapter because their metadata importer uses those addresses. Fresh clients can use `chronicle` directly.

The former repository is preserved. This change does not rename or delete a GitHub repository, overwrite its main branch or change the installed production server. Continue development in the NextGallery repository; the imported pipeline is maintained there.

## Preserved data and configuration

The existing `media_ai_meta` table is adopted in place. Rows, file IDs, hashes, ETags, complete JSON, revisions and timestamps are retained. MariaDB's full-text index is retained; fresh MariaDB installations create it. SQLite and PostgreSQL use the portable text-search fallback.

The migration copies the old settings and encrypted worker/model credentials into `chronicle` keys prefixed `ai_`, only if the target key does not exist. It neither decrypts nor prints these secrets. An explicitly cleared new secret remains cleared. Legacy preferences remain readable until users change them. Existing gallery settings, cache identities and dates remain unchanged.

`data/.media_ai` is retained, including SQLite queue, control/pause state, bootstrap and logs. The new worker accepts `app_id: chronicle` in its bootstrap. Its HTTP transport uses the new app, while its native queue scope deliberately retains the old address as an identity. The publication identity used in the analysis revision is retained as well; an end-to-end regression confirms completed synthetic images do not trigger new inference after the app move. Existing completed analyses are consequently not invalidated by the URL move. The same existing service name and process lock are used; do not run two worker services.

Gallery display folders and the admin's analysis folder selection retain their original meanings. Migrating does not implicitly authorize analyzing another user's folders or newly selected gallery folders. Processing still uses the existing single configured target user; it has not become a multi-user scheduler.

## Shared UI and APIs

There is one Nextcloud navigation entry, **NextGallery Server**. Its sidebar includes **KI-Verarbeitung** for administrators and the configured analysis owner. This opens the processing workspace inside the same extension, with a link back to the gallery. Other users can browse their own galleries and existing metadata without controlling someone else's analysis.

The gallery's **Suche** combines filenames/paths with fresh description, tags, objects and OCR in the selected gallery. Word matches are combined with OR, then ranked with more weight for filenames and tags. Results are paginated without a fixed total-result cap. The viewer lazily loads description/keywords/OCR. Existing timeline, map and offline preview behavior remains available without a model connection or worker. Offline browser search currently covers saved names/paths; downloading AI vectors is a separate future step.

This is lexical search. **Embeddings, embedding downloads and changes to Android are not part of this integration.** The shared search service is now the place to add dense retrieval later. No large-library speedup is claimed: substring search over text still needs a separate benchmark/index strategy.

| Purpose | Canonical address | Existing address with adapter |
| --- | --- | --- |
| Gallery/settings/items | `/index.php/apps/chronicle/`, `/api/settings`, `/api/items` | Unchanged |
| Gallery search | `/index.php/apps/chronicle/api/search?query=…&offset=…&limit=…` | New API; Android not modified |
| Viewer analysis | `/index.php/apps/chronicle/api/analysis/{id}` | New, constrained to the user's selected gallery |
| Processing workspace | `/index.php/apps/chronicle/ai` | `/index.php/apps/media_ai/` redirects here |
| Metadata OCS | `/ocs/v2.php/apps/chronicle/ai/api/v1/{metadata,statuses,resolve}` | `/ocs/v2.php/apps/media_ai/api/v1/…` forwards |
| Worker API | `/index.php/apps/chronicle/ai/worker/…` | `/index.php/apps/media_ai/worker/…` forwards |
| Worker bootstrap command | `occ chronicle:ai-setup` | `occ media-ai:setup` remains an alias |
| Nextcloud unified search provider | Provider ID `media_ai`, owned by `chronicle` | Provider ID retained |

Search and metadata responses revalidate current permissions, selected folders and file ETags. Stale/deleted/inaccessible files do not return old AI text. Deleting an account clears its AI records as well as its gallery index. The forwarding adapter refuses operations when NextGallery is disabled. The main app works with the adapter disabled. Nextcloud 35 uses its new Files v4 API for columns/sidebar; the original v3 integration is retained for earlier releases. Nextcloud 32 and 35 were exercised; 33/34 were not individually tested.

## Build and package

Build with Node.js 24+ (the Nextcloud 35 Files library requires it). Runtime: Nextcloud 32–35, PHP 8.2+ and the installed Nextcloud's actual PHP requirements. The worker needs its existing Python, Node.js and FFmpeg dependencies; gallery use does not require installing them.

```sh
npm ci --ignore-scripts
npm run build
npm test
npm ci --ignore-scripts --prefix worker/lmstudio
node --test pipeline/tests/lmstudio_transport.test.mjs
python3 -m pip install -r worker/requirements.txt
PYTHONPATH=pipeline python3 -m unittest discover -s pipeline/tests -v
python3 scripts/package-app.py artifacts/packages
```

The package command creates a main app archive and a separate small compatibility archive, each with the correct top-level app folder. It includes worker sources and locked dependency manifests, not host dependencies, caches or credentials. Worker dependencies are installed by the installer below.

## Migration of an existing installation

1. Pause processing in the existing UI and wait for the current image to finish. Stop `nextcloud-media-ai.service` so its executable can be replaced safely.
2. Back up the Nextcloud database, both old app directories and `data/.media_ai`. This is the rollback point; include Nextcloud app configuration in the database backup.
3. Unpack the new `chronicle` package into the old gallery's app location. If only Media AI was installed, install `chronicle` first. Preserve normal Nextcloud app ownership. Replace the old **full** `media_ai` app with the compatibility adapter when retaining old Android clients; do not leave its old worker/UI running beside the merged app.
4. Run `occ upgrade`, then `occ app:enable chronicle`; enable `media_ai` when using the compatibility adapter. Both apps' old routes are served by the new implementation.
5. Run `pipeline/scripts/install-worker.sh NEXTCLOUD_ROOT HTTPS_URL [EXISTING_CA_FILE]`. It finds `chronicle`, writes a bootstrap using the preserved token and state directory, installs dependencies and updates the existing service's executable path. Supply the existing CA file if required. The installer starts the daemon; the preserved pause/control state still governs processing.
6. Check the processing workspace, existing completed analyses and old metadata API. Configure gallery folders if this user has not previously used the gallery. Resume processing explicitly when ready.

On a fresh server, install only `chronicle` unless legacy clients need the adapter. No model downloads or processing start just by enabling the gallery. Existing files and external application metadata are never rewritten by the migration.

Rollback restores the old app directories, database and worker state together, then the old worker service executable. Do not run `app:remove media_ai` as part of migration: replace its directory with the adapter to preserve installed-client compatibility and the backup path.

## Verification

`python3 scripts/test-integration.py --case upgrade32` starts a disposable Nextcloud 32.0.5/MariaDB/Redis instance with the two exact predecessor apps, seeds generated colored PNG fixtures, settings, encrypted credentials and queue state, then upgrades both packages. `--case fresh35` exercises a fresh Nextcloud 35.0.1/SQLite installation. Each checks retained data, filename backfill, fresh/stale OCR, selected-folder scope, pagination, cross-account isolation, account deletion, legacy OCS and token-authenticated worker routes. All containers and volumes are deleted by default; `--keep` retains them for manual UI verification. Ports bind only to localhost. The runner never connects to the user's Nextcloud or reads private media.

Physical Android tests and deployment to the user's installed Nextcloud are outside this verification. The native Android repository stays unchanged.
