# Externen Embedding-Dienst einrichten

[English guide](EXTERNAL_EMBEDDING_SERVER.en.md) · [Referenz](EMBEDDING_SERVICE_REFERENCE.md)

Der externe Dienst berechnet Bild- und Suchtextvektoren auf einem separaten Rechner. Nextcloud speichert die Vektoren und führt die visuelle Suche aus.

Für die automatische Gemma-Installation: Python 3.10+, Docker mit Compose, freier Speicherplatz für Container und Modell. Für GPU-Nutzung: NVIDIA-Treiber und NVIDIA Container Toolkit.

## 1. Dienst installieren

Repository klonen und das Installationsskript ausführen (Englisch ist Standard per Enter, Deutsch Auswahl `[2]`):

```bash
git clone https://github.com/wolpa29/nextcloud-nextgallery.git
cd nextcloud-nextgallery
./scripts/install-embedding-server.sh
```

Unter Windows den Assistenten direkt mit Python starten:

```powershell
py -3 scripts/install-embedding-server.py
```

Der Assistent fragt nach Modell (Original, Lite oder Eigenes Modell), Hardware (CPU/GPU) und Port (Standard: `9379`). Konfiguration und API-Schlüssel werden geschützt in `~/.nextgallery-embedding-server` gespeichert.

## 2. Bereitschaft prüfen

Sobald der Container läuft und das Modell geladen ist, die Funktionsfähigkeit mit synthetischen Testdaten prüfen:

```bash
python3 scripts/install-embedding-server.py --action check
```

Wenn das Modell noch lädt: [Fortschritt anzeigen](EMBEDDING_SERVICE_REFERENCE.md#troubleshooting) und später erneut prüfen.

## 3. In NextGallery verbinden

Öffne **NextGallery → Einstellungen → Visuelle Bildsuche · Embeddings**:

| Einstellung | Wert |
| --- | --- |
| Embedding-Dienst | Externer Embedding-Server |
| Modellfamilie | EmbeddingGemma 2, Qwen3-VL-Embedding oder Eigenes Modell |
| Modell-ID | Vom Dienst gemeldete ID (z. B. `embeddinggemma-2-full` oder `embeddinggemma-2`) |
| API-Adresse | `http://SERVER-IP:9379/v1` (oder HTTPS-Adresse) |
| API-Key | Im Installer generierter Schlüssel |

1. Vor dem Speichern auf **Verbindung prüfen** klicken.
2. Laufende Embedding-Aufgaben unter **Benutzerläufe** pausieren und die aktuelle Datei fertigstellen lassen.
3. **Für Benutzer aktivieren** und speichern. Passende Vektoren bleiben erhalten; ein anderer Vektorraum erfordert einen bestätigten Neuaufbau.

<a id="custom-models"></a>
## Eigene Modelle

Wähle im Assistenten **Eigenes Modell** und in NextGallery **Modellfamilie → Eigenes Modell**. Installiere dein gewähltes Modell mit seiner eigenen Laufzeit. Der Dienst muss Bild- und Textanfragen in denselben Vektorraum einbetten.

Adapter, API-Details und Fehlerhilfe findest du in der [technischen Referenz](EMBEDDING_SERVICE_REFERENCE.md).

## Batch-Verarbeitung und verlustfreie Bilder

Unter **Externer Embedding-Server** bestimmt **Bilder/Frames pro Batch** (1–64), wie viele unabhängige Bilder gemeinsam gerechnet werden. Beispielsweise `10` sendet zehn Fotos oder Video-Frames pro Batch; jedes Bild erhält seinen eigenen Vektor. Der Standard `1` behält Einzelanfragen bei. Gemma Original und Qwen unterstützen echtes Modell-Batching; ältere Dienste und Gemma Lite werden weiter einzeln angesprochen. Ein unvollständiger letzter Batch wird ebenfalls verarbeitet. Große PNG-Anfragen werden bei Bedarf unter dem bestehenden 24-MiB-HTTP-Limit aufgeteilt; bei GPU-Speichermangel verkleinert der Server den Rechenbatch ohne Qualitätsänderung.

Fotos und Video-Frames werden nach dem Verkleinern verlustfrei als PNG übertragen. **Max. Bildkante (Pixel)** bleibt wirksam; Originaldateien bleiben unverändert. PNG stellt keine bereits im Original-JPEG verlorenen Details wieder her. Die geänderte Bildvorbereitung verwendet einen neuen Vektorraum (`image-v2-png`); vorhandene Vektoren werden nicht stillschweigend gelöscht. Vor dem nächsten Embedding-Lauf kann NextGallery die bestehende Bestätigung für einen Neuaufbau verlangen. Änderungen der Batch-Größe allein erfordern keinen Neuaufbau.

Für eine vorhandene Installation nach dem Aktualisieren des Repositorys:

```bash
git pull --ff-only
python3 scripts/install-embedding-server.py --action update
python3 scripts/install-embedding-server.py --action check
```

`update` baut den Dienst neu und ersetzt seinen Container. Gespeicherte Modell-/GPU-Auswahl, Port, API-Key und Modellvolume bleiben erhalten. Ein einfaches `start` baut den Container nicht neu. Unter Windows entsprechend `py -3` statt `python3` verwenden.
