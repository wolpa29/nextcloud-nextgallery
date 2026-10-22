# Native Android People integration — 20 October 2026

## 0.4.39 Integration resilience and repeatable quality checks — 22 October 2026

Local working-tree validation; the initial implementation was validated before production installation.
The subsequent delivery record below distinguishes push, CI, install and release. Test media are generated PNG/SVG fixtures.

Implemented: atomic offline collection replacement retaining IndexedDB schema 1
for previously saved shells; cancellation/quota/rollback protection; same-gallery
refresh preservation and page retry; viewer paging across timeline periods;
paused hidden-map pagination and batch marker insertion; compact dismissible
processing help and mobile navigation reflow at enlarged text. Failed storage
roots retain their catalog rows and report a partial scan; outputs still check
current permissions. Optional Android person labels survive online parsing,
SQLite snapshots and the picker. Capability discovery is bounded and compatible
with older servers. Web and Android explain preparing/updating search indexes.

Search queries enqueue cold/stale index work instead of importing in HTTP.
Existing indexes remain readable with current file/ETag checks. Ordinary file
changes target owners and share recipients; unclear/group-share events retain a
conservative global invalidation. Cron rotates accounts persistently and includes
queued Android-only accounts. A periodic queued sweep covers out-of-band changes.

| Local check | Result |
| --- | --- |
| Required Node/browser suite | 83 passed, zero failures/skips; actual PHP shell and current frontend bundles |
| Python suite | 385 passed, no skips reported |
| PHP suite | Full service-user suite and syntax checks passed; includes 45 backend resilience, 18 capability, 19 date/GPS and 11 migration assertions |
| Nextcloud 32.0.5, MariaDB | Both predecessor upgrade and unified rename paths passed the full runner, each including 28 authenticated HTTP checks and immediate revoked-share denial |
| Nextcloud 35.0.1, SQLite | Full fresh-install runner passed, including 28 HTTP checks and 18 concurrent partial-publication/account-isolation assertions |
| Nextcloud 34/35, MariaDB | All eight People contracts passed on each (417 assertions per version) |
| Existing People schema | Real 0.4.38-to-0.4.39 app upgrades on 34/35 retained exact row fingerprints in eight nonempty tables; retry was idempotent. An additional 0.4.21-style pending-migration upgrade on 34 restored the missing sharpness column and retained rows/vectors/names/rules/references. |
| Capability HTTP response | Verified through authenticated Nextcloud capabilities on both 32 fixtures and fresh 35 |
| Layout | Generated screenshots at 320/390/768/1360 CSS pixels, light/dark, and 200% text at 320; no horizontal page overflow in those probes |
| Android companion | 118 JVM tests passed; Lint, Debug APK and Android-test Kotlin compilation succeeded. Store/UI instrumentation was compiled but not run on a device. |
| Packaging | Extracted worker package verification passed; generated frontend rebuild is deterministic |

The short table names and explicit primary-key names repair Nextcloud 32's
installation constraints. Migration 000439 uses physical renames, refuses
ambiguous old/new collisions and copies/deletes no People rows. Earlier migration
steps also accept existing legacy names before that rename occurs.

Additional accessibility checks cover keyboard-only dialog focus return and Tab
cycling, radio-group navigation, the fast-scroll slider, gallery/search/People/map
semantics, visible labels in accessible names and real People photo rendering.
Chromium, Firefox and WebKit are exercised with generated fixtures. The Linux
host lacks WebKit libraries, so its complete strict browser run uses the official
Playwright Docker image with PHP CLI. Roles, labels, attribution-link underlining,
modal focus wrapping and keyboard photo opening were corrected. DOMPurify was
updated to 3.4.17; the dependency audit then reported zero known vulnerabilities.

The new CI browser job rejects skipped tests. People contracts run on 34 and 35;
release packaging depends on the verification workflow. These are prepared
workflow changes: no new hosted GitHub run is claimed for the unpushed tree.
See [quality checks](QUALITY.md) for fixed labelled metrics and measured synthetic
10k/100k/multiple-frame search loads. Those measurements do not establish model
recognition accuracy or complete HTTP production latency. Exact candidate ranking
and model/vector spaces are unchanged. No physical Android device, macOS Safari
or complete assistive screen-reader audit was run for this change. The test suite
verifies automated axe-core rules and targeted keyboard behaviors without
claiming full WCAG compliance.

## 0.4.38 Cross-gallery person assignments and consistent initial filters — 22 October 2026

The People overview keeps gallery-local photos, counts and rarity. Assignment
and similarity targets now use all currently configured galleries of the signed-in
account, including named people with no photos in the current gallery. Target
name search runs before pagination. Preview and inspection requests retain the
target scope and still check current access, configured roots and ETag. The Web
and OCS adapters expose the same optional scope. Browser-restored filter values
are reconciled on opening and pageshow.

Validation: 57 Node/browser tests; 72 real Nextcloud suggestion assertions on
Nextcloud 34/35; a separate 28-assertion contract covers the first rare photo,
search beyond the first page, cross-gallery previews, OCS parity, cross-account
isolation, hidden/unknown targets, removed roots and changed ETags. Existing
96 People feature, 93 native People and 6 snapshot-cleanup assertions pass on
Nextcloud 34. Admin browser validation passes 68 checks and model-transition
validation passes 39 checks. The full PHP unit suite passes when run as the
service user. Its old expectations for repeated pause and rare-person suggestions
were corrected against the existing behavior; no control logic was changed.
The complete fresh Nextcloud 35.0.1 integration run passes, including 28 HTTP
checks, 39 admin and 17 independent-pipeline assertions, concurrent publication
and revoked-share checks.
Synthetic phone/desktop layouts and the new account target card were inspected.
No private photos are used. The pre-existing Admin/Gemma fixes below are included
in this release without resetting or recomputing existing AI data.

## 0.4.35 Vector-space check ignores retired cache rows — 21 October 2026

The admin compatibility check compared every row of the worker cache, including inactive rows kept from an older model space, and reported "The local processing cache contains different image vectors" although all active vectors and the Nextcloud vector table matched the service. It now counts only active, completed rows, like the worker's own rebuild detection. "Check connection" moved below the Gemma install/remove controls.

Validation: admin contract (39 assertions, fixture now uses the worker cache schema with an inactive older-space row; fails with the previous query), 68 admin browser assertions without page errors.

## 0.4.34 Switching to local Gemma before installation — 21 October 2026

Moving from an external embedding service to local Gemma deadlocked: "Save and install Gemma" saves first, the vector-space check cannot reach the not-yet-installed local service, and the dialog only allowed cancelling. An unreachable service now offers "Save, verify later": the configuration is saved, no vectors are deleted and no space is approved. Once the service runs, the worker compares the stored vectors and keeps them on a match or requires an explicit rebuild confirmation. The server rejects deferral when the service is reachable. Local connection checks now say that the local service is not running yet.

Validation: fresh Nextcloud 35 integration suite passed (39 admin processing/model transition assertions including deferral, 17 three-pipeline assertions), 68 admin browser assertions passed without page errors, JavaScript unit tests passed.

## 0.4.33 People workspace and native consistency — 21 October 2026

The People workspace now has a clearly labelled overview, management disclosure, compact/collapsible suggestions, keyboard-accessible context controls and explicit loaded counts. Same-context refresh failures preserve existing cards/photos; failed suggestion requests can be retried independently. Correction requests reject duplicate submissions, stale-view results cannot overwrite another gallery, and correction/conflict failures remain visible. Hide/show and known/unknown flags support retryable undo. Merge separation is honestly labelled and documented: it does not restore historical names/covers. Processing errors distinguish image corruption, limits and response failures without claiming an unverified model-service outage.

Rare groups with good faces and open suggestions now appear in the Suggestions filter. Self-splits are rejected without changing constraints or revisions. Native live lists/media preserve names and preferred covers, include named small groups and exclude unknown/hidden groups consistently with offline snapshots; quality/roots/ETag/token protections remain enforced.

Validation: 48 JavaScript/browser tests, 70 face-pipeline tests, 79 four-lane browser-control assertions, 49 real MariaDB feature assertions, 37 suggestion assertions, 93 native People assertions and six snapshot-cleanup assertions passed. The actual template was tested at 320/390/1360px in light/dark themes, with no overflow or uncaught browser errors. The extracted install package passed SDK/transport/SQLite/search checks.

After explicit owner approval, 0.4.33 was installed by atomic app exchange with rollback available. All 1,047 installed runtime hashes match package SHA-256 `510e08510cf038c06ec3d448a42828f2a58b5db0ef48d1f327d2414815aae818`. Fourteen protected SQL/settings summaries, five logical worker databases, all controls/schedules and all five worker PIDs are identical before/after. Worker code is unchanged and no worker restart, People reset, reanalysis or original-file change was performed. Nextcloud reports maintenance=false and no pending app upgrade; the authenticated live browser confirms 0.4.33 and the new controls. The existing 16 People file errors were retained, not represented as recovered. Backup: `/root/nextgallery-backups/people-ux-0433-20261020-220549` (server UTC timestamp). Evidence: ignored `artifacts/deploy-0.4.33/`, with synthetic visual tests under `artifacts/people-workspace-ui/`.


NextGallery Server **0.4.24** is installed on Nextcloud34.0.5.1/MariaDB. Android0.8.0 uses explicit mobile folder roots for person browsing, optional offline assignment snapshots and person-filtered text/image search. Recognition workers, model spaces and canonical data formats are unchanged.

- **79** real Nextcloud People contracts passed directly against the installable PHP classes, including scope, access, quality, hidden groups, file versions, date-aware paging, fresh/frozen snapshots, invalidation and native search filtering, including real sqlite-vec person/motif intersection and cross-person token rejection. The temporary duplicated-class test bundle was removed; it is not the verification source.
- **97** existing native lexical/vector search, permissions and pagination contracts passed. **49** existing Faces storage/account/API contracts passed. **6** independent snapshot-expiry/erasure assertions passed. All changed PHP files pass syntax checks. The extracted worker package guard and its SQLite/FTS5/vector/transport checks pass; no worker code is changed by this release.
- Seven production HTTP checks used a temporary ordinary account and an empty synthetic folder: person list/snapshot/final token/search-filter echo, invalid roots400, unauthenticated401 and missing crop404. TLS used the configured CA. The account and its data were removed. No private photographs were accessed.
- Before/after installation, all **14** protected SQL/settings summaries were byte-identical by hash. All worker process IDs and controls were identical. Only **12** PHP/routing/package metadata files were installed; no worker restart, reset or reanalysis was requested. Backup: `/root/nextgallery-backups/native-people-0424-20261020-132620`.

Evidence is in ignored `artifacts/native-people-0424/` and the local task verification files. Native Android signed-package, distribution and update-policy evidence is recorded in the separate Android repository. Synthetic fixtures do not measure person-recognition accuracy in a private library.

---

# Merge suggestions and scoped People listing — 2026-10-20

NextGallery **0.4.23** was validated on the disposable Nextcloud **34.0.5 / MariaDB** instance and installed on production **34.0.5.1 / MariaDB** on 2026-10-20 from commit `eb6de9d`.

- **372 Python tests** passed in the base environment (13 optional-runtime skips); all **63 face tests**, including eight new suggestion tests, passed in the OpenCV/NumPy runtime.
- All standalone PHP suites passed, including **92 face assertions** and **50 new assertions** for the separate face-reuse switch, index-scoped listing, fail-closed access without a home folder, suggestion upload guards, dismissal, split/merge behaviour and cleanup.
- The real Nextcloud upgrade to 0.4.23 created both new tables. **49 People**, **43 shared-artifact** and **30 quality** contracts passed unchanged, and **22 new MariaDB contracts** covered the switch fallback, gallery-scoped counts and suggestions, the constraint join, merges, cleanup and reset. All contracts passed twice in a row.
- All **28 Node tests** passed, including the five Chromium real-DOM tests and three new suggestion-panel tests.
- Synthetic scale check: 50,000 detections in 2,000 groups, one overview request about **0.7 s**; per-file live checks for all files would have taken at least about 14 s.
- Deployment verification repeated all **63 real-runtime face tests**, **28 Node/Chromium tests**, standalone PHP suites and syntax checks, and the **49 / 43 / 30 / 22** real database contracts. **24 public-portrait HTTP checks** and **13 shared-folder worker/HTTP checks** passed. A real-model processing run successfully uploaded its suggestion result through the new HTTP endpoint; the two distinct public identities produced no merge candidates.
- The extracted 0.4.23 package passed its SDK and SQLite/FTS/vector runtime checks; all **1,043 runtime files** matched the installed package. Four deployment fault tests passed. No private photographs were opened for verification. Suggestion quality in a real library remains unmeasured; thresholds are provisional.
- Production installation preserved all **14** protected SQL/settings summaries (the two new tables were initially empty), all worker-cache logical contents and unrelated controls. Backup: `/root/nextgallery-backups/faces-0423-20261020-101326`. Only the People worker restarted; the supervisor and other three worker PIDs remained unchanged.
- At the owner's request, `paul`'s People generation changed **3 → 4**. Detections, groups, processed-file records, constraints, suggestions, dismissals and artifact references were all verified empty before resumption, and the derived face cache was removed. Other accounts and all non-People data/settings were unchanged. Personal filters remained **0% minimum area / sharpness 45 / three photographs**.
- Live production Web/OCS list and suggestion endpoints, delivered JavaScript bytes, four worker heartbeats, model readiness, scratch-account cache erasure and `maintenance=false` passed. The new run reached **18 processed photographs with zero errors** during final verification. Installed hashes, unrelated caches, controls and worker PIDs still matched after resumption. Only aggregate counts were inspected; private image quality was not evaluated.

---

# Face quality filtering release — 2026-10-20

NextGallery **0.4.22** was installed on Nextcloud **34.0.5.1 / MariaDB**. Personal area, sharpness and photograph-count filters reduce background detections in the main People view. Weak detections remain available for explicit confirmation and cannot anchor automatic clustering. Raw file inference remains shareable; personal filtering and corrections remain account-specific.

- **364 Python tests** passed in the base environment (five optional-runtime skips); all **55 face tests** passed in the real OpenCV/NumPy development runtime. All **25 Node/browser tests** passed with Chromium.
- Existing standalone PHP suites passed, including **92 face assertions** and five transaction-lock fault/contention checks. A real Nextcloud 34/MariaDB upgrade passed alongside **49 existing People contracts**, **43 shared-artifact contracts** and **30 new quality-policy assertions**. These cover mixed good/weak groups, overview/detail counts, manual overrides, personal settings validation, raw-artifact purity and scoped resets.
- **13 shared-folder HTTP/worker checks** passed: the first account inferred good, empty and weak synthetic results; the second downloaded no images and invoked no detector. Weak faces remained excluded as cluster anchors and revoked sharing denied a fresh request. The existing **24 real-model public-portrait HTTP checks** also passed.
- Desktop/mobile browser checks covered the main, rare and filtered views, filtered details, confirmation and personal filter save/reload. Screenshots were visually inspected. Only public portraits and synthetic fixtures were used; one quality value was deliberately overridden in the disposable UI fixture to exercise the filtered layout.
- Real-model public-portrait smoke checks retained the four sharp originals. Strong artificial Gaussian blur reduced one portrait's sharpness below 45; moderate blur can remain above the threshold. This is a heuristic check, not a measurement of background-face suppression or recognition accuracy in a private library. No private photographs were opened for validation.
- Four deployment fault checks passed. A scoped production backup was retained at `/root/nextgallery-backups/face-quality-20261020-074936`. Before the requested reset, all 12 protected SQL/settings summaries and existing cache logical contents matched across installation; all 1,042 installed runtime hashes matched the package. Only the People worker restarted.
- At the owner's request, only `paul`'s People data and derived cache were reset (generation **1 → 2**, zero processed files immediately after reset), including old personal groups and corrections. Personal filters were set to **0.25% / 45 / 3**. Other accounts' canonical data, references and corrections, the three other AI stores, settings and controls were unchanged. The previous People run was resumed; unrelated workers retained their PIDs.
- Live Web/OCS list requests, the filtered view markup, served JavaScript bytes, model setup, four fresh heartbeats and scratch-account cache erasure passed on the production host. The fresh run advanced to **22 processed files, zero errors** during verification. All ten stored detections had a quality metric; five met the personal exclusion criteria. Unrelated cache contents, controls, worker PIDs and all runtime hashes matched again after resumption; maintenance remained disabled. These initial aggregate counts do not establish recognition quality. Evidence is retained in the local release artifacts.

---

# Shared face inference release — 2026-10-20

NextGallery **0.4.21** was installed on Nextcloud **34.0.5.1 / MariaDB**. Face inference is now reusable by file ID, ETag and inference revision, while account groups and corrections remain independent.

- **352 Python tests** passed in the base environment (three optional-runtime skips); the installed OpenCV/NumPy development runtime passed all **43 face tests**. All **20 Node/browser tests** passed.
- Existing standalone PHP suites passed, including **92 face assertions**. Five additional fault/contention checks verified that the shared mutation lock survives until commit/rollback, releases on errors and rejects competing account mutations without leaking locks.
- A real Nextcloud 34/MariaDB upgrade and **43 shared-inference database/controller contracts** passed, alongside the existing **49 People contracts**. They cover independent corrections, zero-face hits, exact inference revisions, opt-in and read access, reset references, last-reference deletion, stale publication and explicit legacy provenance.
- **12 full HTTP/worker checks** used an actual folder share between disposable accounts. The first account inferred two synthetic files; the second performed zero image downloads and zero detector calls, published its own groups and handled the empty-face result as a hit. A fresh request after share revocation was denied. Detection outputs were mocked synthetic data for this contract, not a recognition-quality evaluation.
- The existing **24 full HTTP integration checks** also passed with the real models and rights-documented public portraits. An extracted package verified the bundled SDK transport and SQLite FTS/vector runtime. No private images were opened for validation.
- A scoped production backup was retained. All eight existing AI/People tables, non-derived settings, worker cache logical contents and unrelated controls matched before/after fingerprints. Only the People worker restarted; the other three worker PIDs remained identical. The prior running People control was restored without resetting results.
- Unversioned legacy data is not automatically assigned current settings on arbitrary upgrades. On this host, the original activation snapshot, unchanged inference configuration, still-uninterrupted first run and file/model metadata verified the original run's provenance; an explicit signature pin permits lazy reuse of those results. Existing rows and groups were retained unchanged.

---

# People release validation — 2026-10-20

NextGallery **0.4.20** was tested and installed on Nextcloud **34.0.5.1** with MariaDB. A separate Nextcloud 34/MariaDB instance verified the upgrade from 0.4.19 without changing existing descriptions, OCR, visual vectors or shared results. Real Nextcloud 35/SQLite contracts were also exercised.

- 340 Python tests passed in the base environment; three tests required optional NumPy/OpenCV. The installed face runtime separately passed all 31 face tests.
- 20 Node/browser behavior tests passed, including real Chromium dialog and stale-gallery response checks. Desktop/mobile screenshots were visually inspected against the actual Nextcloud app; gallery and map markup, photo counts and protected confirmations were checked.
- 92 PHP face assertions and 49 real Nextcloud database/controller assertions passed. The existing PHP pipeline suites remained successful.
- 24 full HTTP integration cases covered public-image inference, zero-face files, account/gallery isolation, private previews, OCS headers/envelopes, corrections, current ETags, file deletion, cache removal, reset generations and account re-creation.
- A synthetic 100,000-vector search check recovered the known nearest identity in all 500 queries, taking 1.529 seconds in total and about 673 MiB peak process memory on the development host. It allocates a one-to-many score vector, not a pairwise distance matrix.
- The production Python 3.11.2 / OpenCV 4.14.0 / NumPy 2.4.6 runtime detected one face in each of four public portraits and grouped them into the two expected identities. No private media were inspected or processed.
- Live Web/OCS responses, served JavaScript bytes, four worker heartbeats, model setup and maintenance state were verified with a disposable account. Account deletion also removed its synthetic face-cache files.
- Existing canonical AI rows, worker cache contents and controls matched before/after fingerprints. Normal gallery-scan progress continued. A scoped backup and runtime rollback copy were retained on the server.

At the initial installation, person recognition remained globally disabled, with no folders opted in, and all pipelines retained their paused state. It was subsequently enabled for the owner's two existing selected folders at the owner's request. The four-portrait smoke check does not establish family-photo accuracy, recognition of children over time or individual pet recognition. Public fixture provenance and reproducible commands are in [benchmarks/faces](../benchmarks/faces/README.md).

---

# Validation of the live installation

Tested on 2026-10-03/04 against Nextcloud **32.0.5**, app **NextGallery 0.1.2**. The repository is private. Only generated, visibly marked demo media were used; no personal media folder was opened or configured.

## Live media and browser checks

- The isolated `/NextGallery-Demo` collection contains **296 media**, including **two three-second H.264 videos**. All 296 original files match their generated SHA-256 hashes after indexing, previews, playback and offline saving.
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
- Deleting the disposable test account through Nextcloud's public user API triggered the app's deletion listener. Both app tables were verified empty for that account. Test credentials and the disposable emulator were removed after validation; the reviewable `/NextGallery-Demo` collection remains.
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

Evidence is in ignored artifacts/v013-local-*.png and local-nc-v013-api.json. Node layout tests and all 19 PHP date/GPS assertions pass; PHP 8.2 lint passes. The existing server was subsequently updated over the visible SSH session to 0.1.3 after a verified 0.1.2 backup. The installed 49 files match the deployment manifest byte-for-byte. A live HTTP regression against the isolated `nextgallery-android-demo` account verified 296 synthetic records, two videos, 203 GPS records, JPEG preview/ETag, video range responses, the offline CSP and the Android embedded context; no private media was opened.

A subsequent real German-user test exposed app-specific IL10N falling back to English without a Nextcloud l10n/de.json file. Page context now uses the public IFactory::findLanguage() independently of app translation files. German desktop and an actual 390x844 same-origin embedded viewport both display German navigation, counts and month labels. The mobile screenshot contains the four bottom controls without visible overflow; artifacts/v013-local-de-mobile.png is a genuine browser capture. English was checked again after switching only the synthetic user's core language. PHP syntax and the existing Node geometry/date tests pass after this correction.

## NextGallery Server 0.1.4 — 2026-10-05

Display name changed to NextGallery Server; technical app ID/namespace/routes remain stable. Android 0.4.0 standard mode no longer needs this extension. API media now retain raw capture evidence plus mtime/size, enabling offline date recomputation.

Node tests (2) and build passed; PHP date/GPS tests passed all 19 assertions on the isolated Docker QA Nextcloud. Library.php syntax passed. Deployment over the existing SSH connection retained a complete 0.1.3 backup under /root/nextgallery-backups/nextgallery-0.1.4-20261005 with the previous-app directory for rollback. The 24 installed runtime files match source SHA256 exactly. Authenticated requests used only nextgallery-android-demo: 296 synthetic records including 2 videos, raw capture/mtime/size payload present, browser page displays NextGallery Server. Existing Nextcloud status remains maintenance=false, needsDbUpgrade=false. No private collection was inspected. This is a rename/API follow-up; earlier full browser layout/offline tests above were not all repeated for unchanged frontend code.


## NextGallery Server 0.1.5 — 2026-10-05

Nextcloud 35.0.1 / PHP 8.5.11 review VM: actual search/index of 296 synthetic media with zero errors on both dedicated accounts, two videos and 203 GPS entries; browser login, real Login Flow v2 grant/poll, English gallery/map/video and native optional-mode round trip passed. HTTPS proxy preserves conditional preview 304, video 206/suffix handling and 416; cross-account file-ID access and traversal settings are rejected. Exact AGPL corresponding source is publicly downloadable, with sidebar link. Runtime English JSON is now generated into templates so the standard runtime-only installation remains bilingual.

Nextcloud 32.0.5 / PHP 8.3 local QA: companion upgraded to 0.1.5, actual search/index of two newly generated fixture files passed with zero errors. All 19 date/GPS assertions passed on both PHP versions, and deployed PHP syntax plus both JS layout tests passed. Nextcloud 33/34 are within the declared API compatibility range but were not individually exercised. The private Nextcloud's existing 0.1.4 installation was not upgraded during review-VM provisioning.


## NextGallery Server 0.3.0 — canonical app identity

The app ID, PHP namespace, gallery/AI tables, routes, commands, browser storage and generated frontend now use NextGallery. The forwarding adapter and legacy command alias were removed. Android source uses the canonical gallery and AI OCS endpoints; its application identity/version/update policy stay unchanged.

Local verification: 113 Python regressions (including completed-file queue/revision preservation without inference), 377 PHP assertions and PHP syntax checks, 2 frontend layout tests and 5 LM Studio transport tests passed. The original two-app migration and merged 0.2.0 migration on disposable Nextcloud 32.0.5/MariaDB, plus a fresh Nextcloud 35.0.1/SQLite installation, each passed 27 core and 14 HTTP checks. All three cases passed both locally and in CI.

The synthetic browser check displayed OCR search results, description/tags/objects/OCR in the viewer and the processing overview under the canonical URL. No private media was opened; physical Android runtime and 33/34 are not independently verified. Production migration uses full database/app/state/config backups and aggregate hashes, with the existing queue left paused during deployment. Final production results are appended after deployment.


### Production and final delivery — 9 October 2026

PR #2 merged to main as a707831. Main [server CI 37933007866](https://github.com/wolpa29/nextcloud-nextgallery/actions/runs/37933007866) passed all PHP, Python and integration jobs. Android main 8d1b310 [CI 37932499567](https://github.com/wolpa29/app-nextgallery/actions/runs/37932499567) passed the JVM, debug/test APK, lint and release-bundle/content checks. No Android version bump, release or forced-update change was made. The existing public help pages and JSON assets match the regenerated local files; version policy is unchanged.

Nextcloud 32.0.5 on 10.1.10.186 runs NextGallery 0.3.0 in apps/nextgallery, with namespace OCA\Nextgallery, canonical catalog/AI tables and nextgallery-ai.service. State is data/.nextgallery_ai, environment /opt/nextgallery-ai-venv, CA directory /etc/nextgallery-ai. The predecessor app directories, unit and environment were moved outside active locations into the complete rollback backup /root/nextgallery-backups/20261009-124542-id-migration. Old app registrations, preferences, tables and jobs are absent. No redirects/forwarding adapter remain.

At the migration boundary, all 4,748 analyses and 20,645 gallery rows, original preferences, AI settings, credential ciphertext hashes and the SQLite queue match their pre-migration fingerprints. All 78 packaged runtime files match the deployment manifest. The extra AI language preference was checked against the old database backup separately. The existing paused/request state was restored; the worker is enabled, active, online and accesses its new HTTPS endpoint with certificate validation. Active queue remains 4,748 done / 20 error / 15,581 pending; inactive rows and saved results were retained.

Twenty live HTTPS checks passed with a disposable synthetic account: canonical gallery/search/details/OCS/status/provider, original bytes, preview, offline/service worker, access control, invalid worker token and retired endpoints returning 404 without redirects. The account was deleted afterwards. A final comparison against a temporary database restored only from the catalog/preferences backup confirmed all original catalog contents, settings and cache epochs remain identical; the regular background index job advanced scan cursors/generations normally. Analyses and queue stayed identical. The temporary verification database and local test containers were removed. No private media was opened. Physical Android runtime and the separate public review-server upgrade remain for the later Android release.


### Native design alignment — 9 October 2026, version 0.3.1

The server interface now uses the exact light/dark palette from `app-nextgallery`'s `AppTheme.kt`, with shared navigation, typography, surface shapes, inputs and primary actions. Styling covers the gallery, search, local map and controls, offline collection/export, settings and dialogs, dark media viewer, AI workspace, administrator form and Files metadata tab. Nextcloud's explicit light/dark preference takes precedence over the operating-system preference; the surrounding Nextcloud UI retains its own theme. On narrow screens the gallery uses five bottom destinations, with AI processing available inside Settings. `css/app-theme.css` is shared with the processing workspace and included by the build in `css/main.css` so cached and exported offline collections keep the same design.

Local Nextcloud 35.0.1/SQLite synthetic validation passed 27 integration assertions and 14 HTTP checks. The Node layout tests and all 19 PHP date/GPS assertions passed; runtime PHP syntax checks passed. Browser checks used only the isolated `merge-synthetic` account and covered gallery, OCR search result, media viewer, map, offline controls, settings, AI overview and processing form. Desktop and 390px responsive layouts, light/dark palettes, main-action contrast and absence of horizontal settings overflow were checked. Screenshots remain in ignored `artifacts/design-check`. No personal media or physical phone was used.

Production Nextcloud 32.0.5 was upgraded from 0.3.0 to 0.3.1 with `occ upgrade`. The full database, configuration, previous app and consistent paused worker state/SQLite queue were backed up under `/root/nextgallery-backups/20261009-design-031`. The worker was briefly stopped for a consistent state backup and restarted with its existing paused/request state. After upgrading, maintenance mode and needsDbUpgrade are false and `nextgallery-ai` is active. Before/after fingerprints confirm unchanged gallery rows, analyses, gallery preferences, AI settings, credential ciphertext and queue contents (excluding ephemeral lock rows).

All 79 installed runtime files match the 0.3.1 package by SHA-256. Seventeen live HTTPS assertions passed using the disposable `nextgallery-design-live` account: versioned gallery page, selected-account scope, filename search, synthetic preview/original bytes, offline shell/service worker, exact CSS/JS assets, unselected-file refusal and retired endpoint refusal. The synthetic account was deleted. The final original-data fingerprints still match the pre-upgrade snapshot; the worker remains paused=true/requested=false as before.


### Preserved AI metadata and named galleries — 9 October 2026, version 0.4.0

Completed AI metadata is protected both in worker discovery (including a missing/rebuilt queue) and the database publisher's unique user/file insert. Model, prompt and source ETag changes retain the original metadata, revision and provenance; unanalysed files use the current configuration. Older file versions remain visible with an explicit warning. Overview and folder counts include these preserved analyses independently of queue statistics. Reanalysis requires an explicit full reset, with account/count confirmation, paused/online checks, a count-change rejection and an authenticated worker endpoint that also requires the saved reset request.

Named galleries retain separate roots, offline identities, scroll positions and map positions, while settings and offline management apply across the account. Synthetic tests cover isolation in timeline, search and map, preservation of the previous gallery catalog/cache identity, and global language/display choices. Shared language and appearance extend to the processing workspace. Dialogs are centered, mobile navigation has four destinations with header search, the column mapping matches the native app, and drag-time scroll context appears beside the thumb.

Local validation: 115 Python regressions, 391 PHP assertions with syntax checks, three frontend layout tests and five LM Studio transport tests passed. Disposable Nextcloud 32.0.5/MariaDB upgrade cases and Nextcloud 35.0.1/SQLite fresh installation each pass 27 migration/scope assertions, 15 named-gallery/preservation assertions, 16 HTTP checks and three explicit-reset/original-preservation/new-analysis assertions. Browser validation used only the synthetic account and generated PNGs; DE/EN, light/dark, 390px/591px/1280px layouts, viewer navigation, preserved stale results, map/gallery switching and the disabled deletion confirmation were checked. Screenshots are in ignored `artifacts/ui-0.4.0`. No personal media or physical phone was inspected.

Production upgrade is pending: direct SSH authentication to the production host was unavailable in this session. No production reset or analysis was started. Installable and corresponding-source archives are prepared for the authorized upgrade; deployment must retain the existing pause/control state and verify unchanged AI metadata before/after.


### Direct settings and visible AI entry — 10 October 2026

Settings now save on change, with immediate theme feedback, serialized requests, pending-choice protection and rollback after a failed save. Older summary responses cannot replace preferences saved while they were loading. Saving and refreshing have distinct error messages. Theme/language and gallery display controls use direct segmented choices; the compact display dialog stays synchronized with Settings. Unsupported-by-the-short-list but valid stored time zones remain visible. The initial server-rendered theme avoids a light flash. Removed invalid mixed selector/media rules and the obsolete standalone dark palette override.

AI processing is a main navigation destination and has a visible, explanatory gallery introduction. The mobile dock includes KI; all entries are hidden offline and removed from portable exports. Existing server permissions still determine whether the processing entry is rendered. No worker or model configuration was changed.

Validation used only the disposable Nextcloud 35.0.1 instance and generated synthetic media. Fresh-install checks passed 27 migration/scope assertions, 15 gallery/preservation assertions, 16 HTTP checks and three explicit reset/original preservation assertions. Three existing frontend layout tests and PHP template syntax checks passed. `scripts/test-settings-ui.mjs` passed 35 browser checks for saved/reloaded preferences, rapid changes, failed-save recovery, DE/EN, matching AI-workspace themes and desktop/320px/390px/768px layouts, plus 108 CSS checks across OS, Nextcloud, explicit app themes and standalone shells; no browser errors. Screenshots and the result JSON are in ignored `artifacts/settings-check`.

For reproduction, retain the `fresh35` synthetic container, disable its `firstrunwizard` app, and run `node scripts/test-settings-ui.mjs` with Playwright available. `PLAYWRIGHT_MODULE` and `PLAYWRIGHT_EXECUTABLE` can point to an existing installation. Production was not deployed or inspected; physical Android/WebView behavior was not tested.


### Production installation — 10 October 2026, version 0.4.1

Installed on Nextcloud 32.0.5 at 10.1.10.186 using the authenticated SSH control connection. A full database/config/app/paused-worker backup is retained under `/root/nextgallery-backups/20261010-114002-041`. The installed 80 runtime files match the package manifest. The before/after audit verifies identical 5,083 AI analyses, 24,924 gallery rows, account preferences, AI settings, encrypted credential fingerprints, queue contents and worker control state. Nextcloud upgraded to 0.4.1 and exited maintenance mode; the AI service and cron are active, with processing still paused.

Four CSS/JS assets served over HTTPS match the local files byte-for-byte; TLS was verified against the existing local CA, and status reports maintenance=false / needsDbUpgrade=false. Sixteen live browser checks passed on a temporary ordinary account with an empty synthetic verification folder: version, direct light/dark saves and palettes, sort/group/columns, timezone persistence, DE/EN reload, mobile fit and protected AI access. No browser errors. Browser navigation used the test certificate override; the separate HTTPS asset/status checks verified TLS. The test account and credentials were removed. Final checks after removal confirm identical original catalog and analyses. Comparing the backed-up preference table in a temporary SQL table found a later theme-only change on an existing account during live verification; it was preserved. Preferences were identical at the installation boundary, and the other original settings remained unchanged. No personal media was opened and no AI work/reset was requested. Evidence is in ignored `artifacts/deploy-0.4.1`.


### AI resume dependency repair — 10 October 2026

Live resume requests were accepted, but the run paused again after about ten seconds with a misleading stream-disconnect message. The replaced app directory lacked `worker/lmstudio/node_modules`; importing `@lmstudio/sdk` failed with `ERR_MODULE_NOT_FOUND`. The previous app backup contained the SDK, and its lockfile matched the current app byte-for-byte. Restored those dependencies from the protected backup, checked the SDK import as `www-data`, and requested resume through the validated Nextcloud State service. Existing metadata, queue and settings were not reset.

Install archives now include the worker SDK dependencies and fail packaging if the SDK is absent or cannot load. The package regression check extracts the actual archive, imports the SDK, and runs the real bridge against a closed local synthetic endpoint; it must emit a structured unavailable-service event. This catches the startup failure that the earlier runtime-file manifest and gallery checks missed. CI runs this package check. The deployment helper derives the current version, rebuilds and verifies its archive, and requires an existing authenticated SSH control socket. Three frontend tests and Python/shell syntax checks also passed.

Live verification: all 973 files in the corrected runtime archive match the installed app, including the restored SDK. The original install archive fails the new regression check with `ERR_MODULE_NOT_FOUND`, while the corrected archive passes. After validated resume, the worker completed both model stages and the done count increased from 5,083 to 5,084; the next file was processing with a fresh heartbeat and paused=false. This verifies actual progress, not just an accepted request. Aggregate diagnostics only; no personal images, filenames or model output were inspected. Existing unrelated per-file limits remain recorded as file errors.


## 0.4.9 central model transitions and processing suspension

The administrator can suspend all account processing after the current file, clearing queued starts and blocking new/manual/scheduled runs until released. Worker cycles and HTTP transitions share a filesystem lock. A confirmed model reset clears all account vectors and embedding-cache rows immediately; descriptions/OCR remain unchanged. Optional automatic rebuilding queues only affected account selections, with `run_kind=embeddings` and no second user approval. Retaining vectors requires a live match of all stored spaces/dimensions and preprocessing. Configurations and affected counts are bound to the confirmation; stale counts, incompatible spaces, pending deletes and active worker locks are rejected.

Validation: 160 Python tests, 442 PHP assertions with complete syntax checks, three frontend tests; 30 new real Nextcloud admin-transition assertions, 35 personal-selection/progress assertions, existing account/isolation contracts, Nextcloud 35 SQLite and Nextcloud 32.0.5 MariaDB upgrade. Browser workspace audit: 168 checks and 101 screenshots without browser errors. Additional central-pause/model-dialog audit: 22 checks, including actual user API denial during admin suspension, all three dialog decisions, automatic rebuild on/off, and 320/390/1360px dialog layouts. Destructive model choices are mocked in the browser and validated against actual synthetic databases by the PHP contract; no private media or production model reset is used. The extracted packaged worker entrypoint, SDK import and unavailable-service handling pass.

Historical retrieval comparisons are published under `benchmarks/`, including synthetic images, vectors, qrels, full rankings, protocols and reports. Hybrid rankings and recall results were independently recomputed successfully from the published inputs. These are small synthetic, historical-baseline comparisons, not current production performance or a Qwen evaluation.


Production 0.4.9 deployment on 11 October 2026: protected backup `/root/nextgallery-backups/20261011-085657-049`; 988 installed runtime files match the verified archive. Database/config/state fingerprints confirm 5,141 analyses and 24,929 gallery rows, original settings, credential ciphertext, preferences, native queue and original paused control remain identical after installation and after removal of the verification accounts. Worker/cron/Apache are active, heartbeats fresh, maintenance off. Two disposable empty accounts passed 41 live browser checks without page errors, including real admin/user boundaries, new central-controls availability, personal selection and a completed empty description job. Accounts, scoped queues and temporary credentials were removed. No production model switch/reset/download or private-media analysis was performed. Feature CI [38119669050](https://github.com/wolpa29/nextcloud-nextgallery/actions/runs/38119669050) passed all seven jobs on commit `629aaeb86bf62d83861217680f606837736b8f49`.

A manual or centrally queued embedding rebuild waiting for model installation now stays pending and resumes after explicit setup completes, even when that account has no automatic daily schedule. A worker regression verifies the pending control and schedule state.

## 0.4.10 progressive gallery search

Validated on 11 October 2026. The `phase=text` request returns lexical results without calling the embedding model; an independent `phase=hybrid` request subsequently supplies the existing scoped RRF ranking. The text result remains usable during inference, on service errors and after the 22-second browser deadline. A new query, gallery change or leaving search cancels the browser request and rejects stale responses. Already started server-side work retains its existing bounded service timeouts.

Eleven frontend unit tests cover pending inference, outage, a hung transport ignoring cancellation, obsolete responses, gallery/view cancellation, disabled/unindexed models, pagination parameters and lexical errors. The complete disposable Nextcloud 35/SQLite and Nextcloud 32/MariaDB upgrade integrations passed, including 16 real database embedding contracts (four new immediate-search checks), 30 admin-transition checks and 35 personal-processing checks. Twenty additional browser checks passed with six desktop/mobile screenshots, no page errors, focused-tile preservation and the actual 22-second deadline. Delays/outages in the browser test are simulated; no private media or real model inference is used. Reproduce with `scripts/test-integration.py --case fresh35 --keep`, then `scripts/test-progressive-search-ui.mjs` using the configured Playwright runtime. Production preservation and deployment evidence is retained separately with the installation package in `artifacts/deploy-0.4.10`.

## 0.4.13 embedded AI navigation

The AI workspace is a lazy same-origin frame within the gallery. The parent keeps its gallery/map/offline/settings state and authenticated session. AI subviews use in-document navigation; gallery return and account/admin switching stay within the parent. Embedded mode changes layout and same-origin framing policy only, retaining Nextcloud authentication and role checks.

Validation: 11 JavaScript tests, PHP lint, fresh Nextcloud 35 integration (28 HTTP checks plus account/scope/preservation contracts), and 36 browser assertions with synthetic ordinary/admin accounts passed. Browser checks cover 320/390/768/1360px, session reuse, no parent navigation/popups, subviews, account/admin switching, gallery return, offline hiding and anonymous denial. A disposable API 28 WebView with the Nextcloud 35.0.0 URL callback policy confirmed that programmatic frame loading and history-based subview changes do not trigger its external-browser branch. This native probe uses a small synthetic page, not the full Nextcloud client; the complete UI flow was verified in Chromium. The API 37 emulator failed during boot due to a graphics-driver assertion. No existing emulator or private media was used.

Production 0.4.13 is installed on Nextcloud 32.0.5. Seven live browser assertions passed with a temporary empty ordinary account; six HTTPS UI assets matched local hashes with the local CA verified. Native Android entry opens the gallery for administrators as well, while browser administration entry retains its existing behavior. The first deployment rolled back after maintenance caused the active embedding worker to pause on HTTP 503; the completed deployment stopped the worker briefly before maintenance and restarted it afterwards. The installation-induced embedding pause was resumed without reset, with observed progress from 5,045 to 5,047 completed items. Nextcloud reports maintenance=false and needsDbUpgrade=false; the service is active. The synthetic account and only its matching hashed worker-state directory were deleted. A physical phone and the full official Android client were not tested.

## 0.4.14 Android client user-agent correction

The physical-phone report exposed a gap in 0.4.13 validation: the browser tests appended a Chrome identifier to the Nextcloud Android user agent, masking the core unsupported-browser redirect. That core script uses history.pushState followed by location.reload, which can invoke the client's external-browser branch at startup and inside the AI frame.

Android gallery and embedded AI responses now use authenticated blank standalone templates with only app assets and Nextcloud-generated session CSRF tokens. Ordinary browser pages keep the core shell; authentication, roles, same-origin framing and CSRF enforcement remain enabled. No server-wide browser compatibility setting is changed.

Local validation: 11 frontend tests, PHP/JavaScript syntax checks, complete fresh Nextcloud 35/SQLite integration (28 HTTP checks plus account/scope/preservation contracts), and 52 browser assertions with the exact unmodified Android UA. It exercises ordinary/admin accounts, startup, AI status and subviews, account/admin switching, gallery return, widths 320/390/768/1360, authenticated writes and tokenless denial, offline hiding and anonymous denial. A full official Android client and physical phone remain pending retest.

Production: reproduced the old core redirect on Nextcloud 32.0.5 using an empty temporary account and the exact client UA, then installed 0.4.14 with guarded hashes, backup and unchanged processing controls. Eight live browser assertions pass without login/unsupported redirects or page errors; five deployed JS/CSS assets match local hashes over CA-verified HTTPS. Nextcloud reports maintenance=false, needsDbUpgrade=false, and the AI service is active. The temporary account and only its hashed state were removed. The previously unpaused embedding queue required a continuation request after the graceful service restart; no analysis reset was performed.

Post-restart progress was verified: embeddings moved from 5,232 to 5,234 done (15,092 to 15,090 pending, 32 errors unchanged), with online=true, paused=false and state=running. Source commit is local; no GitHub push or new CI run was performed for this hotfix.
