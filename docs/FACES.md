# People in NextGallery

NextGallery can group photographs by the people appearing in them. The People view uses a face thumbnail and a photograph count for each group; it does not require or store names. Selecting it opens its photographs. One photograph can belong to several people.

## Enable and run

1. A Nextcloud administrator opens **NextGallery → Server settings → People** and explicitly installs the face runtime and models. Downloads do not start merely by saving settings or enabling a pipeline.
2. Enable person recognition in the server settings.
3. Under **My processing → Folders and schedule**, select **People** for the folders to process. Existing folders keep their previous three pipeline choices after an upgrade.
4. Start the **People** pipeline. It has its own pause, resume, retry, schedule, status and errors. Descriptions, OCR and visual image search retain their own data and controls.
5. Open **People** in the gallery. Hidden groups, individual detections and cases requiring review have separate filters.

Processing currently supports photographs. Video face tracks and individual pet recognition are not included in this release. Existing descriptions and image search can still find animal categories.

## Reduce background faces

Under **My processing → Folders and schedule → People filters**, each account can adjust three independent filters after pausing processing:

- **Minimum face area:** default **0.25%** of the oriented photograph. Smaller faces remain available under **Filtered faces**.
- **Minimum sharpness:** default **45**, measured as grayscale Laplacian variance on the aligned 112×112 face crop. Zero disables this filter. It is a heuristic for blur, not a guarantee that a face belongs to the main subject.
- **Minimum photographs:** default **3** for new installations. Existing administrator group-size defaults are retained until the account chooses its own value. People below this count are accessible under **Rare people**.

Small and blurry detections are retained but do not anchor automatic grouping of other photographs. Shared file artifacts retain the raw metric; each account applies its own policy. The main and filtered detail views use the same quality criteria as their overview counts, including groups with a mixture of good and weak detections.

Select a filtered detection and choose **Confirm** to retain that personal assignment despite the quality filters. It can then appear under Rare people until the configured photograph count is reached. Existing manual corrections remain authoritative. Hidden groups remain accessible through the Hidden filter.

Quality settings can be changed without re-running the model when the quality metric is present. Older detections did not record sharpness and are treated as unknown rather than assigned a fabricated score. A deliberately requested People reset clears that account's groups and corrections and allows a complete fresh run with the metric; it does not delete original photographs or other AI stages.

## Correcting groups

Groups can be merged, selected detections can be separated, and groups can be hidden or shown. Corrections apply immediately and are stored independently of the model vectors. A hidden group is retained; deleting all People data is a separate explicit action.

### Merge suggestions

Automatic grouping never joins two groups once both exist, so a person split early in a run stays split. After each complete People run the worker therefore compares the average face of every group with at least two faces against all other groups, including rare ones. Pairs whose similarity lies at most 0.15 below the configured assignment threshold (default 0.55, so from 0.40; never below 0.30) are offered under **Possibly the same person** in the person's detail view and through the **Suggestions** filter. At most eight neighbours per group and 5,000 pairs per account are kept.

Nothing is merged automatically. **Merge** moves the suggested group into the open person; **Different person** stores a decision that later runs cannot override. Splitting or removing photos counts as the same decision for the affected groups. Decisions follow a group when it is merged into another. Suggestions only refer to groups that still exist, are not hidden and have a currently readable photograph in the requested gallery. The thresholds are provisional until measured against a real library.

The model is not guaranteed to recognize every appearance. Side views, small or blurred faces, occlusion, children growing up, and very similar people may require correction. Model benchmarks and small public-image smoke tests do not establish the accuracy of a private photo library.

## Data ownership and deletion

Published detections, vectors, processed-file versions, stable groups and correction rules belong to the account in NextGallery's tables inside the configured Nextcloud database. Worker `faces.sqlite3` is a derived working cache. It is not the source of user corrections.

Face inference is reusable for the same Nextcloud file ID, current ETag and inference revision. Its dedicated cache contains only normalized face positions, detection scores and vectors. Accounts with current read access and an explicit People folder selection can reuse these results without downloading or analyzing the image again. Separate copies with different file IDs are processed separately. An administrator controls this reuse with its own switch (**Reuse face detection between accounts**); until that choice is saved it follows the general result-sharing setting. Turning it off removes the shared face cache at the next hourly cleanup.

Groups, detection IDs, hidden status, reviews, merge/split decisions and correction rules remain private to each account. Reused detections are assigned to that account's own groups. Groups, counts and media are taken from the gallery index for the requested gallery, the same source as the timeline counts. Every returned photograph and every cover or face crop is checked live for read access, gallery scope and file version first; without a verifiable home folder nothing is returned. A count can therefore lag briefly behind a revoked share until the next index sweep, but no file or face crop of it is shown. Cropped thumbnails are generated on demand and are not stored as separate permanent image files.

Merge suggestions and "different person" decisions are account data like corrections: reset, account deletion and cleanup remove them, and a suggestion upload is accepted only for the account's current epoch and generation.

An account reset changes its publication generation. Results calculated for an older generation cannot restore deleted data. Reset and account deletion remove the account's cache references; an artifact is removed when no account retains a reference. Other accounts' existing results and corrections are retained. Periodic cleanup also withdraws references whose source is no longer accessible or opted in, and removes obsolete file versions. Restarting a reset account can reuse an artifact still retained by another account, while creating fresh personal groups. Original photographs, descriptions, OCR and visual image-search vectors are separate from a People reset.

The inference revision includes the model weights, preprocessing and detection settings. Grouping thresholds and personal corrections do not invalidate shared inference. Compatible signed results can be reused lazily; upgrading does not reset or recompute the existing personal library. The original People release did not record detection settings per file. Such older results are eligible for reuse only when an operator has verified their original settings and explicitly pinned `ai_faces_legacy_inference_revision`. Without that proof, existing personal results remain valid, but the first other account computes a signed reusable result. Face artifacts use their own storage and worker-only endpoint, outside the description/OCR/image-embedding shared-results store.

## Runtime and model sources

The worker uses OpenCV YuNet face detection, five-landmark alignment and SFace face embeddings. Model URLs, expected sizes and SHA-256 values are recorded in `worker/faces_models.json`. Downloads are verified before installation in the private `faces-local/` directory. The isolated runtime does not install OpenCV into the system Python environment.

The OpenCV Zoo publishes YuNet under MIT and SFace under Apache 2.0. License notices and links are included under `docs/licenses/`. These statements describe the published model licenses; they are not a blanket assurance about every deployment or the original training data. The SFace research repository mentions CASIA-WebFace, VGGFace2 and MS-Celeb-1M experiments; the exact training provenance of the converted OpenCV checkpoint is not fully specified there.

- YuNet: https://github.com/opencv/opencv_zoo/tree/main/models/face_detection_yunet
- SFace: https://github.com/opencv/opencv_zoo/tree/main/models/face_recognition_sface
- SFace research: https://github.com/zhongyy/SFace

## API

The versioned subject list and subject-media APIs use the signed-in account, gallery/folder scope and cursor pagination. Public responses contain group IDs, counts, cover-detection IDs and permitted media, never face vectors. `subjects/{id}/similar` returns up to eight suggested groups with count, cover and similarity, and `filter=suggested` lists main groups that have open suggestions; `action=dismiss` with `target_id` records "different person". Corrections carry a rules revision so stale client operations cannot silently overwrite newer changes.

Worker publication additionally carries the account epoch, data generation, rules revision, file version and model space. The server validates them within the account publication transaction. Search and clustering caches are rebuildable projections of this authoritative state.

## Scale and validation limits

The People overview reads detections from the gallery index and checks only the returned page live. With 50,000 synthetic detections in 2,000 groups on the Nextcloud 34/MariaDB test instance, one overview request took about 0.7 s; checking every file live, as before 0.4.23, would have taken at least about 14 s per request at the measured 0.28 ms per lookup of a missing file (existing files cost more).

Grouping uses exact cosine nearest-neighbor comparison over a contiguous vector matrix, with a small buffer for newly committed detections. It does not allocate a face-by-face distance matrix. A synthetic 100,000-vector check found the known nearest identity in all 500 perturbed queries, taking about 3 ms per query on the development host; peak process memory was about 673 MiB. This measures the search mechanism, not recognition accuracy or total processing time. Hardware, the photo decoder and the number of detected faces affect real workloads.

The release smoke evaluation uses four rights-documented public portraits and a generated image with no face. It verifies the complete publication, grouping, permissions and correction flow. It does not establish family-photo accuracy, accuracy for children over time or recognition of individual animals.
