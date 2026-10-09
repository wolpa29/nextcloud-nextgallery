<picture>
  <source media="(prefers-color-scheme: dark)" srcset="docs/assets/media-ai-dark.svg">
  <img src="docs/assets/media-ai.svg" alt="Media AI" width="320" height="80">
</picture>

Lokale Bild- und Videoanalyse für Nextcloud. Macht Fotos über Bildbeschreibungen,
Suchbegriffe und erkannten Text auffindbar. Originaldateien bleiben unverändert.

## Funktionen

- Bildbeschreibungen und OCR in der Nextcloud-Suche und Dateiseitenleiste.
- Videoanalyse anhand gleichmäßig verteilter Einzelbilder.
- Übersicht und Datei-Explorer mit Analysestatus, auch in Nextcloud Dateien.
- Ordnerauswahl, täglicher Zeitplan, Start/Pause und Aktivitätslog.
- Getrennte Modelle für Bildanalyse und OCR; Ergebnisse werden zwischengespeichert.

## Installation

Voraussetzungen: Nextcloud 32, PHP 8.2+, MariaDB/MySQL, Python 3.10+ und
FFmpeg. Dazu ein bildfähiger Modellserver, etwa LM Studio; dessen SDK-Zugang
benötigt Node.js 18+ und npm.

1. `nextcloud-app/media_ai` in den Nextcloud-App-Pfad kopieren und mit `occ app:enable media_ai` aktivieren.
2. Den Worker mit `scripts/install-worker.sh` einrichten.
3. In **Media AI** Modellserver und Ordner einstellen, speichern und den ersten Lauf starten.

Die vollständige [Installationsanleitung](docs/APP_WORKER.md#installation)
enthält die Befehle, Dienstkonfiguration und Hinweise für Updates.

Die App wird manuell installiert und ist nicht im Nextcloud App Store veröffentlicht.
OCR und Beschreibungen können Fehler enthalten. Videos werden stichprobenartig
analysiert; Audio und Video-OCR sind nicht enthalten.

## Dokumentation

[Bedienung und Einstellungen](docs/APP_WORKER.md) ·
[Controller und Speicherung](docs/CONTROLLER.md) ·
[Prüfergebnisse](VALIDATION.md)
