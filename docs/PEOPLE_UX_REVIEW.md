# People workflow review — 2026-10-20

Prepared version: 0.4.33. Scope: Nextcloud NextGallery, based on `63f5403` / 0.4.32. The running installation was inspected through its browser controls and textual status/error views. Original photos and saved people assignments were not changed. Visual and mutation tests use synthetic images and isolated state.

## Findings and changes

| Area | Observed problem | Resulting behavior |
| --- | --- | --- |
| Overview | “All” excludes rare and quality-filtered groups. | “Overview”, a short explanation of other views, and loaded people counts. Names remain optional. |
| Management | Sharing and account-wide reset compete with everyday controls. | A management disclosure keeps both available. Reset explains all-gallery scope and irreversible loss of assignments. |
| Suggestions | Tall cards push photos down on small screens. Context thumbnails are mouse-only. | Compact cards, collapsible suggestions and real keyboard-accessible context buttons. |
| Suggestion completeness | The Suggestions filter omits rare good-quality groups that have suggestion badges. | Include those groups and keep the filter counter aligned. |
| Self-reassignment | Splitting detections into their own group can create contradictory constraints. | Reject the request atomically, preserving membership and rule revision. |
| Suggestions failing | Request failures silently look like no suggestions. | Separate retryable failure message; photos stay usable. |
| Refresh | Existing cards/photos disappear before the request succeeds. | Current-context results stay visible on refresh failure; switching gallery/filter clears old-context results. |
| Corrections | Double submissions and late responses can interfere with the current view. | Single correction in flight; stale person/gallery responses cannot update the new view. |
| Errors | Revision-conflict refresh clears the error; failed suggestion merge clears its own error on photo refresh. | Synchronize when needed and keep the failure visible. |
| Undo | Hide/show has no immediate undo. Failed undo discards the retry. | Hide, show and known/unknown flags have undo; failed undo stays available. Merge undo still separates moved detections, rather than restoring an exact historical group record. |
| Processing | A failed/paused run is described as an unavailable model service regardless of the actual cause. | Neutral status directs users to file/activity errors without inventing a cause. |
| File errors | Decoder internals, pixel guards and response parse errors appear as unexplained technical text. | Distinct understandable explanations with original technical details in a disclosure. |
| Terminology | Processing counts look like numbers of people; descriptions omit the fourth pipeline. | Explain that the count is checked photos, including photos with no detected face. Include People in the processing scope descriptions. |
| Native client parity | Live native lists/media ignore names, selected covers, named small groups and unknown-person flags that snapshots honor. | Live queries use the same visibility and metadata policy while preserving page-level live cover checks and all access/token guards. |
| Sharing | Help assumes raw inference sharing is enabled. | Explain the separate administrator switch and the opt-in sharing of personal sorting. |

## Pipeline and feature inventory

The existing People lane detects human faces with YuNet, computes SFace identity vectors, applies account quality policy and clusters/assigns detections. Canonical account state, file etags, generation and rule revisions guard publication and corrections. Worker checkpoints and persistent file errors support incremental runs and deliberate retry. Suggestions are generated at run completion; they are proposals, not verified identity matches.

Available workflows include anonymous groups and photo counts, gallery-scoped lists/media, rare and filtered detections, review, names, custom cover, full-image context/zoom, manual merge/split/reassignment, rejected suggestion pairs, known/unknown and hidden groups, bulk flags, account-local correction state and optional sorting adoption for mutually readable files. Recognition itself is not retrained by these UI actions. Individual pet identity recognition is not implemented by the human face models.

The live People status at inspection was 22,549 checked photos, zero pending and 16 file errors. The error list included incomplete/unreadable images, pixel guards and unreadable processing responses. These observations do not establish a model-service outage or measure recognition accuracy.

Quality settings observed live: minimum area 0, sharpness 45, minimum overview photos 3. No threshold, worker configuration, stored vector, name, correction, original or production process was changed by this review.

## Validation

- `npm run build`: successful; generated assets and English templates rebuilt.
- `npm test` with the available Playwright module and Chromium: 48 tests passed, zero skipped.
- Actual PHP template rendered/inspected and browser-tested at 320, 390 and 1360 pixels in light/dark themes: no horizontal overflow; management disclosure and collapsible suggestions work; 12 screenshots in `artifacts/people-workspace-ui/`.
- Extended `scripts/test-user-control-isolation-ui.mjs`: 79 checks passed, covering all four lanes, overlapping requests, pause/resume, duplicate controls and understandable People errors with retained technical details.
- `PYTHONPATH=pipeline:worker <face-runtime>/bin/python -m unittest discover -s pipeline/tests -p 'test_faces*.py'`: 70 tests passed.
- Real Nextcloud/MariaDB feature contract: 49 assertions passed, including self-split rejection with unchanged constraints and revision.
- Real Nextcloud/MariaDB suggestion contract: 37 assertions passed, including rare suggestion/count parity.
- Real Nextcloud/MariaDB native People contract: 93 assertions passed, including names, covers, named one-photo groups, quality/root guards, stranger visibility and snapshot parity.
- Native snapshot cleanup: 6 assertions passed.
- Packaged 0.4.33 runtime: 1,047 files; changed runtime bytes verified against source; extracted SDK/transport/SQLite/search checks pass.
- Source syntax and whitespace checks pass.

## Boundaries

The initial review and visual tests were local/read-only. After explicit owner approval, 0.4.33 was installed on 2026-10-21 with an atomic app exchange and protected-state comparison. This is not a People reset, recognition quality benchmark or physical-device test. No schema or model change is part of this patch. Existing People API behavior is aligned where documented above. The native API fix was completed and validated by the lead after stopping the delegated implementation before it made changes. Real image errors remain to be resolved individually; the revised UI explains them without modifying originals or silently weakening decode guards.


## Independent audit triage

Antigravity reviewed the worker, canonical service and native client API separately. Its report was checked against source and live behavior. The claimed broken `ch-people-detail` lookup is not a defect: the existing lookup helper aliases it to `ch-person-detail`, and the actual-template browser test exercises this path. A claimed cross-pipeline leak of `pause_reason` was not established; the verified defect is the generic UI explanation, not a proven failure of lane isolation. Worker revision retries do not establish the cause of the 16 live errors; the actual displayed messages are recorded above.

The audit also noted the existing limitation of merge separation, which does not restore historical group metadata. UI labels/help now explain this limitation instead of promising an exact historical undo. Snapshot cleanup/scalability observations require separate measurement and lifecycle analysis; they are not reported as proven data leaks or recognition failures.


## Authorized production installation — 2026-10-21

Installed 0.4.33, package SHA-256 `510e08510cf038c06ec3d448a42828f2a58b5db0ef48d1f327d2414815aae818`. All 1,047 installed runtime files match the prepared package. Fourteen protected SQL/settings summaries, five logical worker databases, all saved controls/schedules and all five worker PIDs match the pre-install baseline. The worker runtime is unchanged, so no worker was restarted. Backup: `/root/nextgallery-backups/people-ux-0433-20261020-220549` (server UTC timestamp). Maintenance is off and no app upgrade is pending.

The existing authenticated browser shows version 0.4.33 and the new People workspace, including the management menu and 133 suggested groups instead of 25 after rare suggested groups were included. Main/rare/filtered/unknown counts remain 38/637/345/30. No People correction, threshold, reset or retry was triggered during verification. The existing 16 People file errors remain separate from deployment success.
