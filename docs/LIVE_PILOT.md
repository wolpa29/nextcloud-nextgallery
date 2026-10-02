# Live-GPU-Pilot – 2026-10-02

**Erfolgreich mit dem echten API-Server `http://10.1.10.78:1234`, ausschließlich künstliche Testbilder. Nextcloud-App nicht aktiviert.**

Modell: `qwen/qwen3.8-27b`; LM Studio meldet `type=vlm`, `arch=qwen35`, `quantization=Q4_K_M`, `state=loaded`, geladener Kontext 44.219 Tokens. Keine Gewichtsdateien oder GPU-Hardwarewerte per SSH abgefragt. Der Modellname wird unverändert vom Server übernommen.

## Gemessene Inferenzzeiten

| Bild | Vision | OCR | Ergebnis |
|---|---:|---:|---|
| Generierte Rechnung | 5,580 s | 3,508 s | `dokument`; Rechnungsnummer 12345, Datum und 42,50 EUR korrekt |
| Generierte Chat-Ansicht | 5,877 s | 3,287 s | `screenshot`; beide Nachrichten und 17:30 Uhr korrekt |
| Generierte Fahrrad-/Strand-Grafik | 5,940 s | 3,424 s | `grafik`; Fahrrad, Strand/Wasser und Sonne erkannt; kein OCR-Text |
| Bytegleiche Kopie der Rechnung | Cache | Cache | Beide Ergebnisse wiederverwendet, keine weitere Inferenz |

Erster vollständiger Lauf: **27,914 s**, vier Dateien fertig, null Fehler, sechs Modellanfragen und zwei wiederverwendete Stufenergebnisse. Zweiter Lauf: **0,021 s**, null Modellanfragen, null Bildlesevorgänge. Originalprüfsummen vor/nach dem Lauf identisch.

Lokale Volltextsuche nach `RECHNUNG 12345` findet beide Rechnungsdateien; `Fahrrad` findet die Grafik und den Chat-Text. Die erste Rechnung enthält genau die fünf erwarteten Textzeilen; zusätzliche Leerzeilen der OCR-Antwort werden unverändert erhalten.

Bildbeschreibungen sind keine beweissicheren Fakten: Bei der Fahrrad-Grafik erfand das Modell zusätzlich einen dünnen schwarzen Außenrahmen. Die realen Objekte und Bildtypen wurden korrekt erkannt. Auch bei erfolgreichem Pilot bleiben menschliche Qualitätskontrolle und getrennte Speicherung von KI-Interpretationen notwendig. Es werden keine Dateisortierungen oder Löschungen ausgelöst.

## Behobener Live-Kompatibilitätsfehler

Beim ersten Versuch erzeugte LM Studio schema-gültiges Vision-JSON ausschließlich in `reasoning_content`; `content` blieb leer. Deshalb lehnten alle vier Dateien die ursprüngliche Pipeline korrekt als ungültige finale Antwort ab. Keine unvollständigen Suchtreffer wurden veröffentlicht.

Die explizite Option `vision.allow_reasoning_json=true` erlaubt jetzt ausschließlich vollständiges JSON, das das unveränderte Metadatenschema erfüllt und bei `finish_reason=stop` geliefert wird. OCR nutzt weiterhin ausschließlich den finalen Textkanal. Beliebige Reasoning-Prosa, Prosa mit eingebettetem JSON, Tool-Aufrufe und leere finale OCR-Antworten werden nicht übernommen. Vier zusätzliche Regressionstests prüfen diese Grenzen.

## Reproduktion und Aussagegrenzen

```bash
python3 scripts/live_pilot.py --output /tmp/media-ai-live-pilot-neu
```

Das erzeugt Testbilder, SQLite-State, Konfiguration und einen `report.json` im neuen Ausgabeordner. Live-Artefakte liegen außerhalb des Git-Repositories. Der getestete Ausgabeordner auf diesem Rechner ist `/home/paul/Desktop/Projects/tmp/media-ai-live-pilot-20261002-fixed`.

Keine produktiven Nextcloud-Bilder oder Zugangsdaten benötigt. Kein Test von Nextcloud-WebDAV am echten Server, MariaDB-Migration, Authentifizierungs-Middleware oder globaler Browser-/Android-Suche. Kein belastbarer Durchsatzwert für die gesamte Fotosammlung: drei kleine und klare Testbilder sind kein repräsentativer Datensatz. Vor dem Gesamtlauf folgt ein Pilot mit echten unterschiedlichen Fotos/Screenshots/Scans.
