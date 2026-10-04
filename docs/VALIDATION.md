# Validation of the live installation

Tested on 2026-10-03/04 against Nextcloud **32.0.5**, app **Chronik 0.1.0**. The repository is private. Only generated, visibly marked demo media were used; no personal media folder was opened or configured.

## Live media and browser checks

- The isolated `/Chronik-Demo` collection contains **296 media**, including **two three-second H.264 videos**. All 296 original files match their generated SHA-256 hashes after indexing, previews, playback and offline saving.
- **203 media with GPS** appear on the local world map. The saved offline collection shows the same 203 positions.
- Desktop and **430 × 932** mobile browser views were checked visually: timeline, map, media viewer, settings and offline collection. Saved screenshots are local artifacts, not private media.
- All four date displays were exercised: year, month, day and date rail. Both sort directions and jumps to older periods work. Month-only and year-only items explicitly retain their precision in the viewer.
- Full photo preview loaded at **960 × 720**. An original video loaded at that resolution, reported **3 seconds**, and played to its end.
- All **296 thumbnails** were saved with **zero failures**, consuming approximately **6.5 MB**. The separate `/offline` page reopened them from IndexedDB: visible images use local blob URLs rather than preview requests. Its script nonce now matches the Nextcloud CSP.

## Automated checks

- **19 date/GPS assertions** run with the server's PHP, covering folder authority, conflicting metadata, filename priority, leap dates, timezone conversion, missing day/month precision and zero coordinates.
- The Node layout tests cover geometry for **100,000 simulated records** at 320, 430, 900 and 1600 px widths, with a bounded visible row window. This tests virtualization geometry, not a real 100,000-photo server or phone frame rate.
- Authenticated HTTP tests use a disposable account with four selected synthetic files and one unselected file. They cover allowlists, traversal/invalid settings, CSRF, date priorities, GPS, video metadata, JPEG generation, ETag/304, video range/206, suffix ranges, invalid range/416, the offline HTML/CSP, worker scope and the Android menu API.
- Cross-user requests for another account's **synthetic demo** preview and original return **404**.
- Warm HTTP item requests over four records measured **18.0 ms median / 21.9 ms maximum** in the final pre-cleanup run. This is a small-library smoke measurement, not a large-library benchmark.
- PHP syntax checks and Nextcloud's app-info XML schema passed. npm audit reported no vulnerabilities. GitHub Actions checks builds, Node tests, PHP syntax and the date resolver on every push.

## Practical limits

- The running origin uses a local CA that this desktop browser does not trust for Service Worker registration. Saving and displaying local thumbnails is verified; reopening by URL with the network disconnected is **not verified** on this origin. Trust the CA on the device for that workflow.
- Single-file HTML export is implemented and the UI generated its download link. The automation could not retrieve the download; its browser policy also blocks navigating to blob URLs. Reopening the exported file is therefore **not claimed as a passed browser test**.
- Offline collections contain previews and metadata. Original video playback needs the server. Downloaded copies must be removed separately from browser storage.
- JPEG EXIF and local MP4 metadata were tested. HEIC/non-JPEG metadata relies on Nextcloud metadata providers; format and codec support depends on configured previews and the client.
- The basemap provides country boundaries, zoom and photo clusters, without external tiles or street-level detail.
- Hardware scrolling performance on the user's S26 remains unmeasured. Android/WebView results are recorded separately in [Android integration](ANDROID.md).
