# Zwei unabhängige Verarbeitungsschritte

**Beschreibung und Texterkennung** erzeugt Beschreibung, Tags, Objekte und OCR (bei Videos ohne OCR). **Visuelle Bildsuche** erzeugt ausschließlich Bildvektoren; Beschreibungen werden weder vorausgesetzt noch eingebettet. Beide Schalter betreffen neue Verarbeitung. Bereits gespeicherte Texte bleiben durchsuchbar.

Die Galerie vereinigt Texttreffer und alle nach Ähnlichkeit sortierten Bildtreffer innerhalb derselben Galerie, desselben Kontos, desselben Medientyps und aktueller Dateistände. Kein UND-Gate und keine feste Ähnlichkeitsschwelle. Die Rangfusion nutzt gleiche Gewichte und RRF k=60. Alle verfügbaren Kandidaten bleiben nachladbar; eine vollständige semantische Trefferabdeckung kann das Modell nicht garantieren. Bei einem Ausfall bleiben Textsuche und Dateinamen nutzbar, mit sichtbarer Meldung. Suchanfragen benutzen denselben aktiven Embedding-Dienst wie die Indexierung; die große Beschreibungspipeline läuft nicht bei der Suche. Offline bleibt die vorhandene lokale Dateisuche erhalten.

Fotos erhalten einen Vektor. Videos werden gleichmäßig anhand echter Frame-Zeitstempel abgetastet; jeder Frame erhält einen Vektor. Die am besten passende Szene bestimmt den Videorang, nicht der Durchschnitt. Stichproben können kurze Szenen verpassen; Audio ist nicht enthalten.

## Gemma auf der lokalen CPU

Unter **Embedding-Dienst** stehen **Gemma 2 · sparsam (CPU)** und **Gemma 2 · Original, mehr Bilddetails (CPU)**, **Externer Embedding-Server** und die erweiterte manuelle lokale Einrichtung zur Auswahl. Bei einem externen Server werden Modellfamilie, Modell-ID, API-Adresse und API-Key angezeigt. Es wird dort nichts installiert.

Ein fehlendes lokales Gemma wird **erst nach einem Klick auf „Gemma installieren“** heruntergeladen. Auswählen, Speichern, Aktivieren und Starten eines Medienlaufs starten allein keinen Modell-Download. Bei ungespeicherten Einstellungen heißt der Button **„Speichern und Gemma installieren“** und speichert die Auswahl vor der Installationsanforderung. Eine Installation kann auch bei ausgeschalteter visueller Bildsuche stattfinden. Der Button erscheint nur, solange die ausgewählte verwaltete Gemma-Installation fehlt; danach zeigt die Oberfläche „installiert“ bzw. „bereit“ und einen roten Papierkorb zum Deinstallieren.

Ein installierter, laufender NextGallery-Worker übernimmt die Einrichtung außerhalb des Web-Requests, auch wenn die Medienverarbeitung pausiert ist. Nur Nextcloud-Administratoren dürfen sie anfordern. Die Installationsanforderung ist vom Start-/Pause-Zustand der Medienpipeline getrennt und startet keine Medienanalyse. Ein Nextcloud-App-Download allein installiert keinen Systemdienst: Der gebündelte Worker muss einmalig mit `pipeline/scripts/install-worker.sh` installiert sein. Vorhandene Worker bei einem App-Upgrade neu starten; ohne Installationsunterstützung ist der Button deaktiviert und ein entsprechender Hinweis sichtbar.

Der Worker erstellt im privaten Zustandspfad `.nextgallery_ai/embedding-local/` eine eigene Python-Umgebung, installiert die gebündelten Anforderungen aus `worker/requirements-embeddings.txt` und lädt einmalig das Gemma-Modell (387.710.976 Bytes, ca. 388 MB) herunter. Die Python-Pakete benötigen zusätzlich Speicher und Internetzugang zu PyPI. Es werden keine Pakete in die System-Python-Umgebung installiert. Der festgelegte Modell-Download verwendet HTTPS; ein fertiges Modell wird erst nach Größen- und SHA-256-Prüfung atomar veröffentlicht.

In Übersicht, Verarbeitung und Einstellungen erscheinen Einrichtungsstatus, Downloadfortschritt und Fehler. Fehlgeschlagene oder abgebrochene Downloads werden nicht als fertige Modelle verwendet. Der Worker versucht fehlgeschlagene Einrichtungen nach fünf Minuten erneut; Beschreibung/OCR und Textsuche können währenddessen weiterlaufen. Ein bereits angeforderter Medienlauf bearbeitet die Embeddings nach abgeschlossener Einrichtung, sofern er nicht pausiert wurde. Ein Wechsel auf extern oder manuelle Einrichtung beendet die laufende Einrichtung beim nächsten Worker-Durchlauf und verwirft die offene Installationsanforderung. Das Ausschalten der visuellen Bildsuche lässt eine ausdrücklich angeforderte Installation fertig werden. Installierte Dateien bleiben für einen späteren Wechsel zurück erhalten.

Das Modellpaket kommt aus dem offiziellen LiteRT-Community-Repository:

- Repository: `litert-community/embeddinggemma-2-text-vision-440m-litert-lm`
- Festgelegter Stand: `e301f74d5551b0c2641bd5cb4652a76239d5c5f8`
- Datei: `embeddinggemma-2-text-vision-440m.litertlm`
- SHA-256: `92dcbea108899e5d6e30d919b0744f90d9967e80c67a4ab5503ac16d54f62eb0`
- Download: https://huggingface.co/litert-community/embeddinggemma-2-text-vision-440m-litert-lm/resolve/e301f74d5551b0c2641bd5cb4652a76239d5c5f8/embeddinggemma-2-text-vision-440m.litertlm

Bei aktivierter visueller Bildsuche startet der Worker nach der Einrichtung den Dienst auf `127.0.0.1:9379` und hält ihn auch bei pausierter Medienverarbeitung für Suchanfragen aktiv. Das Modell bleibt geladen und wird nicht je Bild neu geladen. Nach einem Neustart werden Modell und Python-Umgebung weiterverwendet. Setup-Details stehen in der privaten Datei `embedding-local/setup.log`; der Webstatus enthält keine Python-Ausgaben, Zugangsdaten oder Medieninhalte. Die Installation setzt Python mit venv/ensurepip-Unterstützung und passende Binärpakete für den Host voraus. Bei einem inkompatiblen Host wird ein sichtbarer Einrichtungsfehler gemeldet.

**Manuelle Einrichtung:** Unter **Embedding-Dienst** die Option **Lokales Modell · manuelle Einrichtung** auswählen, die Python-Anforderungen in der Worker-Umgebung installieren und einen absoluten Modellpfad angeben. Bereits gespeicherte abweichende Modellpfade behalten bei Upgrades die manuelle Auswahl. Qwen benötigt weiterhin die separate offizielle Laufzeit; die automatische Einrichtung gilt ausschließlich für Gemma. Externe Dienste werden von NextGallery nicht installiert.


### Varianten und Hardware

| Lokale Auswahl | Berechnung / Bildbudget | Download des Modells | Empfehlung zusätzlich zu Nextcloud |
| --- | --- | --- | --- |
| Sparsam | LiteRT, INT4/INT8, 140 Bild-Tokens | ca. 388 MB | 4 CPU-Kerne, 2 GB freier RAM, 2 GB freier Speicher |
| Original, mehr Bilddetails | Ursprüngliche BF16-Gewichte, CPU-Berechnung in FP32, 280 Bild-Tokens | ca. 1,52 GB | 4 CPU-Kerne, 6 GB freier RAM, 5 GB freier Speicher |

Beide verwalteten Varianten laufen auf der CPU; keine GPU erforderlich. Die Angaben sind Empfehlungen mit Reserve, keine gemessenen universellen Mindestgrenzen. Binärpakete für Python und den Host müssen verfügbar sein. Bei einem externen Dienst hängt die Hardware vom dort gewählten Modell ab; auf Nextcloud läuft dafür keine Embedding-Inferenz.

Die Originalvariante nutzt `google/embeddinggemma-2` am festgelegten Stand `914f7f89142e33e77833254d9c9b90c3cef7303b`. Dateiliste, Größen und SHA-256-Werte stehen in `worker/embedding-full-model.json`. Es werden ausschließlich Gewichte, Tokenizer- und Konfigurationsdaten geladen, kein ausführbarer Modellcode. `trust_remote_code=False` und `local_files_only=True` verhindern Code- und Modelldownloads bei der Inferenz. Das Audiomodul bleibt ausgeschaltet. Die Laufzeit aus `worker/requirements-embeddings-full.txt` nutzt CPU-Pakete von PyTorch und PyPI; der private Pfad ist `.nextgallery_ai/embedding-full/`.

Beide Installationen können nebeneinander gespeichert bleiben. Die gewählte Variante erzeugt Medien- und Suchtextvektoren; Vektorräume werden wegen Modell-, Präzisions- und Laufzeitunterschieden getrennt. Nach einem Wechsel muss ein Medienlauf die passenden Vektoren erstellen. Wenn bereits Vektoren vorliegen, erscheint beim Wechsel ein Hinweis und vor Start/Fortsetzen eine Bestätigung „Bildvektoren neu aufbauen?“. Auch ein geplanter Lauf wartet auf diese Bestätigung. Dimensionen werden angezeigt, sobald der neue Dienst bereit ist. Eine veränderte Modell-/Laufzeitidentität wird ebenfalls erkannt, auch bei gleicher Dimension. Die Zustimmung gilt nur für diesen Konfigurationsstand und Vektorraum. Alte Vektoren werden nicht vorab gelöscht und nie mit dem neuen Raum verglichen; die neue Berechnung ersetzt die Bildsuche schrittweise. Die Neuaufbau-Bestätigung löscht keine Beschreibungen oder OCR. Bis dahin bleiben die Texttreffer verfügbar. Eine bessere Trefferquote der Originalvariante gegenüber LiteRT wurde nicht nachgewiesen; das höhere Bildbudget und die fehlende Gewichtsquantisierung sind technische Unterschiede.

Am 10. Oktober wurde die Originalvariante mit synthetischen Bildern auf der Entwicklungs-VM (Intel i5-12400, 4 CPU-Threads) gemessen: ca. 3 GB maximaler Prozess-RAM, ca. 3,5 s pro Testbild und 65 ms für den ersten Suchtext. Das ist kein Performance-Nachweis für den produktiven Nextcloud-Host.

### Deinstallation

Bei einer installierten Variante erscheint direkt unter der Auswahl ein roter Papierkorb. Eine Bestätigung nennt die Folgen. Nur Administratoren dürfen die Deinstallation anfordern; der aktualisierte Worker muss online und die Medienverarbeitung pausiert sein. Er beendet den lokalen Dienst und entfernt ausschließlich die feste Modell-/Laufzeit-Unterstruktur der gewählten Variante. Eine andere installierte Variante, Originalmedien, Beschreibungen, OCR und gespeicherte Vektoren bleiben erhalten. Nach dem Entfernen ist die semantische Suche mit dieser Variante bis zur erneuten Installation nicht verfügbar; die Textsuche bleibt nutzbar. Es startet keine automatische Neuinstallation. Externe und manuell eingerichtete Modelle werden nicht gelöscht.

## Externer PC

Derselbe Dienst kann unabhängig von LM Studio gestartet werden. Python-Laufzeit und Gemma-Modellpaket müssen dort vorhanden sein. Gemma nutzt derzeit die CPU; Qwen wählt bei verfügbarem CUDA die GPU.

```sh
# NEXTGALLERY_EMBEDDING_KEY vorher als private Umgebungsvariable setzen.
python embedding_server.py --host 0.0.0.0 --port 9379 \
  --model-path /path/to/embeddinggemma-2-text-vision-440m.litertlm
```

In der Verwaltung **externer PC**, **multimodaler Embedding-Dienst**, `http://PC-IP:9379/v1` und denselben API-Key einstellen. Außerhalb eines vertrauenswürdigen lokalen Netzes TLS verwenden. Weiterleitung von HTTP-Anfragen wird nicht verfolgt. Den externen Dienst startet und beendet NextGallery nicht.

## Qwen3-VL-Embedding

Separat die offizielle Implementierung und deren Anforderungen aus https://github.com/QwenLM/Qwen3-VL-Embedding installieren. Keine automatische Installation von ausführbarem Quellcode im Web-Request. Es ist **Qwen3-VL-Embedding**, nicht das reine Qwen3-Embedding-Modell nötig.

```sh
python embedding_server.py --family qwen3_vl --model Qwen/Qwen3-VL-Embedding-2B \
  --model-path /path/to/Qwen3-VL-Embedding-2B \
  --model-revision PINNED_MODEL_REVISION --qwen-source /path/to/Qwen3-VL-Embedding
```

Entsprechende lokale Pfade oder externen Dienst konfigurieren. Eine Live-Inferenz mit Qwen wurde in dieser Änderung nicht nachgewiesen; Gemma wurde tatsächlich mit synthetischen Bildern, Frames und Suchtext getestet.

## Modellwechsel und Speicherung

Vektoren liegen als normalisierte Float32-Werte in einer eigenen Nextcloud-Tabelle. Konto, Datei-ETag, Modell-/Runtime-Fingerabdruck, Auflösung, Frame-Anzahl und Video-Frame werden berücksichtigt. Verschiedene Vektorräume werden nie vermischt. Ein Wechsel von CPU auf einen externen Dienst kann nur exakt kompatible Vektoren weiterverwenden. Alte Beschreibungen bleiben unverändert. Ein ausdrücklicher Gesamtrücksatz löscht auch Embeddings, nie Originaldateien. Nach einem Publikationsausfall werden berechnete Vektoren weiterverwendet.

Die aktuelle Suche vergleicht exakt alle berechtigten Vektoren auf dem Nextcloud-Host. Es gibt noch keinen ANN-Index; CPU- und DB-Aufwand wachsen mit dem Bestand und der Zahl der Video-Frames. Die Embedding-Laufzeit ist vom eigentlichen Vektorvergleich getrennt.

## Nachweis für LM Studio, 10. Oktober 2026

Am tatsächlich laufenden Server `10.1.10.78:1234` war `text-embedding-embeddinggemma-2` (Unsloth GGUF Q8_0, Kontext 8192) geladen. Text lieferte 768 Werte. Bild- und Video-Objekte am `/v1/embeddings`-Endpunkt wurden mit HTTP 400 zurückgewiesen. Zusätzliche `images`-Felder wurden ignoriert: roter Kreis und blaues Quadrat lieferten identische Vektoren. Diese API ist daher für diese Bild-Pipeline ungeeignet. Das sagt nichts über die grundsätzlichen multimodalen Fähigkeiten der Modellgewichte aus.
