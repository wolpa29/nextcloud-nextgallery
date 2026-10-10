# Externer Embedding-Server

Der Dienst berechnet Bildvektoren und Suchtextvektoren auf einem anderen PC. Nextcloud speichert die Vektoren und kombiniert sie mit der Textsuche. Videos werden über ausgewählte Standbilder verarbeitet. LM Studio kann daneben die Beschreibungen liefern.

## Voraussetzungen

- Linux mit Docker und Compose, oder Windows/macOS mit [Docker Desktop](https://docs.docker.com/get-started/get-docker/).
- Python 3 auf dem PC, um das Installationsskript auszuführen.
- Internet für den einmaligen Modell- und Laufzeitdownload; anschließend bleibt das Modell gespeichert.
- **Gemma sparsam:** empfohlen 4 CPU-Kerne, 2 GB freier RAM, 2 GB freier Speicher, zusätzlich zum Docker-Image.
- **Gemma Original:** empfohlen 4 CPU-Kerne, 6 GB freier RAM, 5 GB freier Speicher, zusätzlich zum Docker-Image. Docker Desktop muss genügend RAM zugewiesen bekommen.
- Keine GPU nötig. Beide Varianten verwenden die CPU. Die Angaben sind Empfehlungen mit Reserve, keine garantierten Mindestwerte.

Die Docker-Lösung verwendet denselben Linux-Dienst auf allen Hostsystemen. Eine echte Windows-/macOS-Installation ist noch nicht separat nachgewiesen.

## Installation

Das private Repository einmal auf dem externen PC klonen. Bei GitHub anmelden, wenn der Zugriff danach fragt:

```sh
git clone https://github.com/wolpa29/nextcloud-nextgallery.git
cd nextcloud-nextgallery
python3 scripts/install-embedding-server.py
```

Unter Windows in PowerShell den letzten Befehl als `py -3 scripts/install-embedding-server.py` ausführen. Docker Desktop muss laufen und Linux-Container verwenden.

Der Standard installiert **Gemma sparsam**. Für das Originalmodell:

```sh
python3 scripts/install-embedding-server.py --profile full
```

Das Skript erstellt eine eigene Docker-Compose-Einrichtung und einen API-Schlüssel. Es startet den Dienst und den einmaligen Download. Die Modelle liegen in einem eigenen Docker-Volume; Nextcloud-Zugangsdaten werden nicht benötigt. Wiederholtes Ausführen verwendet denselben Schlüssel und vorhandene Downloads. Beide Gemma-Varianten können im Volume gespeichert bleiben.

Fortschritt auf Linux:

```sh
docker compose --env-file "$HOME/.nextgallery-embedding-server/.env" -f "$HOME/.nextgallery-embedding-server/compose.json" logs -f
```

Die Nextcloud-Verbindungsdaten stehen in **`.nextgallery-embedding-server/connection.json`** in deinem Benutzerordner. Die Datei enthält einen Schlüssel und bleibt privat. Öffne sie in einem Texteditor.

## In Nextcloud verbinden

Als Administrator **NextGallery → Einstellungen → Visuelle Bildsuche · Embeddings** öffnen:

1. **Externer Embedding-Server** auswählen.
2. Modellfamilie **EmbeddingGemma 2** wählen.
3. Modell-ID aus `connection.json` übernehmen: `embeddinggemma-2` für sparsam, `embeddinggemma-2-full` für Original.
4. Adresse **`http://PC-IP:9379/v1`** eintragen; `PC-IP` durch die LAN-Adresse des PCs ersetzen.
5. API-Schlüssel aus `connection.json` eintragen.
6. Speichern und **Verbindung prüfen** drücken. Erst „bereit“ bestätigt, dass der Download abgeschlossen ist und der Dienst antwortet.
7. Die visuelle Bildsuche aktivieren und speichern. Benutzer starten anschließend ihre eigenen Bildsuch-Läufe.

Der PC muss für die Verarbeitung **und für semantische Suchanfragen** erreichbar sein. Bei einem Ausfall bleibt die Textsuche verfügbar. Der Dienst startet nach einem PC-Neustart mit Docker wieder; unter Docker Desktop muss auch Docker Desktop gestartet sein. Bei Bedarf Port 9379 in der PC-Firewall für den Nextcloud-Host freigeben. Außerhalb des vertrauenswürdigen LANs HTTPS über einen Reverse Proxy verwenden.

## Stoppen, erneut starten, entfernen

```sh
python3 scripts/install-embedding-server.py --action stop
python3 scripts/install-embedding-server.py --action start
python3 scripts/install-embedding-server.py --action uninstall
```

`uninstall` entfernt den Dienst, bewahrt die Downloads im Docker-Volume. Downloads ebenfalls entfernen:

```sh
python3 scripts/install-embedding-server.py --action uninstall --delete-models
```

Diese Aktion betrifft nur das Volume dieses Embedding-Dienstes. Originaldateien, Nextcloud-Daten und andere Docker-Volumes bleiben erhalten. Die Verbindungsdateien im Benutzerordner können anschließend selbst gelöscht werden. Die Download-/Papierkorb-Buttons in Nextcloud verwalten ausschließlich Modelle auf dem Nextcloud-Host.

## Weitere Optionen

Mit `--port 9380`, `--threads 4` oder `--directory PFAD` lassen sich Port, CPU-Threads und das private Einrichtungsverzeichnis wählen. `--prepare-only` erstellt lediglich die Konfiguration und lädt kein Modell herunter.

Qwen3-VL-Embedding bleibt ein eigener Dienst mit der offiziellen Qwen-Laufzeit; dieses einfache Installationsskript richtet derzeit die beiden Gemma-Varianten ein. Reines Qwen3-Text-Embedding und LM Studios Text-Embedding-Endpunkt liefern keine Bildvektoren für diese Pipeline.
