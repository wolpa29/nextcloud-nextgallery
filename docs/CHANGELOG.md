# Version history

[Back to the overview](../README.md) · [Administration](ADMINISTRATION.md)

Historical notes moved from the original README. This is a partial history; see the [commit history](https://github.com/wolpa29/nextcloud-nextgallery/commits/main/) for newer changes.

| Version | Android companion | Legal revision | Technical changes | Legal changes |
| --- | --- | --- | --- | --- |
| 0.4.9 | Native app unchanged | No policy or legal change | Central pause for all user runs; confirmed global vector/cache reset with optional automatic embedding rebuild; retain vectors only after a matching vector-space check; synthetic comparison data and scripts under benchmarks/. | None. |
| 0.4.8 | Native app unchanged | No policy or legal change | Central admin model settings; personal folders, exclusions and schedules; separate description/vector inventory counts and filters; confirmed model changes across accounts; automatic replacement of old file vectors after successful publication; accessible responsive dialogs and consistent light/dark UI. | None. |
| 0.4.5 | Native app unchanged | No policy or legal change | Optional hybrid image-vector search, managed LiteRT/full Gemma CPU models, explicit installation/removal and vector-rebuild confirmation. | None. |
| 0.4.1 | Native app unchanged | No policy or legal change | Direct autosaving settings and theme/language choices, synchronized display controls, explicit-theme fixes, and prominent AI entry on desktop and mobile. | None. |
| 0.4.0 | Native app unchanged | No policy or legal change | Named galleries with shared settings/offline management, centered dialogs, native column controls and fast-scroll context. Existing AI analyses never overwrite; model changes apply only to missing analyses, full reset requires an explicit count-aware confirmation. | None. |
| 0.3.1 | Native app unchanged | No policy or legal change | Shared native light/dark palette, navigation, surfaces and controls across gallery, search, map, offline, settings, viewer, AI workspace and Files metadata; responsive access to AI settings. | None. |
| 0.1.4 | 0.4.0 / code 6 | 2026-10-05.1 (Android) | Rename to NextGallery Server; retain predecessor app ID; expose original capture evidence and mtime/size for offline date changes. Android extension is optional. | Clarify separate AGPL server licensing and optional processing on the user's Nextcloud. |
| 0.1.5 | 0.4.1 / code 7 | 2026-10-05.2 (Android) | Nextcloud 35 search-query compatibility; package English template translations with runtime files; optional corresponding-source link. Verified on Nextcloud 32.0.5 and 35.0.1; 33/34 not individually exercised. | Exact AGPL server source offer for public synthetic review service; proprietary Android code is separate. |
| 0.3.0 | Updated endpoints; client update required | No forced-update or publication | Canonical nextgallery ID, namespace, tables, worker service and APIs; verified data migration; no redirects. | Names and server licence references updated. |
| 0.2.0 | Existing native clients unchanged | No Android policy change | Merge Media AI and gallery, preserve data/routes via optional compatibility adapter, shared lexical search and viewer AI details; portable AI storage. | Unified server source includes AI worker and adapter. |
