# Video speech transcription

NextGallery has an optional fifth processing pipeline, **Videoton** (video audio). It transcribes speech in videos locally with [faster-whisper](https://github.com/SYSTRAN/faster-whisper) and multilingual Whisper models. Transcripts and segment timestamps are stored separately from descriptions and OCR, appear in media details and participate in text search. The search source **Gesprochene Inhalte** restricts results to transcripts. This is speech recognition: background sounds, music identification, speaker identification and audio embeddings are outside this pipeline.

## Enable and use

1. An administrator enables **Videoton** in AI settings. The worker prepares an isolated runtime in `.nextgallery_ai/audio-local/runtime`; installation progress/errors are visible in processing status. The base worker and the four existing runtimes are unchanged.
2. The portable default is multilingual `small`, CPU, `int8`, four threads. On the 12 GiB Nextcloud installation, use `large-v3` for quality, with a 24-hour per-video budget; a synthetic container test measured about 4.9 GiB peak child RSS. Language can be detected automatically or explicitly selected. `tiny`, `base`, `medium`, `large-v3` and `large-v3-turbo` are alternatives. Larger models need more memory and processing time. CUDA requires compatible server drivers/libraries; CPU works without CUDA.
3. Each user explicitly selects **Videoton** for the desired processing folders, then starts the lane. Existing folder choices never opt into audio automatically. Audio has independent start, pause, retry, schedule, activity, counts and deletion controls.
4. Search normally to include speech, or select **Gesprochene Inhalte** for transcript-only results. Existing gallery, folder, date, media and permission restrictions still apply.

Enabling the feature downloads the Python runtime dependencies. The first transcription downloads the selected pinned model from Hugging Face; subsequent processing uses the local cache. Original video/audio never leaves the server for inference. No API key or paid transcription service is required. Model repository revisions are recorded in `worker/audio_models.json`. Models and faster-whisper use MIT licences; notices are in `docs/licenses/audio-whisper.txt`. Optional dependencies retain their own licences in the private runtime.

## Boundaries and data preservation

Only videos explicitly selected for this lane are processed. Files without an audio track and files without detected speech produce a valid empty transcript. Speech activity detection suppresses silent passages; recognition can still make mistakes, particularly with dialect, noise and music. Synthetic smoke tests establish wiring and model execution, not accuracy on a personal library.

The per-video budget defaults to 3,600 seconds, including extraction, model loading/download and recognition. Videos longer than four hours fail visibly rather than producing a silently truncated transcript. Text and segment limits also fail explicitly. `ffmpeg` and `ffprobe` must be installed. Local authorized videos are opened read-only; remote/encrypted storage falls back to a bounded temporary download. Temporary extracted audio is private and removed after processing or failure.

Pause terminates only audio's current decoder/inference child. Completed transcripts survive folder/model changes and stay available until the owner explicitly deletes audio data. Publication retries use the saved transcript rather than performing recognition again. Audio deletion does not remove descriptions, OCR, vectors, people or original media. Updating from four lanes only adds audio controls/status/schedule, paused by default, and advances the pipeline marker to version 4; previous lane files and caches are preserved.

The normal `nextgallery-ai.service` supervises all five lanes after its next start. To introduce audio while old lanes are actively processing long videos, a deployment may start `app_worker.py --bootstrap <existing-bootstrap> --pipeline audio` as a temporary separate service with the same service identity and filesystem restrictions. The updated supervisor detects a lane's process lock, so a later supervisor restart does not duplicate it. After a host reboot the regular supervisor starts audio itself. Runtime installation can also be prepared explicitly with `audio_setup.py --directory <private-state-directory>` without enabling any account's processing.

## Validation

`pipeline/tests/test_audio_pipeline.py` covers preservation during migration, isolated cancellation/deadlines, publication retry and no-track completion. Real synthetic speech/no-audio smoke tests use the same FFmpeg and faster-whisper path as the worker. PHP integration contracts cover independent publication, stage deletion, opt-in, transcript-only search, stale versions and account/gallery restrictions. Browser checks cover lane controls and search interaction. No private media is needed for validation.
