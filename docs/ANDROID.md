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

Refresh/reopen the Android app so that it refreshes capabilities and menu entries. On a server using a local CA, trust that CA on the device. The embedded view may require a Nextcloud browser login, depending on the Android client and WebView's session behavior.

## Offline

The browser's independent offline collection is opened through **Offline → Offline öffnen**. Service Worker, IndexedDB and CA trust are required. Browser and Android WebView storage are separate: save a collection in the surface where you want to use it. Device-specific WebView persistence is not established by desktop browser tests. This version does not modify the native client Photos screen or build a custom Nextcloud Android APK.

If the local CA prevents Service Worker registration, thumbnails can still be saved and viewed in an already loaded gallery. **Offline-Datei herunterladen** exports a single HTML file containing the saved collection, including its world map. Open that file in a browser without a network connection. Downloaded files are separate from browser storage and need to be deleted separately when no longer wanted.
