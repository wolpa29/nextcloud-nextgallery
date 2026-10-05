# Native NextGallery and optional browser integration

The separate [NextGallery Android app](https://github.com/wolpa29/app-nextgallery) uses ordinary Nextcloud WebDAV by default from 0.4.0. This extension is optional: explicitly enable NextGallery Server in Android settings for the shared index. The browser gallery runs in Nextcloud. These are separate storage surfaces.

# Android integration

The official Nextcloud Android app obtains custom menu links through the External sites OCS API. Chronik uses an Android-only link that opens its responsive view inside the app's WebView.

## Configure

Install and enable the official `external` app. From the Nextcloud administration interface, add:

- Name: **Chronik**
- URL: your full HTTPS URL ending in `/index.php/apps/chronicle/` (or your rewrite equivalent)
- Device: **Only in the Android app**
- Type: **Header / link**
- Redirect: **off** (embedded WebView)

Existing external links should be preserved. Alternatively, the included CLI helper uses the official SitesManager to add/update only the Android Chronik entry:

```sh
sudo -u www-data php scripts/android-link.php https://YOUR-NEXTCLOUD/index.php/apps/chronicle/
```

Refresh/reopen the Android app so that it refreshes capabilities and menu entries. Client 35.0.0 refreshes external links after its app-open counter exceeds ten (or on a forced/account refresh); an immediate first-login menu update did not work reliably in the emulator. The entry appeared after eleven isolated relaunches. On a server using a local CA, trust that CA on the device. The embedded view requires its own Nextcloud web login in the tested client. The native account login does not supply a web session automatically.

## Offline

Use **Offline → Gespeicherte Galerie ansehen** to show local previews directly inside the app. **Live öffnen** returns to the server. Browser and Android WebView storage are separate: save a collection in the surface where you want to use it. Reopening a closed collection through **Offline öffnen** requires a working Service Worker, IndexedDB and certificate trust. This version does not modify the native client Photos screen or build a custom Nextcloud Android APK.

If the local CA prevents Service Worker registration, thumbnails can still be saved and viewed in an already loaded gallery. **Offline-Datei herunterladen** exports a single HTML file containing the saved collection, including its world map. Open that file in a browser without a network connection. Downloaded files are separate from browser storage and need to be deleted separately when no longer wanted.

## Verified Android client behavior

On 2026-10-04 the official **Nextcloud Android 35.0.0** release APK was tested on a fresh, disposable **API 37 x86_64 emulator**, using only a disposable account containing generated test media. Existing AVDs and their app data were not modified. Four GB RAM were required; the first 2 GB attempt was killed by Android for low memory. Rendering used SwiftShader, so this is a functional test, not a hardware performance benchmark.

The server capability and link were fetched by the actual native client. After its menu-cache refresh, **Chronik** appeared in the drawer. Selecting it opened `ExternalSiteWebView` inside Nextcloud. After a separate web login, the timeline displayed exactly four selected test media and one video, and the map displayed the one GPS-tagged test image. The video player loaded the three-second MP4, changed from play to pause while running, and returned to play with a completed seek bar. Screenshots confirm the gallery, map and video viewer in the official app.

With Chronik 0.1.2, four previews saved without failures (approximately 0.1 MB). Wi-Fi and cellular data were then disabled; the emulator had no IP route and a server ping failed. The native client showed **No internet connection**, while Chronik displayed all four local previews. Opening **Karte** for the first time in that session still displayed the world map and its one GPS point, using the saved bundle without a network connection. Its popup opened the saved photo in the media viewer, retaining the folder's month-only date and the metadata conflict indication.

Service Worker registration was unavailable in this WebView on the local-CA origin, even after the CA appeared in Android's user trust store. Consequently, reopening the app from a fully closed state without a connection remains unverified. During earlier reopening attempts the emulator also produced a native SIGILL crash in `libwebviewchromium.so`. Resetting the updated WebView and disabling its Play Store updates affected only the disposable emulator; the final loaded-session offline checks passed. These results do not establish long-term WebView stability or cache retention on the S26.

The app keeps its internal view changes within the page instead of using hash navigation in the Android client. Client 35.0.0 sends non-redirect navigations to external link intents, as shown in its [ExternalSiteWebView source](https://github.com/nextcloud/android/blob/stable-35.0.0/app/src/main/java/com/owncloud/android/ui/activity/ExternalSiteWebView.java).

The client's external-link refresh behavior is supported by its [35.0.0 DrawerActivity source](https://github.com/nextcloud/android/blob/stable-35.0.0/app/src/main/java/com/owncloud/android/ui/activity/DrawerActivity.java). The APK came from the [official release](https://github.com/nextcloud/android/releases/tag/stable-35.0.0). A physical S26 and its installed client version, GPU speed, battery consumption and long-term cache persistence remain untested.
