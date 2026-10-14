<img src="img/app-color.svg" width="64" alt="NextGallery icon">

# NextGallery for Nextcloud

**Your photos. Your Nextcloud. Easier to explore.**

Turn the photo and video folders you already have in Nextcloud into a gallery you enjoy browsing. Rediscover a holiday, find a picture, or see where your memories were made — right in your browser.

[Deutsch](docs/README.de.md) · [Installation](docs/ADMINISTRATION.md#build-and-install) · [Android app](https://github.com/wolpa29/app-nextgallery) · [Feedback & issues](https://github.com/wolpa29/nextcloud-nextgallery/issues)

## What can I do with it?

| You want to… | NextGallery helps you… |
| --- | --- |
| **Browse your memories** | Explore photos and videos in a timeline, jump between dates, and open them in the built-in viewer. |
| **Keep collections simple** | Create named galleries from selected Nextcloud folders — holidays, family, hobbies, or anything else. |
| **Find a particular photo** | Search filenames and, with optional AI, descriptions, tags and text found in images. |
| **Explore places** | See photos and videos with GPS information on a world map. |
| **Take a gallery with you** | Save previews for offline browsing or download a portable offline collection. |

Adjustable layouts, light/dark themes and English/German controls work across desktop and mobile.

## Optional AI: find more than a filename

Remember *a dog on the beach*, but not the filename? Optional AI makes processed media searchable by content:

- **Descriptions & tags** make photos and videos easier to discover.
- **Text recognition (OCR)** helps you find words in photographed signs or documents.
- **Visual search** finds relevant images using a description of what you remember.

You choose processing folders and schedules, with separate start/pause controls for each AI feature. **The gallery works without AI.**

## Get started

1. **Ask your administrator to install NextGallery Server.** [Installation guide →](docs/ADMINISTRATION.md#build-and-install)
2. **Open NextGallery → Settings → Manage galleries.** Give your gallery a name and choose its folders.
3. **Start exploring.** Browse the timeline, try Search, or open Map.

**Requirements:** Nextcloud 32–35, supported PHP (8.2+), working previews and cron. AI additionally needs worker/model setup.

## Your files, your choices

Your original photos and videos remain unchanged in Nextcloud. AI uses the model services configured by your administrator; these can run on your own hardware. [Setup details →](docs/ADMINISTRATION.md#ai-administration-and-external-embedding-server)

Offline saves **previews, not a full backup**. Original video playback needs a connection; reopening browser collections requires Service Worker support. Portable offline files are an alternative.

**Using Android?** The separate [NextGallery app](https://github.com/wolpa29/app-nextgallery) works without this extension; optional server mode adds the shared index. The official Nextcloud Android app can open the browser gallery via [External sites](docs/ANDROID.md).

---

Need more detail? [Administration](docs/ADMINISTRATION.md) · [AI & visual search](docs/EMBEDDINGS.md) · [Embedding server setup](docs/EXTERNAL_EMBEDDING_SERVER.en.md) · [Migration](docs/MERGE.md) · [Validation](docs/VALIDATION.md) · [Version history](docs/CHANGELOG.md)

Open source under [AGPL-3.0-or-later](LICENSE). Third-party credits: [NOTICE](NOTICE). Running a public installation? See the [network source offer](docs/ADMINISTRATION.md#network-source-offer).
