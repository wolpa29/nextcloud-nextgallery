# Chronik for Nextcloud

A private photo/video gallery with selected folders, a virtualized chronological grid, year/month/day headers or a date rail, an embedded media viewer, a local GPS world map and explicit offline thumbnail collections.

## Use

Open **Chronik → Einstellungen → Ordner hinzufügen**, choose the folders and save. Folder dates are authoritative by default. Select **Metadaten** or **Dateiname** to change priority. Switch date headers and sorting directly in the gallery toolbar. **Karte** shows media with GPS; **Offline** saves up to 5,000 small previews within a 300 MB budget.

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
