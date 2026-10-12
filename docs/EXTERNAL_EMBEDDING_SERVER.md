# Externer Embedding-Server (Docker)

Dieser Dienst berechnet Bild- und Suchtextvektoren auf einem externen Rechner (mit CPU oder NVIDIA-GPU) und entlastet den Nextcloud-Host.

---

## Schnellstart

### 1. Repository klonen und Assistenten starten

Auf dem Rechner ausführen, auf dem der Embedding-Server laufen soll:

```sh
git clone https://github.com/wolpa29/nextcloud-nextgallery.git
cd nextcloud-nextgallery
./scripts/install-embedding-server.sh
```

*(Unter Windows: `py -3 scripts/install-embedding-server.py`)*

---

### 2. Installationsschritte im Assistenten

Der Assistent führt dich interaktiv durch 3 Schritte:

1. **Modell wählen:**
   - **EmbeddingGemma 2 · Original (Full) [Empfohlen]:** Höchste Genauigkeit. Läuft auf CPU oder GPU (NVIDIA CUDA). ~1,5 GB Download.
   - **EmbeddingGemma 2 · sparsam (Lite):** Geringer Speicherbedarf (~388 MB Download), reine CPU-Berechnung.
2. **Hardware wählen (CPU oder GPU):**
   - Erkennt automatisch vorhandene Grafikkarten (`nvidia-smi`).
   - Bei mehreren Grafikkarten kannst du eine gezielte GPU (z. B. GPU 0, GPU 1), alle GPUs oder reine CPU-Berechnung wählen.
3. **Port & Start:**
   - Standard-Port: `9379` (einfach mit Enter bestätigen).
   - Docker startet den Container im Hintergrund und lädt das Modell herunter.

---

### 3. In Nextcloud eintragen

Am Ende zeigt das Skript deine individuellen Verbindungsdaten an. Öffne in Nextcloud als Administrator:
👉 **NextGallery → Einstellungen → Visuelle Bildsuche · Embeddings**

| Einstellung in Nextcloud | Einzutragender Wert |
| :--- | :--- |
| **Embedding-Dienst** | `Externer Embedding-Server` |
| **Modellfamilie** | `EmbeddingGemma 2` |
| **Modell-ID** | `embeddinggemma-2-full` *(bzw. `embeddinggemma-2`)* |
| **API-Adresse des externen Servers** | `http://<DEINE-PC-IP>:9379/v1` *(vom Skript ermittelte LAN-IP)* |
| **API-Key** | `<Dein generierter 64-Zeichen-Schlüssel>` |

**Aktivierung abschließen:**
1. Einstellungen und API-Key eintragen und **„Verbindung prüfen“** wählen. Die Prüfung verwendet die noch ungespeicherten Eingaben; sie verändert keine Einstellungen oder Vektoren. Erst nach abgeschlossenem Download meldet der Server *„Bereit“*.
2. Bei einem Wechsel mit vorhandenen Vektoren auch die gemeldete Kompatibilität prüfen. Ein Verbindungsfehler bedeutet, dass der Vektorraum noch nicht geprüft werden konnte. Er beweist keinen Modellwechsel und verlangt kein Löschen.
3. Die betroffenen Embedding-Läufe unter **„Benutzerläufe“** pausieren und den Abschluss der aktuellen Datei abwarten. Die Speichern-Hinweise zeigen, welche Verarbeitung noch läuft. Beschreibungen und OCR können bei einem reinen Embedding-Wechsel weiterlaufen.
4. **„Für Benutzer aktivieren“** setzen und **„Speichern“** wählen. Bei nachgewiesen gleichem Vektorraum bleiben die Vektoren automatisch erhalten. Ein Zurücksetzen benötigt weiterhin eine ausdrückliche Bestätigung.

### Dasselbe Modell auf CPU und GPU

Der Modellname allein reicht für eine kompatible Bildsuche nicht aus. Die Vektorraum-ID berücksichtigt beim Originalmodell den Inhalt des Modellverzeichnisses und die Versionen von PyTorch, Transformers und Sentence Transformers. NextGallery ergänzt Bildauflösung und Video-Standbildanzahl. Unterschiede darin können bei gleichem Modellnamen eine andere ID ergeben.

Die bisherige Full-Laufzeit nutzt FP32 auf CPU und FP16 auf CUDA; diese Präzisionswahl wird nicht separat in ihrer Vektorraum-ID abgebildet. Eine erfolgreiche ID-Prüfung ist deshalb keine Messung der numerischen Gleichheit von CPU- und GPU-Ergebnissen. Die neue Oberfläche setzt diese Schutzprüfung nicht außer Kraft. Bei abweichender ID zuerst Modellstand, Laufzeit und Verarbeitungseinstellungen abgleichen; vorhandene Vektoren nicht aufgrund eines Verbindungsfehlers löschen.

---

## Nützliche Befehle

| Aktion | Befehl |
| :--- | :--- |
| **Download-Fortschritt & Logs** | `docker compose --env-file ~/.nextgallery-embedding-server/.env -f ~/.nextgallery-embedding-server/compose.json logs -f` |
| **Verbindungsdaten erneut anzeigen** | `python3 scripts/install-embedding-server.py --action info` |
| **Dienst stoppen** | `python3 scripts/install-embedding-server.py --action stop` |
| **Dienst starten** | `python3 scripts/install-embedding-server.py --action start` |
| **Deinstallieren** | `python3 scripts/install-embedding-server.py --action uninstall` *(mit `--delete-models` auch Downloads löschen)* |

### Startfehler „invalid device ordinal“ bei GPU 1 oder höher

Ältere Installer-Versionen haben die GPU-Nummer des Rechners als CUDA-Nummer im Container übernommen. Wenn Docker nur die ausgewählte Karte durchreicht, ist diese im Container immer CUDA-Gerät 0. Beispielsweise bleibt die physische GPU 1 ausgewählt, während PyTorch `cuda` (Gerät 0) verwendet.

Nach Aktualisierung des Repositorys repariert der Startbefehl diese gespeicherte Zuordnung automatisch:

```sh
git pull --ff-only
python3 scripts/install-embedding-server.py --action start
```

API-Key, Modellprofil, ausgewählte Grafikkarte und Modell-Volume bleiben erhalten. Die Modelle müssen nicht erneut heruntergeladen werden. Anschließend die Logs prüfen: Der Container muss weiterlaufen und das Modell als bereit melden; ein erfolgreiches `docker compose up` allein bestätigt die Bereitschaft noch nicht.

---

## Automatisierte Optionen (ohne Assistent)

Für automatisierte Setups kann der Assistent über Parameter übersprungen werden:

```sh
# Beispiel: Original-Modell mit GPU 0 auf Port 9379
python3 scripts/install-embedding-server.py --profile full --device cuda --gpu-id 0

# Beispiel: Sparsames Modell auf CPU mit 4 Threads
python3 scripts/install-embedding-server.py --profile lite --device cpu --threads 4
```

Voraussetzungen:
- Docker & Docker Compose installiert und aktiv.
- **Für GPU-Beschleunigung:** NVIDIA-Treiber und registriertes `nvidia-container-toolkit`.
  *Falls der Fehler `could not select device driver "nvidia"` auftritt:*
  ```sh
  # Toolkit in Docker registrieren und Docker neu starten:
  sudo nvidia-ctk runtime configure --runtime=docker
  sudo systemctl restart docker
  # (Falls das Paket noch fehlt: sudo apt install -y nvidia-container-toolkit)
  ```
- Port 9379 im lokalen Netzwerk für den Nextcloud-Host freigegeben.
