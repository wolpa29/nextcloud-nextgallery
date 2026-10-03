# Nextcloud: private OCR- und Bildsuche

**Stand 0.3.5: integrierte App und Python-Worker auf Nextcloud 32 installiert.**

Die App enthält den Worker und eine Oberfläche für Ordnerauswahl, Zeitplan,
Start/Pause, DE/EN, Ergebnisse und Wartung. Installation und Bedienung:
[Integrierte App](docs/APP_WORKER.md). Die folgenden CLI-Abschnitte beschreiben
zusätzlich den eigenständig ausführbaren Controller.

`max_tokens: 0` bedeutet **kein Ausgabe-Tokenlimit durch die Pipeline**;
`max_tokens` wird dann in der API-Anfrage weggelassen. Reasoning bleibt dem
Modell überlassen. Nur die finale Bildbeschreibung soll kurz ausfallen;
Die finale Beschreibung umfasst 1–2 Sätze, tags und objects zusammen höchstens
20 unterschiedliche Suchbegriffe. OCR transkribiert den sicher lesbaren Text vollständig.
Beide Stufen fordern strikt validiertes JSON an. Streaming erkennt Wiederholungen
früh und versucht bis zu drei Korrekturen ausschließlich für dasselbe Bild.
Die Parameter sind pro Stufe in der App konfigurierbar; Schleifen und Korrekturen
stehen im Aktivitätslog. Kontextfenster und etwaige serverseitige
Ausgabegrenzen bleiben Einstellungen des Modellservers.

```text
Bilder in Nextcloud (nur lesen)
       ↓ HTTPS/WebDAV oder lokales Dateiverzeichnis
Python-Controller auf einem beliebigen Rechner
       ↓ verkleinerte Bildkopie, HTTP/API im eigenen Netzwerk
GPU-PC 10.1.10.78: Vision-Modell + OCR-Modell
       ↓ Beschreibung, Objekte, Suchbegriffe, erkannter Text
SQLite auf dem Controller: Queue, Ergebnisse, Wiederaufnahme
       ↓ authentifizierte API der kleinen Nextcloud-App
Nextcloud/MariaDB: eigene Tabelle + Volltextindex
       ↓
Globale Nextcloud-Suche: „Bildinhalt und OCR“
```

## Was wird wo gespeichert?

| Ort | Inhalt |
|---|---|
| Bestehende Nextcloud-Dateien | Originale bleiben unverändert; keine Umbenennung, Verschiebung, Löschung oder EXIF-Bearbeitung. |
| Controller: `state/media.sqlite3` | Dateiliste, Datei-Version/ETag, SHA-256, Verarbeitungsstatus, Fehler, OCR, Bildbeschreibung, Suchbegriffe, Modellversionen und separat gelesene EXIF-Fakten. SQLite-WAL/SHM gehören zum laufenden Zustand. |
| GPU-PC | Installierte Modelle; Bildkopien werden für die Anfrage verarbeitet. Der Controller legt dort kein Bildarchiv an. Etwaige API-/LM-Studio-Protokollierung wird beim Einrichten geprüft. |
| Nextcloud-Datenbank | Die App legt `oc_media_ai_meta` an; `oc_` hängt vom vorhandenen Datenbankpräfix ab. Pro Benutzer und Nextcloud-Datei-ID ein Metadatensatz, plus MariaDB-Volltextindex für OCR, Beschreibung, Tags und Objekte. |
| `export` | Optionaler JSONL-Export mit allen fertigen Ergebnissen. Keine zusätzlichen Begleitdateien im Fotoordner. |

Der Controller braucht **keinen direkten Zugang zur MariaDB**. Er meldet sich mit Pauls Nextcloud-App-Passwort an und sendet Ergebnisse an die eigene App. Die App prüft Benutzerzugriff, Datei-ID und aktuellen ETag und schreibt ausschließlich ihre eigene Tabelle. Suchtreffer werden ebenfalls auf Zugriff und aktuelle Dateiversion geprüft. Suchanfragen laufen auf Nextcloud, auch wenn der GPU-PC aus ist.

## Bestehende Dateien und andere Apps

- Der erste erfolgreiche Lauf inventarisiert rekursiv **alle Bildordner** unter der konfigurierten Quelle. Standard: Pauls vollständiger WebDAV-Dateibereich, also auch `InstantUpload`, `GoPro_Aufnahmen` und andere Ordner, soweit dort Bilder liegen.
- Die integrierte App verarbeitet alle offenen Bilder der gewählten Ordner, ohne Datei-/Gesamtzeitlimit. Pro Bild gilt standardmäßig ein gemeinsames Zeitbudget von 600 Sekunden (0 = unbegrenzt). Pause/Fortsetzen erhält den Fortschritt. Die portable CLI unterstützt weiterhin ausdrücklich gesetzte Pilotgrenzen.
- Unveränderte Dateien brauchen weder erneuten Download noch Modellanfragen. Änderungen an ETag, Modellversion oder Prompt lösen eine neue Verarbeitung aus.
- Bytegleiche Duplikate erhalten dieselben zwischengespeicherten Analyseergebnisse. Sie werden **nicht** gelöscht oder zusammengeführt. Ein SHA-256 erkennt keine nur ähnlich aussehenden/rekomprimierten Duplikate.
- WebDAV-Datei-ID und unveränderter ETag erlauben Umbenennungen ohne erneute Analyse. Der Suchtreffer verwendet immer den aktuellen Nextcloud-Pfad.
- Ein Fehler in OCR wiederholt beim nächsten Versuch nur die fehlgeschlagene Stufe; erfolgreich gespeicherte Stufen bleiben erhalten. Dateien mit Fehlern werden nach einer Stunde erneut versucht. Ein erneuter Modell-/Dateistand hebt die Wartezeit auf.
- Ist der GPU-PC aus, wird die Analyse zurückgestellt. Fertige Ergebnisse und Suchindex bleiben erhalten. Ein Timer schaltet den PC **nicht** selbst ein.
- Bereits berechnete Ergebnisse können mit `publish` ohne GPU an Nextcloud nachgereicht werden. Die neue App muss dafür erreichbar und aktiviert sein.
- Original-EXIF, bestehende Nextcloud-Tags, Recognize-Daten und Memories-Daten werden nicht verändert. Unsere KI-Tags sind eigene Suchbegriffe; sie werden nicht automatisch zu System-Tags oder Memories-Metadaten.
- EXIF-Fakten und KI-Interpretationen werden getrennt gespeichert. OCR/Vision können Fehler machen; es gibt deshalb keine automatischen Dateiverschiebungen oder andere Aktionen anhand ihrer Ergebnisse.

## Umfang und Grenzen dieses Grundgerüsts

**Bilder**: JPEG/PNG/WebP/TIFF/BMP/GIF/HEIF/HEIC/AVIF, soweit die installierten Bilddecoder sie öffnen können. HEIF/HEIC wird über `pillow-heif` ergänzt. Animationen: erstes Bild. Für das aktuelle LM-Studio-Setup werden Vision und OCR vor dem Versand auf höchstens 2048 Pixel an der längsten Kante verkleinert (quadratisch höchstens 4,194 MP; 4:3 höchstens 3,146 MP). EXIF-Drehung vor der Verkleinerung, vollständiger Bildrahmen, keine Vergrößerung. Gesendete Auflösung/Pixelzahl, Format, Bytezahl und SHA256 stehen in der Analyseprovenienz; die Auflösung steht auch im Aktivitätslog. Separater Speicherschutz für Originale: 64 MiB und 80 Megapixel, größere/defekte Dateien werden als Fehler erfasst. Keine Videos, PDF-Dateien, Office-Dokumente, Gesichtsnamen, Ähnlichkeitssuche oder Vektor-/Embedding-Suche in Version 0.3.

Die Nextcloud-App ist für **Nextcloud 32 mit MariaDB/MySQL** vorbereitet. Ihre globale Suche kombiniert Wörter in OCR, Beschreibungen und Suchbegriffen. MariaDB-Stopwörter, Tokenisierung und Mindestwortlänge gelten; der Provider nimmt Wörter ab drei Zeichen auf. Es handelt sich noch nicht um semantische Freitextsuche. Die Android-App muss den eigenen Suchanbieter unterstützen; die Einbindung wird zunächst in der Browser-Suche geprüft. Die normale Dateinamen-Suche wird nicht automatisch erweitert.

## Eigenständigen Controller vorbereiten

Python **3.10 oder neuer**, SQLite mit FTS5, Internet für die einmalige Installation der Bibliotheken. Die privaten Bilder gehen danach nur an deine konfigurierten Server.

```bash
cd nextcloud-media-ai-pipeline
python3 -m venv .venv
.venv/bin/pip install -r requirements.txt
cp config.example.json config.json
mkdir -p state
```

`config.json` anpassen:

1. WebDAV-Adresse und Benutzer prüfen. Die beiliegende öffentliche `nextcloud-local-ca.crt` ist, falls vorhanden, nur die Zertifikats-Vertrauensdatei. Sie muss zur live verwendeten Nextcloud-Zertifikatskette passen; TLS-Prüfung wird nicht abgeschaltet.
2. In LM Studio auf `10.1.10.78` einen **bildfähigen** Modell-Build laden und den API-Server bereitstellen. Die Vorlage verwendet jetzt die live getestete ID `qwen/qwen3.8-27b`, Q4_K_M. Bei anderen oder aktualisierten Gewichten die ID und `model_revision` in **beiden** Modellabschnitten anpassen. Der Versionsbezeichner benennt den beobachteten Modellstand; ein exakter Gewichtsdatei-Hash wurde nicht vom Server ermittelt.
3. Zunächst können beide Stufen dasselbe Vision-Modell verwenden. Später kann die OCR-Stufe einen eigenen kompatiblen Server/Modell auf der anderen GPU bekommen. PaddleOCR-VL muss gegebenenfalls separat bereitgestellt werden; seine LM-Studio-Kompatibilität wird nicht vorausgesetzt.
4. `nextcloud.enabled` bleibt zunächst `false`. Damit funktioniert der Pilot ohne App-Installation: nur die lokale SQLite-Suche wird gefüllt.
5. Für systemd `pipeline.env.example` in eine private Datei übernehmen; für einen manuellen Lauf das App-Passwort direkt im Terminal eingeben:

```bash
read -rsp 'Nextcloud App-Passwort: ' NEXTCLOUD_APP_PASSWORD; echo
export NEXTCLOUD_APP_PASSWORD
read -rsp 'GPU-API-Key: ' MEDIA_AI_API_KEY; echo
export MEDIA_AI_API_KEY
.venv/bin/python media_pipeline.py run --config config.json --limit 100
.venv/bin/python media_pipeline.py status --config config.json
.venv/bin/python media_pipeline.py search 'Fahrrad Strand' --config config.json
```

Kein Passwort in der Kommandozeile, im Repository oder in `config.json` speichern. GPU-API über LAN/VPN bereitstellen; die Vorlage verwendet LAN-HTTP für LM Studio, Nextcloud verwendet HTTPS. API-Key und Netzwerkzugriff werden beim GPU-Setup eingerichtet.

**Direkt im Nextcloud-Container:** `source.kind` auf `directory`, `source.directory` auf `/var/www/nextcloud-data/paul/files` setzen; `nextcloud.path_prefix` bleibt leer. Bei einer Quelle nur unter `.../files/InstantUpload` dagegen `path_prefix` auf `/InstantUpload` setzen. Datenbank und Scriptverzeichnis müssen außerhalb des Original-Dateibaums liegen. Keine Symlinks verfolgen. Der ausführende Benutzer braucht lediglich Leserechte auf die Bilder und Schreibrechte auf sein separates State-Verzeichnis. Bei serverseitiger Verschlüsselung oder externen Speichern WebDAV verwenden, damit Nextcloud die lesbaren Inhalte liefert. Änderungen direkt am Datenträger müssen bereits im Nextcloud-Dateiindex bekannt sein.

## In die echte Nextcloud-Suche übernehmen (separater Einrichtungsschritt)

1. Datenbankbackup erstellen und Live-Version/PHP/MariaDB überprüfen.
2. `nextcloud-app/media_ai` in den App-Pfad deiner Nextcloud kopieren und mit `occ app:enable media_ai` aktivieren. Die eigene Migration erstellt die Tabelle und den Volltextindex. Das muss zuerst im tatsächlichen Nextcloud-32-Umfeld geprüft werden; die App ist ein lokaler Prototyp, keine veröffentlichte App-Store-App.
3. `nextcloud.enabled` auf `true` setzen. Benutzer, URL und `path_prefix` müssen zur Bildquelle passen. Beim nächsten `run` werden auch bisher lokal fertige Dateien erneut eingelesen, ihre Bildanalysen aber aus dem SHA-256-Cache wiederverwendet. Dabei wird vor der Verarbeitung die native Datei-ID/Version erfasst und anschließend über die App publiziert.
4. In der globalen Browser-Suche nach `Fahrrad`, `Strand` oder einem tatsächlich erkannten Text suchen. Den OCS-Provider kann man außerdem über `/ocs/v2.php/search/providers/media_ai/search?term=Fahrrad` prüfen.
5. Wird die App deaktiviert, fällt ausschließlich der eigene Suchanbieter weg; Dateien und vorhandene andere Suchanbieter bleiben erhalten. Zum Wiederherstellen der Analyse den SQLite-State sichern, nicht nur die Skripte. Datenbankbackups enthalten die eigenen App-Tabellen mit.

Ein späteres Memories-/Recognize-Plugin bekommt durch unsere Tabelle nicht automatisch zusätzliche Funktionen. Falls gewünscht, kann eine bewusste, getrennte Integration folgen. OCR-Volltext und Beschreibungen lassen sich direkt über unseren eigenen Suchanbieter nutzen.

## Täglicher Lauf

Vorlagen: `systemd/media-ai.service` und `systemd/media-ai.timer`. **Nicht aktiviert.** Pfade, Benutzer, Leserechte und private Umgebungsdatei zuerst anpassen. Der Timer läuft täglich um 03:00 in der Zeitzone seines Rechners; nachgeholte Ausführungen sind eingeschaltet. Die Vorlage verarbeitet maximal 1.000 Bilder oder vier Stunden pro Lauf, seriell und mit niedriger CPU/I/O-Priorität. Der Pilot misst die echte GPU-Laufzeit, bevor Paketgröße und Laufzeit für den Gesamtlauf gewählt werden.

```bash
.venv/bin/python media_pipeline.py publish --config config.json --limit 1000
.venv/bin/python media_pipeline.py export --config config.json > ergebnisse.jsonl
python3 -m unittest discover -s tests -v
```

Die optionale CLI-Laufzeitgrenze wird zwischen Dateien geprüft. In der integrierten App zählt stattdessen das gemeinsame Zeitbudget pro Bild; aktive Modellstreams werden beim Ablauf abgebrochen. SQLite-/State-Dateien lokal speichern, nicht gemeinsam auf einem Netzlaufwerk. Die Sperre verhindert überlappende Controller auf demselben State; mehrere unabhängige State-Verzeichnisse bilden keine koordinierte gemeinsame Queue.

## Modellplanung und Quellen

Qwen3.8-27B unterstützt Bildverarbeitung laut [offizieller Modellkarte](https://huggingface.co/Qwen/Qwen3.8-27B). Ein geeigneter quantisierter Vision-Build für die RTX 3090 ist ein **zu testender Kandidat**, keine bereits validierte Hardware-Konfiguration. 27B in FP16 passt nicht allein in 24 GB VRAM. Gewichte, Vision-Encoder und KV-Cache müssen zusammen passen; maximale Kontextlänge nicht unnötig hoch einstellen. Die tatsächliche VRAM-Größe deiner RTX 3060 wird vor der Verteilung geprüft. Reasoning wird nicht durch die Pipeline beschränkt oder abgeschaltet. Die finale OCR-Antwort muss ausschließlich Text und die Vision-Antwort das erwartete JSON liefern. Der Controller verwirft abgeschnittene oder ungültige Antworten.

Weitere Primärquellen: [LM Studio Chat Completions mit Bildern](https://lmstudio.ai/docs/developer/openai-compat/chat-completions), [PaddleOCR-VL-1.6](https://huggingface.co/PaddlePaddle/PaddleOCR-VL-1.6), [Nextcloud 32 Suchanbieter](https://docs.nextcloud.com/server/32/developer_manual/digging_deeper/search.html), [Nextcloud OCS-Authentifizierung](https://docs.nextcloud.com/server/32/developer_manual/client_apis/OCS/ocs-api-overview.html).

Die Vorlage aktiviert `vision.allow_reasoning_json` für einen im Live-Test beobachteten LM-Studio/Qwen-Kompatibilitätsfehler: strukturierte Antworten landen im `reasoning_content`-Feld, während `content` leer ist. Der Controller akzeptiert daraus ausschließlich ein vollständiges, schema-gültiges JSON-Objekt bei normalem Abschluss. Beliebige Gedanken/Prosa, abgeschnittene Antworten und OCR aus diesem Feld werden verworfen. Der Fehler ist auch im [LM-Studio-Bugtracker dokumentiert](https://github.com/lmstudio-ai/lmstudio-bug-tracker/issues/1773). Eine globale Server-/Modelleinstellung wurde für den Test nicht verändert.

## Live-Pilot wiederholen

Der Pilot erzeugt ausschließlich künstliche Testbilder (Rechnung, Chat-Ansicht, Fahrrad-Grafik plus Duplikat), spricht den echten GPU-Endpunkt an und kontrolliert OCR, lokale Suche, Cache, zweiten Durchlauf und unveränderte Originalbytes. Sein Ausgabeordner muss neu sein und außerhalb des Repositories liegen. Unter Ubuntu wird die vorhandene DejaVuSans-Schrift genutzt. Der Controller selbst hängt nicht von dieser Test-Schrift ab.

```bash
.venv/bin/python scripts/live_pilot.py --output /tmp/media-ai-live-pilot-neu
```

Konkrete Prüfergebnisse: `VALIDATION.md` und `docs/LIVE_PILOT.md`. Der kleine Live-Pilot validiert API-Anbindung und Verarbeitung dieser Testbilder; die Qualitätsprüfung der 45 Juni-Bilder wird separat dokumentiert. HEIC/AVIF mit echten Dateien, Gesamtdurchsatz und Android-Suchanbieter-Unterstützung bleiben gesondert zu prüfen. Live-State, echte Konfiguration, Zertifikate und Schlüssel werden nicht ins Git-Repository aufgenommen.
