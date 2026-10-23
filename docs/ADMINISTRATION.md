# Administration and technical reference

[Back to the overview](../README.md) · [Version history](CHANGELOG.md)

This guide keeps the detailed setup, usage and maintenance notes separate from the short project overview. For installations using predecessor app names, read the [migration guide](MERGE.md) before updating.

The server companion is AGPL-3.0-or-later. The separate proprietary Android app has its own licence and storage.

## Use

Open **NextGallery Server → Einstellungen → Galerien verwalten**, name a gallery, choose its Nextcloud folders and save. Use the gallery name in Gallery, Search or Map to switch collections. Each gallery has its own folders and saved previews; display, dates, theme and language settings are shared across the account. Folder dates are authoritative by default. **Einstellungen** exposes order, date groups, two to six columns, theme and language directly; changes save automatically. The compact **Darstellung** dialog shares the same gallery choices. **System** follows the Nextcloud theme, falling back to the operating system; explicit light/dark choices override it. Every signed-in user can open **KI-Verarbeitung** for their own runs, files and data. Administrators land directly in the server settings; **Galerie** remains available through its link. Model configuration is administrator-only, including the API. **Karte** shows media with GPS; **Offline** manages the selected gallery’s previews, up to 5,000 within a 300 MB budget.

Use **Offline → Gespeicherte Galerie ansehen** to display the saved collection directly, including inside the Android app. **Live öffnen** returns to the server. Reopening an offline URL requires a working Service Worker on the device. A downloadable single-file offline collection is also available; its reopening test is documented under validation.

Originals remain unchanged. Offline video entries show their thumbnail; original playback needs a connection. The Android Nextcloud client can expose NextGallery through an Android-only External sites menu entry. Responsive browser tests are not hardware/WebView tests.

Existing AI metadata is preserved permanently until explicitly deleted. Model IDs, model builds, prompts and file changes never schedule completed analyses again. The overview counts saved analyses independently of the worker queue and identifies older file versions. To reanalyse everything, pause and wait for the current file, open **Meine Verarbeitung → Daten verwalten und löschen → Beschreibungsdaten löschen**, check the affected count and confirm, then start a new run. This deletes AI metadata and the analysis cache for reanalysis; original images and videos remain unchanged. Only administrators can inspect or change model connections. Users can start description or image-embedding runs and delete either kind of their own AI data separately. Queues, logs, caches and deletion requests are isolated per account. Existing analyses and pause state are retained when updating.

## People recognition

An optional fourth pipeline groups photographs by people, without requiring names. Existing folder selections never opt in automatically. Administrators install and enable its isolated OpenCV runtime; users select People per folder and start their own run. Groups, corrections and deletion stay per account, independently of descriptions, OCR and image search. See [People setup, data ownership and model provenance](FACES.md).

## Video speech transcription

An optional fifth pipeline transcribes spoken video content locally and adds a separate speech search source. It is disabled by default and requires explicit per-folder opt-in. See [Audio setup, limits and preservation](AUDIO.md).

## AI administration and external embedding server

As administrator, open **NextGallery → Einstellungen**. Description, OCR, embedding, People and video audio settings are collapsed into separate sections. Users choose their own folders, exclusions and schedules under **Meine Verarbeitung → Ordner und Zeitplan**. Each selected folder has a checkbox per pipeline (description, OCR, image search, People, video audio), so a screenshot folder can run OCR only. Subfolders follow the most specific selected folder, and at least one pipeline stays ticked; to skip a folder entirely, exclude it. A pipeline the administrator has turned off stays greyed out until it is enabled. Deselecting a pipeline keeps existing results; the **Dateien** page lists such media as not planned instead of pending. The **Dateien** page shows selected-media totals and separate ready/pending/error counts and filters for descriptions, OCR and image vectors. User pages contain no model fields, server addresses, prompts or installation controls.

Descriptions, OCR, visual embeddings, People and video audio have five independent workers, start/pause/retry controls, current-file displays, counters and daily schedule completion. They can run concurrently, including for different accounts. Under **Benutzerläufe**, administrators suspend each pipeline separately. Pausing a pipeline, replacing its model, deleting its results or a service outage leaves the other pipelines untouched. Changes to shared folders or media limits require all affected pipelines to finish their current operation. Background model calls to the same host are serialized to avoid overloading one model server; calls to different hosts can run in parallel. Waiting for a model host does not consume the per-image time budget.

When upgrading from the earlier combined-worker architecture, restart the existing `nextgallery-ai.service` once. The same service supervises three processes; no additional systemd units are needed. Legacy requests and daily schedule state are split once under `control.lock`; existing description/OCR pause state is inherited by OCR. The original `native.sqlite3` is retained, and its existing results are copied into separate `native-descriptions.sqlite3` and `native-ocr.sqlite3` caches without reanalysis. The embedding cache remains in place. Existing published metadata is recognized without a destructive database migration. Each pipeline has its own control, status, schedule and processing gate. Concurrent description/OCR publication merges both results under a short account lock, and deleting one stage preserves the other. Logs retain a shared account history with the pipeline kind on each event.

### External storage (NAS, SMB)

Nextcloud only notices changes made directly on a NAS when someone opens the folder or a scan runs. Until then NextGallery and the AI pipelines see the old state. A nightly scan keeps it current. Example for an SMB mount `/Familienfotos` of user `paul`:

```ini
# /etc/systemd/system/nextcloud-familienfotos-scan.service
[Service]
Type=oneshot
User=www-data
WorkingDirectory=/var/www/nextcloud
ExecStart=/usr/bin/php /var/www/nextcloud/occ files:scan --quiet --path=/paul/files/Familienfotos

# /etc/systemd/system/nextcloud-familienfotos-scan.timer
[Timer]
OnCalendar=*-*-* 03:30:00
Persistent=true
[Install]
WantedBy=timers.target
```

Enable it with `systemctl daemon-reload && systemctl enable --now nextcloud-familienfotos-scan.timer`. Run the scan once before the first AI run.

Moving or renaming files on the NAS gives them a new Nextcloud file ID. Descriptions, OCR and image vectors are reused by content (SHA-256), so only the file is read again and no model runs. Moves made through Nextcloud keep the file ID and need no reread.

### Shared results between accounts

Descriptions, OCR and image vectors stay per account, but an account processing a file that another account has already analysed reuses the result instead of running the model again. This covers group-shared mounts such as `/Familienfotos`, regular shares and identical copies. The worker downloads the file as usual, computes its SHA-256 and asks the server for a result for the same content.

Descriptions and OCR follow the same rule as an account's own results: changing the model or prompt applies to media without a result and never reanalyses finished ones. An account therefore also adopts another account's result made with an earlier model or prompt. A result made with the account's current settings (model revision: model, prompt and stage options; for videos also frame sampling) is preferred, otherwise the newest one. The adopted result records the model that produced it in its provenance. Image vectors are only reused within the same embedding space, because vectors of different models cannot be compared in search.

The server answers only for a file inside the requesting account's selected folders, at its current version, and only for stages enabled for that account. Shared rows contain the model output and the public model name, without paths, file IDs, server addresses, prompts or the contributing account. Vectors that another account published for the very same file version before this feature existed are offered as well. Finished descriptions and OCR results from before the upgrade, including those kept after a model change, are offered to the shared store once, on the account's next description or OCR run.

Deleting a pipeline's results or deleting an account also withdraws that account's shared contributions; results another account has already adopted remain that account's own data. Switching the embedding model with deletion clears all shared vectors. To turn sharing off for the whole server:

```bash
sudo -u www-data php occ config:app:set nextgallery ai_share_results --value=no
```

People also reuses inference, through a separate cache keyed by Nextcloud file ID, ETag and the exact face-detection configuration. It can skip both the download and model run for a shared file; identical copies with different IDs do not share this cache. Face positions and vectors are reusable, while groups, hidden status and corrections stay per account. Live read access and explicit folder opt-in are required. Reset/deletion withdraws that account's references and removes cached inference once the last reference is gone. This reuse has its own switch under **Server settings → People → Reuse face detection between accounts**, independent of the description/OCR/image-search sharing above. Until an administrator saves that choice, it follows `ai_share_results`. Turning it off removes the shared face cache at the next hourly cleanup; each account's own groups and corrections stay. See [People](FACES.md).

For an external PC, see the [step-by-step installation guide](EXTERNAL_EMBEDDING_SERVER.md) and [installer](../scripts/install-embedding-server.py). The installer starts a separate Gemma embedding service with Docker; LM Studio can continue serving descriptions alongside it. See [embedding architecture](EMBEDDINGS.md).

## Build and install

Requires Nextcloud 32–35, PHP 8.2+ and its supported PHP version, its configured image/video preview providers, and a working Nextcloud cron. Server search also needs Python 3 with SQLite FTS5 trigram support and subprocess execution. The app bundles sqlite-vec 0.1.9 for Linux x86_64 and aarch64; it creates and updates each account’s search index automatically. See [SQL search setup and data preservation](SQL_SEARCH.md). Optional local FFprobe enriches video metadata.

```sh
npm ci --ignore-scripts
npm ci --ignore-scripts --prefix worker/lmstudio
npm run build
npm test
php tests/dates.php
```

Copy `appinfo`, `lib`, `templates`, `css`, `js`, `img` and `worker` to `apps/nextgallery`, set the usual app ownership, then run `occ app:enable nextgallery`. Frontend dependencies are bundled; no CDN is needed. There is no composer install step.

Installable archives also include the LM Studio SDK and its runtime dependencies. Build them with `python3 scripts/package-app.py OUTPUT` and verify the extracted worker with `python3 scripts/verify-worker-package.py OUTPUT/nextgallery-VERSION.tar.gz`. App updates must retain this runtime; otherwise a paused AI run starts and immediately pauses again when OCR tries to connect.

For a chosen user's configured folders:

```sh
sudo -u www-data php occ nextgallery:index USER --restart
```

Do not configure private folders for automated visual tests. `scripts/create-fixtures.py` generates a visibly marked synthetic demo collection without reading any personal media. `scripts/import-demo.php` refuses overwrites; optional `--resume` validates existing demo files by SHA-256 first.

See [research and tradeoffs](RESEARCH.md), [validation](VALIDATION.md), and [Android integration](ANDROID.md).

AGPL-3.0-or-later; third-party library and basemap licenses are recorded in [NOTICE](../NOTICE).

## Network source offer

For a public installation, offer the exact corresponding server source to network users under the AGPL. After building, run `python3 scripts/source-archive.py /private/output/nextgallery-server-VERSION-source.tar.gz` with the installed version, publish this server-only archive and configure `occ config:app:set nextgallery source_url --value=https://YOUR-HOST/nextgallery-server-VERSION-source.tar.gz`. The sidebar then includes a DE/EN download link. The archive contains original frontend/PHP sources, generated assets, build manifests/scripts and the bundled third-party library sources/licences; it contains no Android repository, credentials, generated sample photos or Git history. A private GitHub link alone is not a public source offer.

Synthetic retrieval comparisons, reports, saved vectors and verification scripts: [benchmarks](../benchmarks/README.md). Current model-transition and search behavior: [embeddings](EMBEDDINGS.md).
