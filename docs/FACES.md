# People in NextGallery

NextGallery can group photographs by the people appearing in them. The People view uses a face thumbnail and a photograph count for each group; it does not require or store names. Selecting it opens its photographs. One photograph can belong to several people.

## Enable and run

1. A Nextcloud administrator opens **NextGallery → Server settings → People** and explicitly installs the face runtime and models. Downloads do not start merely by saving settings or enabling a pipeline.
2. Enable person recognition in the server settings.
3. Under **My processing → Folders and schedule**, select **People** for the folders to process. Existing folders keep their previous three pipeline choices after an upgrade.
4. Start the **People** pipeline. It has its own pause, resume, retry, schedule, status and errors. Descriptions, OCR and visual image search retain their own data and controls.
5. Open **People** in the gallery. Hidden groups, individual detections and cases requiring review have separate filters.

Processing currently supports photographs. Video face tracks and individual pet recognition are not included in this release. Existing descriptions and image search can still find animal categories.

## Correcting groups

Groups can be merged, selected detections can be separated, and groups can be hidden or shown. Corrections apply immediately and are stored independently of the model vectors. A hidden group is retained; deleting all People data is a separate explicit action.

The model is not guaranteed to recognize every appearance. Side views, small or blurred faces, occlusion, children growing up, and very similar people may require correction. Model benchmarks and small public-image smoke tests do not establish the accuracy of a private photo library.

## Data ownership and deletion

Published detections, vectors, processed-file versions, stable groups and correction rules belong to the account in NextGallery's tables inside the configured Nextcloud database. Worker `faces.sqlite3` is a derived working cache. It is not the source of user corrections.

Face inference is reusable for the same Nextcloud file ID, current ETag and inference revision. Its dedicated cache contains only normalized face positions, detection scores and vectors. Accounts with current read access and an explicit People folder selection can reuse these results without downloading or analyzing the image again. Separate copies with different file IDs are processed separately. The administrator's existing result-sharing setting also controls this reuse.

Groups, detection IDs, hidden status, reviews, merge/split decisions and correction rules remain private to each account. Reused detections are assigned to that account's own groups. Gallery scope, live file access and file version are checked before returning groups, counts, media or thumbnails. Cropped thumbnails are generated on demand and are not stored as separate permanent image files.

An account reset changes its publication generation. Results calculated for an older generation cannot restore deleted data. Reset and account deletion remove the account's cache references; an artifact is removed when no account retains a reference. Other accounts' existing results and corrections are retained. Periodic cleanup also withdraws references whose source is no longer accessible or opted in, and removes obsolete file versions. Restarting a reset account can reuse an artifact still retained by another account, while creating fresh personal groups. Original photographs, descriptions, OCR and visual image-search vectors are separate from a People reset.

The inference revision includes the model weights, preprocessing and detection settings. Grouping thresholds and personal corrections do not invalidate shared inference. Compatible signed results can be reused lazily; upgrading does not reset or recompute the existing personal library. The original People release did not record detection settings per file. Such older results are eligible for reuse only when an operator has verified their original settings and explicitly pinned `ai_faces_legacy_inference_revision`. Without that proof, existing personal results remain valid, but the first other account computes a signed reusable result. Face artifacts use their own storage and worker-only endpoint, outside the description/OCR/image-embedding shared-results store.

## Runtime and model sources

The worker uses OpenCV YuNet face detection, five-landmark alignment and SFace face embeddings. Model URLs, expected sizes and SHA-256 values are recorded in `worker/faces_models.json`. Downloads are verified before installation in the private `faces-local/` directory. The isolated runtime does not install OpenCV into the system Python environment.

The OpenCV Zoo publishes YuNet under MIT and SFace under Apache 2.0. License notices and links are included under `docs/licenses/`. These statements describe the published model licenses; they are not a blanket assurance about every deployment or the original training data. The SFace research repository mentions CASIA-WebFace, VGGFace2 and MS-Celeb-1M experiments; the exact training provenance of the converted OpenCV checkpoint is not fully specified there.

- YuNet: https://github.com/opencv/opencv_zoo/tree/main/models/face_detection_yunet
- SFace: https://github.com/opencv/opencv_zoo/tree/main/models/face_recognition_sface
- SFace research: https://github.com/zhongyy/SFace

## API

The versioned subject list and subject-media APIs use the signed-in account, gallery/folder scope and cursor pagination. Public responses contain group IDs, counts, cover-detection IDs and permitted media, never face vectors. Corrections carry a rules revision so stale client operations cannot silently overwrite newer changes.

Worker publication additionally carries the account epoch, data generation, rules revision, file version and model space. The server validates them within the account publication transaction. Search and clustering caches are rebuildable projections of this authoritative state.

## Scale and validation limits

Grouping uses exact cosine nearest-neighbor comparison over a contiguous vector matrix, with a small buffer for newly committed detections. It does not allocate a face-by-face distance matrix. A synthetic 100,000-vector check found the known nearest identity in all 500 perturbed queries, taking about 3 ms per query on the development host; peak process memory was about 673 MiB. This measures the search mechanism, not recognition accuracy or total processing time. Hardware, the photo decoder and the number of detected faces affect real workloads.

The release smoke evaluation uses four rights-documented public portraits and a generated image with no face. It verifies the complete publication, grouping, permissions and correction flow. It does not establish family-photo accuracy, accuracy for children over time or recognition of individual animals.
