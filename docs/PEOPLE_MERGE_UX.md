# People merge and preview corrections

The People overview supports merging several selected groups in one operation. Select at least two groups, choose **Merge**, then explicitly choose which person to keep. Another target can be found in the expandable search area. A two-person merge also offers **Reverse direction**. Suggestions use the same dialog and retain their existing default direction and next-suggestion navigation.

The preview identifies incoming groups and the surviving person. The target's name and cover take priority; missing metadata is filled from source groups. It explains that corrections apply across the account's galleries, that originals are preserved, and that separating added photos does not restore former names or covers. Hidden or unknown target status is called out separately.

`POST subjects/bulk-action` accepts `action: merge`, `ids` (source IDs), `target_id` and `rules_revision`. The service validates every source and target under the existing account lock, then applies all merges in one transaction and increments the revision once. Missing or foreign groups, self-merges, stale revisions and more than 500 sources are rejected. Single and bulk merge share the same implementation for metadata, manual detections, constraints and suggestion remapping.

Successful merges return `merged_groups` for separate follow-up splits. Splitting uses batches of at most 500 detections per original source, retaining progress for retries. It creates new groups and does not claim to restore all former metadata.

Preview preferences are synchronized to the checkbox on render, show and browser page restoration. The checkbox disables form-state autocomplete and has explicit native styling. A failed server crop returns an unavailable preview instead of silently returning the full photo.

On small screens the overview uses a compact filter dropdown. The merge dialog uses stacked, labelled source and target summaries, an expandable target search, and a sticky confirmation area with touch-sized controls. Late candidate responses cannot override a later filter or gallery selection. Failed writes retain the chosen target; revision conflicts refresh the view and explain the failure.

## Local validation

- `npm test` with Playwright and Chromium enabled, including `tests/people-merge.test.mjs`; `scripts/test-faces-ui.mjs` runs both People test files.
- Disposable `nextgallery-faces34` Nextcloud/MariaDB contracts: 96 user-feature assertions, 49 storage/account/API assertions, 37 suggestion/reuse/gallery-scope assertions and 93 native People assertions.
- Real shell template and synthetic images at 320, 390, 768 and 1360 pixels in light and dark appearance. Screenshots: `artifacts/people-merge-ux/`.
- PHP syntax checks, JavaScript syntax check and `git diff --check`.

These checks use local and disposable test environments. Production deployment is verified separately with runtime hashes and a before/after preservation audit. Private-library accuracy and physical-device behavior were not tested; this change does not require a data reset.
