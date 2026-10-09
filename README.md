# NextGallery for Nextcloud

A private photo/video gallery with selected folders, a virtualized chronological grid, year/month/day headers or a date rail, an embedded media viewer, a local GPS world map and explicit offline thumbnail collections.

**NextGallery Server** is the optional server companion for the separate native [NextGallery Android app](https://github.com/wolpa29/app-nextgallery). Android **0.4.0 and later default to ordinary Nextcloud WebDAV**, without installing this extension. Enable the server mode explicitly for a shared index, account-wide folder/date settings and this browser gallery. An administrator installs the companion; ordinary users connect their own instance. The Android app provider separately operates an isolated synthetic review instance. Repository: [nextcloud-nextgallery](https://github.com/wolpa29/nextcloud-nextgallery). Current version: **0.4.0**, Nextcloud 32–35; PHP requirements follow the installed Nextcloud.

**Deutsch:** Die optionale Erweiterung heißt **NextGallery Server**. Sie verwaltet einen Index und Ordner-/Datumseinstellungen je Nextcloud-Konto und ergänzt die Browser-Galerie. Ab Android 0.4.0 funktioniert der voreingestellte Standardzugang ohne diese Erweiterung. Die technische App-ID lautet `nextgallery`, der Namespace `OCA\Nextgallery`. Bestehende Installationen werden mit ihren Daten migriert; Android benötigt das aktualisierte Client-Build. Originale bleiben erhalten. Die Android-README beschreibt Datenflüsse, lokalen Speicher, Löschung, Lizenzen und tatsächlich hinterlegte Play-Console-Angaben.

The companion remains **AGPL-3.0-or-later**, separately from the proprietary Android app. Private repository visibility does not remove that licence's applicable source obligations. The browser's offline storage is separate from the native Android app's cache. The official Nextcloud client's optional External sites link below is not required by the separate native app.


**Version 0.3.0 unifies NextGallery Server and Media AI under the `nextgallery` identity.** The gallery, optional image/video descriptions, tags, objects, OCR, processing workspace, scheduling and file integration now belong to one Nextcloud app. Both Git histories are preserved. The Android client uses the new gallery and AI addresses. Old endpoints and the compatibility app are removed. Read the [migration guide](docs/MERGE.md) before replacing either installed app.

**Deutsch:** Galerie und Media AI sind jetzt eine gemeinsame Nextcloud-Erweiterung. KI-Verarbeitung bleibt optional; die Galerie funktioniert ohne Worker oder Modellverbindung. Die gemeinsame Suche verbindet Dateinamen mit vorhandener Beschreibung, Tags, Objekten und OCR. Embeddings folgen separat.

## Use

Open **NextGallery Server → Einstellungen → Galerien verwalten**, name a gallery, choose its Nextcloud folders and save. Use the gallery name in Gallery, Search or Map to switch collections. Each gallery has its own folders and saved previews; display, dates, theme and language settings are shared across the account. Folder dates are authoritative by default. **Darstellung** changes the order, date groups and two to six columns. **Karte** shows media with GPS; **Offline** manages the selected gallery’s previews, up to 5,000 within a 300 MB budget.

Use **Offline → Gespeicherte Galerie ansehen** to display the saved collection directly, including inside the Android app. **Live öffnen** returns to the server. Reopening an offline URL requires a working Service Worker on the device. A downloadable single-file offline collection is also available; its reopening test is documented under validation.

Originals remain unchanged. Offline video entries show their thumbnail; original playback needs a connection. The Android Nextcloud client can expose NextGallery through an Android-only External sites menu entry. Responsive browser tests are not hardware/WebView tests.

Existing AI metadata is preserved permanently until explicitly deleted. Model IDs, model builds, prompts and file changes never schedule completed analyses again. The overview counts saved analyses independently of the worker queue and identifies older file versions. To reanalyse everything, pause and wait for the current file, choose **KI-Verarbeitung → Wartung → Alle KI-Daten löschen**, check the account/count and confirm, then start a new run. This deletes AI metadata and the analysis cache for reanalysis; original images and videos remain unchanged. Only administrators can change model connections.

## Build and install

Requires Nextcloud 32–35, PHP 8.2+ and its supported PHP version, its configured image/video preview providers, and a working Nextcloud cron. Optional local FFprobe enriches video metadata.

```sh
npm ci --ignore-scripts
npm run build
npm test
php tests/dates.php
```

Copy `appinfo`, `lib`, `templates`, `css`, `js`, `img` and `worker` to `apps/nextgallery`, set the usual app ownership, then run `occ app:enable nextgallery`. Frontend dependencies are bundled; no CDN is needed. There is no composer install step.

For a chosen user's configured folders:

```sh
sudo -u www-data php occ nextgallery:index USER --restart
```

Do not configure private folders for automated visual tests. `scripts/create-fixtures.py` generates a visibly marked synthetic demo collection without reading any personal media. `scripts/import-demo.php` refuses overwrites; optional `--resume` validates existing demo files by SHA-256 first.

See [research and tradeoffs](docs/RESEARCH.md), [validation](docs/VALIDATION.md), and [Android integration](docs/ANDROID.md).

AGPL-3.0-or-later; third-party library and basemap licenses are recorded in [NOTICE](NOTICE).

## Version history

| Version | Android companion | Legal revision | Technical changes | Legal changes |
| --- | --- | --- | --- | --- |
| 0.4.0 | Native app unchanged | No policy or legal change | Named galleries with shared settings/offline management, centered dialogs, native column controls and fast-scroll context. Existing AI analyses never overwrite; model changes apply only to missing analyses, full reset requires an explicit count-aware confirmation. | None. |
| 0.3.1 | Native app unchanged | No policy or legal change | Shared native light/dark palette, navigation, surfaces and controls across gallery, search, map, offline, settings, viewer, AI workspace and Files metadata; responsive access to AI settings. | None. |
| 0.1.4 | 0.4.0 / code 6 | 2026-10-05.1 (Android) | Rename to NextGallery Server; retain predecessor app ID; expose original capture evidence and mtime/size for offline date changes. Android extension is optional. | Clarify separate AGPL server licensing and optional processing on the user's Nextcloud. |
| 0.1.5 | 0.4.1 / code 7 | 2026-10-05.2 (Android) | Nextcloud 35 search-query compatibility; package English template translations with runtime files; optional corresponding-source link. Verified on Nextcloud 32.0.5 and 35.0.1; 33/34 not individually exercised. | Exact AGPL server source offer for public synthetic review service; proprietary Android code is separate. |
| 0.3.0 | Updated endpoints; client update required | No forced-update or publication | Canonical nextgallery ID, namespace, tables, worker service and APIs; verified data migration; no redirects. | Names and server licence references updated. |
| 0.2.0 | Existing native clients unchanged | No Android policy change | Merge Media AI and gallery, preserve data/routes via optional compatibility adapter, shared lexical search and viewer AI details; portable AI storage. | Unified server source includes AI worker and adapter. |

## Network source offer

For a public installation, offer the exact corresponding server source to network users under the AGPL. After building, run `python3 scripts/source-archive.py /private/output/nextgallery-server-0.4.0-source.tar.gz`, publish this server-only archive and configure `occ config:app:set nextgallery source_url --value=https://YOUR-HOST/nextgallery-server-0.4.0-source.tar.gz`. The sidebar then includes a DE/EN download link. The archive contains original frontend/PHP sources, generated assets, build manifests/scripts and the bundled third-party library sources/licences; it contains no Android repository, credentials, generated sample photos or Git history. A private GitHub link alone is not a public source offer.
