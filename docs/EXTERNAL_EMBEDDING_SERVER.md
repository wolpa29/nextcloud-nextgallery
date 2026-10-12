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
1. Einstellungen eintragen und unten auf **„Speichern“** klicken.
2. Auf **„Verbindung prüfen“** klicken (erst nach abgeschlossenem Download meldet der Server *„Bereit“*).
3. Haken bei **„Für Benutzer aktivieren“** setzen und erneut speichern.

---

## Nützliche Befehle

| Aktion | Befehl |
| :--- | :--- |
| **Download-Fortschritt & Logs** | `docker compose --env-file ~/.nextgallery-embedding-server/.env -f ~/.nextgallery-embedding-server/compose.json logs -f` |
| **Verbindungsdaten erneut anzeigen** | `python3 scripts/install-embedding-server.py --action info` |
| **Dienst stoppen** | `python3 scripts/install-embedding-server.py --action stop` |
| **Dienst starten** | `python3 scripts/install-embedding-server.py --action start` |
| **Deinstallieren** | `python3 scripts/install-embedding-server.py --action uninstall` *(mit `--delete-models` auch Downloads löschen)* |

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
- Für GPU-Beschleunigung: NVIDIA-Treiber und [NVIDIA Container Toolkit](https://docs.nvidia.com/datacenter/cloud-native/container-toolkit/install-guide.html).
- Port 9379 im lokalen Netzwerk für den Nextcloud-Host freigegeben.
