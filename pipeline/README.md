# Media AI controller inside NextGallery

This repository's Media AI history and implementation have been merged into **NextGallery Server**. The maintained Nextcloud application is now the root `chronicle` app. See [integration, compatibility and migration](../docs/MERGE.md).

The worker has one implementation in `../worker/`. This folder retains its portable controller entry point, examples, installers and regression suites. Historical notes under `docs/` and `VALIDATION.md` describe previous standalone versions; their old `nextcloud-app/media_ai` install paths are superseded by the integration guide.

```sh
python3 -m pip install -r ../worker/requirements.txt
npm ci --ignore-scripts --prefix ../worker/lmstudio
python3 media_pipeline.py --help
```

Existing portable configurations still use the old OCS addresses via the optional forwarding adapter. New configurations can select `nextcloud.app_id: chronicle`, as in `config.example.json`. The bundled native daemon uses `occ chronicle:ai-setup`; `media-ai:setup` is retained as a command alias. The existing worker service name, private state directory and native queue identity are preserved.
