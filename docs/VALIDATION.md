# Validation of the live installation

Tested on 2026-10-03/04 against Nextcloud **32.0.5**, app **Chronik 0.1.2**. The repository is private. Only generated, visibly marked demo media were used; no personal media folder was opened or configured.

## Live media and browser checks

- The isolated `/Chronik-Demo` collection contains **296 media**, including **two three-second H.264 videos**. All 296 original files match their generated SHA-256 hashes after indexing, previews, playback and offline saving.
- **203 media with GPS** appear on the local world map. The saved offline collection shows the same 203 positions.
- Desktop and **430 × 932** mobile browser views were checked visually: timeline, map, media viewer, settings and offline collection. Saved screenshots are local artifacts, not private media.
- All four date displays were exercised: year, month, day and date rail. Both sort directions and jumps to older periods work. Month-only and year-only items explicitly retain their precision in the viewer.
- Full photo preview loaded at **960 × 720**. An original video loaded at that resolution, reported **3 seconds**, and played to its end.
- All **296 thumbnails** were saved with **zero failures**, consuming approximately **6.5 MB**. The separate `/offline` page reopened them from IndexedDB: visible images use local blob URLs rather than preview requests. Its script nonce now matches the Nextcloud CSP.
- The direct **Gespeicherte Galerie ansehen** control and return through **Live öffnen** were exercised. After a fresh browser load, the saved map bundle started from CacheStorage with the page's CSP nonce and displayed all 203 GPS positions.

## Automated checks

- **19 date/GPS assertions** run with the server's PHP, covering folder authority, conflicting metadata, filename priority, leap dates, timezone conversion, missing day/month precision and zero coordinates.
- The Node layout tests cover geometry for **100,000 simulated records** at 320, 430, 900 and 1600 px widths, with a bounded visible row window. This tests virtualization geometry, not a real 100,000-photo server or phone frame rate.
- Authenticated HTTP tests use a disposable account with four selected synthetic files and one unselected file. They cover allowlists, traversal/invalid settings, CSRF, date priorities, GPS, video metadata, JPEG generation, ETag/304, video range/206, suffix ranges, invalid range/416, the offline HTML/CSP, worker scope and the Android menu API.
- Cross-user requests for another account's **synthetic demo** preview and original return **404**.
- Deleting the disposable test account through Nextcloud's public user API triggered the app's deletion listener. Both app tables were verified empty for that account. Test credentials and the disposable emulator were removed after validation; the reviewable `/Chronik-Demo` collection remains.
- Warm HTTP item requests over four records measured approximately **18–27 ms median** across repeated runs. This is a small-library smoke measurement, not a large-library benchmark.
- PHP syntax checks and Nextcloud's app-info XML schema passed. npm audit reported no vulnerabilities. GitHub Actions checks builds, Node tests, PHP syntax and the date resolver on every push.

## Practical limits

- The running origin uses a local CA that this desktop browser does not trust for Service Worker registration. Saving and displaying local thumbnails is verified, including the official Android app with Wi-Fi/data disabled. Reopening a closed collection by URL with the network disconnected is **not verified** on this origin. It needs certificate trust and a working Service Worker; Android user-CA installation alone did not enable registration in the tested WebView.
- Single-file HTML export is implemented and the UI generated its download link. The automation could not retrieve the download; its browser policy also blocks navigating to blob URLs. Reopening the exported file is therefore **not claimed as a passed browser test**.
- Offline collections contain previews and metadata. Original video playback needs the server. Downloaded copies must be removed separately from browser storage.
- JPEG EXIF and local MP4 metadata were tested. HEIC/non-JPEG metadata relies on Nextcloud metadata providers; format and codec support depends on configured previews and the client.
- The basemap provides country boundaries, zoom and photo clusters, without external tiles or street-level detail.
- Hardware scrolling performance on the user's S26 remains unmeasured. The official Android client was also exercised in an isolated emulator; its WebView results are recorded separately in [Android integration](ANDROID.md).

## NextGallery 0.1.3 follow-up — 2026-10-04

The compact NextGallery design, connected cloud/photo icon and German/English translations were exercised on a separate loopback-only Docker Nextcloud 32.0.5 instance. It contains only the generated 296-item fixture set: 2 videos and 203 GPS records, verified through the paginated real app API. No private media or personal account is present in this local instance.

The live UI checks found and fixed the lazy map global-name mismatch introduced by rebranding. Worker registration is now permitted only from the same origin in the page CSP, and the worker CSP allows same-origin network requests. The static-asset matcher supports both apps/ and custom_apps/. Activation waits for the registration itself, with a bounded timeout; a legacy index.php URL outside the canonical scope no longer hangs saving.

Verified: English desktop with a 56px header; 390x844 phone with a 52px header, four usable bottom navigation targets and no horizontal overflow; year/date settings; online local world map with all 203 GPS records; 24 saved previews (~0.5 MB); reopening the standalone offline page with the local Docker server stopped; first opening of its locally cached map (16 GPS records in that saved subset); MP4 playback through the full 3 seconds. World geography and previews are bundled/cached, without an external tile service.

Evidence is in ignored artifacts/v013-local-*.png and local-nc-v013-api.json. Node layout tests and all 19 PHP date/GPS assertions pass; PHP 8.2 lint passes. The existing server was subsequently updated over the visible SSH session to 0.1.3 after a verified 0.1.2 backup. The installed 49 files match the deployment manifest byte-for-byte. A live HTTP regression against the isolated `chronik-android-demo` account verified 296 synthetic records, two videos, 203 GPS records, JPEG preview/ETag, video range responses, the offline CSP and the Android embedded context; no private media was opened.

A subsequent real German-user test exposed app-specific IL10N falling back to English without a Nextcloud l10n/de.json file. Page context now uses the public IFactory::findLanguage() independently of app translation files. German desktop and an actual 390x844 same-origin embedded viewport both display German navigation, counts and month labels. The mobile screenshot contains the four bottom controls without visible overflow; artifacts/v013-local-de-mobile.png is a genuine browser capture. English was checked again after switching only the synthetic user's core language. PHP syntax and the existing Node geometry/date tests pass after this correction.
