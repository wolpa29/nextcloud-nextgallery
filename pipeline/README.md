# AI controller inside NextGallery

The maintained server app is `nextgallery` in [nextcloud-nextgallery](https://github.com/wolpa29/nextcloud-nextgallery). The predecessor repository is archived. See [migration and APIs](../docs/MERGE.md).

`../worker/` contains the sole worker implementation. This folder contains the portable CLI entry point, example configuration, installers and regression tests. Historical validation notes describe previous versions; current installation paths and APIs are in the migration guide.

```sh
python3 -m pip install -r ../worker/requirements.txt
npm ci --ignore-scripts --prefix ../worker/lmstudio
python3 media_pipeline.py --help
```

The native worker uses `occ nextgallery:ai-setup`, `.nextgallery_ai` and `nextgallery-ai.service`. Portable publication uses `nextcloud.app_id: nextgallery`. There are no legacy endpoint adapters.
