# NextGallery for Nextcloud

A private photo/video gallery with selected folders, a virtualized chronological grid, year/month/day headers or a date rail, an embedded media viewer, a local GPS world map and explicit offline thumbnail collections.

**NextGallery Server** is the optional server companion for the separate native [NextGallery Android app](https://github.com/wolpa29/chronik-android). Android **0.4.0 and later default to ordinary Nextcloud WebDAV**, without installing this extension. Enable the server mode explicitly for a shared index, account-wide folder/date settings and this browser gallery. An administrator installs the companion; it is not a hosted intermediary service operated by the Android app provider. Current version: **0.1.4**, Nextcloud 32 / PHP 8.1+.

**Deutsch:** Die optionale Erweiterung heißt **NextGallery Server**. Sie verwaltet einen Index und Ordner-/Datumseinstellungen je Nextcloud-Konto und ergänzt die Browser-Galerie. Ab Android 0.4.0 funktioniert der voreingestellte Standardzugang ohne diese Erweiterung. `chronicle`, Namespace und Repository bleiben als technische Kennungen erhalten, damit bestehende Installationen kompatibel bleiben. Originale bleiben erhalten. Die Android-README beschreibt Datenflüsse, lokalen Speicher, Löschung, Lizenzen und tatsächlich hinterlegte Play-Console-Angaben.

The companion remains **AGPL-3.0-or-later**, separately from the proprietary Android app. Private repository visibility does not remove that licence's applicable source obligations. The browser's offline storage is separate from the native Android app's cache. The official Nextcloud client's optional External sites link below is not required by the separate native app.

## Use

Open **NextGallery Server → Einstellungen → Ordner hinzufügen**, choose the folders and save. Folder dates are authoritative by default. Select **Metadaten** or **Dateiname** to change priority. Switch date headers and sorting directly in the gallery toolbar. **Karte** shows media with GPS; **Offline** saves up to 5,000 small previews within a 300 MB budget.

Use **Offline → Gespeicherte Galerie ansehen** to display the saved collection directly, including inside the Android app. **Live öffnen** returns to the server. Reopening an offline URL requires a working Service Worker on the device. A downloadable single-file offline collection is also available; its reopening test is documented under validation.

Originals remain unchanged. Offline video entries show their thumbnail; original playback needs a connection. The Android Nextcloud client can expose Chronik through an Android-only External sites menu entry. Responsive browser tests are not hardware/WebView tests.

## Build and install

Requires Nextcloud 32, PHP 8.1+, its configured image/video preview providers, and a working Nextcloud cron. Optional local FFprobe enriches video metadata.

```sh
npm ci --ignore-scripts
npm run build
npm test
php tests/dates.php
```

Copy `appinfo`, `lib`, `templates`, `css`, `js` and `img` to `apps/chronicle`, set the usual app ownership, then run `occ app:enable chronicle`. Frontend dependencies are bundled; no CDN is needed. There is no composer install step.

For a chosen user's configured folders:

```sh
sudo -u www-data php occ chronicle:index USER --restart
```

Do not configure private folders for automated visual tests. `scripts/create-fixtures.py` generates a visibly marked synthetic demo collection without reading any personal media. `scripts/import-demo.php` refuses overwrites; optional `--resume` validates existing demo files by SHA-256 first.

See [research and tradeoffs](docs/RESEARCH.md), [validation](docs/VALIDATION.md), and [Android integration](docs/ANDROID.md).

AGPL-3.0-or-later; third-party library and basemap licenses are recorded in [NOTICE](NOTICE).

## Version history

| Version | Android companion | Legal revision | Technical changes | Legal changes |
| --- | --- | --- | --- | --- |
| 0.1.4 | 0.4.0 / code 6 | 2026-10-05.1 (Android) | Rename to NextGallery Server; retain chronicle ID; expose original capture evidence and mtime/size for offline date changes. Android extension is optional. | Clarify separate AGPL server licensing and optional processing on the user's Nextcloud. |
