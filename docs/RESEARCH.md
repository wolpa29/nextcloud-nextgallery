# Implementation decisions (2026-10-03)

The deployment target was checked live: Nextcloud **32.0.5**, PHP with EXIF and FFmpeg/FFprobe. Compatibility is intentionally declared for Nextcloud 32 only; newer majors require validation.

## Existing apps and the requested scope

[Memories](https://memories.gallery/) already offers a chronological photo/video timeline, EXIF support and mobile access. Building a custom app is justified here by the requested folder-authoritative chronology, retained date precision, custom date rail and explicit small offline collection. This is an independent app; it does not replace Photos, Memories or Media AI.

## Nextcloud integration

- [Nextcloud 32 controllers and security](https://docs.nextcloud.com/server/32/developer_manual/basics/controllers.html): authenticated routes; GET views only disable CSRF; settings/index mutations retain the default CSRF checks; every identifying media result rechecks user access and allowed roots.
- [Background jobs](https://docs.nextcloud.com/server/32/developer_manual/basics/backgroundjobs.html): bounded resumable indexing, cron job every 60 seconds eligible; repeat sweep after 15 minutes. Nextcloud's cron scheduling may lengthen these intervals. A complete sweep may take longer for large libraries.
- [IPreview](https://github.com/nextcloud/server/blob/stable32/lib/public/IPreview.php): use Nextcloud's existing preview providers and disk cache. No duplicate originals; missing/unsupported previews fail gracefully.
- [Public Files Search interfaces](https://github.com/nextcloud/server/tree/stable32/lib/public/Files/Search): bounded search using file IDs; the custom implementation avoids instantiating private OC search classes.
- [Photos EXIF provider](https://github.com/nextcloud/photos/blob/stable32/lib/Listener/ExifMetadataProvider.php): reuse available metadata; supplement JPEG EXIF without editing originals. Video creation times/GPS use local FFprobe where possible. HEIC/non-JPEG metadata relies on metadata already supplied by Nextcloud; additional format extractors are a future compatibility improvement.

## Android: supported menu route, not a replacement native Photos screen

The official Android library calls `/ocs/v2.php/apps/external/api/v1` after checking the `external` capability:
[ExternalLinksOperation.java](https://github.com/nextcloud/android-library/blob/master/library/src/main/java/com/owncloud/android/lib/common/accounts/ExternalLinksOperation.java).

The Android app can show a link in its drawer and open an embedded web view:
[ExternalSiteWebView.kt](https://github.com/nextcloud/android/blob/master/app/src/main/java/com/owncloud/android/ui/activity/ExternalSiteWebView.kt).

The supported **External sites** app is used to register one Android-only `Chronik` link (`redirect=false`). Existing entries are preserved. Sign-in and CA trust remain subject to the installed Android client's WebView. No physical Android device was connected during this run; WebView/device verification is separate from responsive browser checks.

## Performance, date policy and offline

The database is indexed by user, date order, file ID and period. The client requests period-local blocks of 150 records with three concurrent requests, retains a bounded block cache, and renders only visible rows plus overscan. World-map GPS results are loaded in batches and clustered. Collection-wide geometry uses period counts rather than all file records.

Dates keep their year/month/day precision. Matching capture/name dates can add precision inside folder dates; conflicting dates are explained in the viewer. Unknown timezone-free EXIF uses the selected timezone; offset-bearing dates are converted. Modification time is the explicitly labeled last fallback.

Offline is opt-in: an authenticated, independent offline page and bundled assets are saved in Cache Storage; file metadata and 384 px thumbnails are saved in IndexedDB per user/configuration epoch. APIs, originals and session pages are never stored by the service worker. Original video playback requires a connection. Collection limit: 5,000 thumbnails and 300 MB. Browsers can evict local storage. Clearing the collection deletes both metadata and thumbnails. Root/date-policy changes invalidate local collections; the logout link observed within Chronik clears them. Logout from elsewhere cannot guarantee clearing a device's intentionally saved collection.

The world map is local and does not send GPS data to a tile provider. [Natural Earth](https://www.naturalearthdata.com/about/terms-of-use/) boundaries are public domain; bundled from its [official vector repository](https://github.com/nvkelso/natural-earth-vector). [Leaflet](https://leafletjs.com/reference.html) and [MarkerCluster](https://github.com/Leaflet/Leaflet.markercluster) provide zoom/pan and grouped markers. No remote street tiles are loaded. World-map location can be shown offline for saved media; a street-level map is outside this version's local basemap.
