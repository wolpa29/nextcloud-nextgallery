# Technische Referenz: Externer Embedding-Dienst

[English reference](EMBEDDING_SERVICE_REFERENCE.en.md) · [Hauptanleitung](EXTERNAL_EMBEDDING_SERVER.md)

Diese Referenz dokumentiert die technische Schnittstelle, den API-Vertrag, die Bereitstellung eigener Modelle sowie Befehle zur Betriebsführung und Fehlerbehebung des externen NextGallery-Embedding-Servers.

## Hinweise zur Installation

- Original: ca. 1,52 GB Modell-Download plus Laufzeit, je nach Eingaben etwa 4–6 GB RAM/VRAM. Lite: ca. 388 MB plus Laufzeit, etwa 2 GB RAM; nur CPU.
- Bei Auswahl einer GPU reserviert Docker deren Host-Nummer. Im Container hat die freigegebene GPU den CUDA-Index 0.
- Private Verbindungsdaten liegen in `~/.nextgallery-embedding-server`. Eine erneute Einrichtung übernimmt API-Key und Modellvolume. Den Schlüssel vertraulich behandeln.
- Nextcloud muss den gewählten Port erreichen. Zugriff auf benötigte Rechner begrenzen; für Verbindungen über Netzwerkgrenzen HTTPS verwenden.
- Die Adaptervorlage startet erst, wenn `load_backend()` ein echtes Modell lädt.

---

## 1. Eigene Modelle anbinden

NextGallery beschränkt externe Dienste nicht auf Gemma oder Qwen. Du kannst jedes Modell verwenden, sofern es Text- und Bildabfragen in denselben gemeinsamen Vektorraum einbettet und den unten definierten API-Vertrag erfüllt.

### Vorhandenen Modellserver nutzen

Falls du bereits einen eigenen Modellserver betreibst:
- Der Server muss die beiden Endpunkte `GET /v1/info` und `POST /v1/embed` bereitstellen.
- Eine reine Text-Embedding-API oder eine Chat-/Bildbeschreibungs-API genügt für die visuelle Suche nicht.
- Weicht die native API deines Servers ab, schalte einen schlanken Adapter davor.
- Starte den Installer und wähle **Eigenes Modell**, um API-Adresse, Modell-ID und Schlüssel deines Dienstes einzugeben:
  ```bash
  ./scripts/install-embedding-server.sh
  ```
  Dieser Test sendet synthetische Text- und Bildanfragen, installiert kein Gemma, überschreibt keine bestehende Konfiguration und speichert den Schlüssel nicht dauerhaft ab.

### Eigenes Modell per NextGallery-Adapter bereitstellen

Für eigene Modelle steht eine Vorlage unter [examples/custom_embedding_adapter.py](examples/custom_embedding_adapter.py) bereit.

1. Implementiere in deiner Adapterdatei die Funktion `load_backend()`.
2. Installiere die Modellabhängigkeiten (einschließlich Pillow) in einer separaten Python-Umgebung (`venv`).
3. `backend.identity` muss Modellgewichte, Revisionsstand, Laufzeitversionen, Bildvorverarbeitung, numerische Präzision und Aufgabenprompts reproduzierbar beschreiben. Ändert sich einer dieser Faktoren, muss sich die Identität ändern, da der Adaptercode in den Vektorraum-Fingerabdruck einfließt.
4. Starte den Dienst mit dem benutzerdefinierten Adapter:

```bash
# Vorab NEXTGALLERY_EMBEDDING_KEY als Umgebungsvariable setzen
/path/to/model-venv/bin/python worker/embedding_server.py \
  --family custom \
  --model 'anbieter/mein-bild-text-modell' \
  --model-path /pfad/zum/modell \
  --custom-adapter /pfad/adapter.py \
  --device cuda \
  --host 0.0.0.0 \
  --port 9379
```

Mit `--device cpu` wird die Berechnung auf der CPU ausgeführt, sofern der Adapter dies unterstützt. Der Adaptercode wird lokal beim Start geladen; NextGallery lädt keinen ausführbaren Code über Netzwerkanfragen nach.

---

## 2. API-Vertrag

Ist ein Schlüssel konfiguriert, erwarten alle Endpunkte den HTTP-Header:
```http
Authorization: Bearer <API-Key>
```
Weiterleitungen (HTTP Redirects) werden nicht unterstützt. Anfragen enthalten Roh-Bytes oder Suchtexte, niemals interne Nextcloud-Dateipfade.

### `GET /v1/info`

Liefert Metadaten zum Modell und zum Vektorraum:

```json
{
  "model": "anbieter/mein-bild-text-modell",
  "family": "meine_modellfamilie",
  "dimensions": 512,
  "space": "aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa",
  "modalities": ["text", "image"]
}
```

- **`dimensions`**: Unterstützt werden 128 bis 4096 Dimensionen.
- **`space`**: Stabiler 64-stelliger SHA-256-Fingerabdruck. Sobald Gewichte, Laufzeit, Präzision, Vorverarbeitung oder Prompts den Vektorraum verändern, muss dieser Fingerabdruck wechseln. Übereinstimmende Modellnamen oder Dimensionen allein reichen nicht aus.
- **`modalities`**: Muss zwingend `["text", "image"]` enthalten.

### `POST /v1/embed`

Nimmt genau eine Eingabe entgegen (entweder Text oder Bild):

**Suchtext:**
```json
{
  "model": "anbieter/mein-bild-text-modell",
  "text": "ein rotes Auto"
}
```

**Bild (Base64-kodiert):**
```json
{
  "model": "anbieter/mein-bild-text-modell",
  "image": "BASE64_KODIERTE_BILDBYTES"
}
```

**Antwort:**
```json
{
  "space": "aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa",
  "embedding": [0.0123, -0.0456, 0.0789, ...]
}
```

- Das Array `embedding` muss genau die deklarierte Anzahl endlicher Gleitkommazahlen enthalten und darf kein reiner Nullvektor sein.
- NextGallery normalisiert die Vektoren für die Kosinus-Ähnlichkeitssuche.
- Videos werden durch Extrahieren und Einbetten einzelner Standbilder verarbeitet.

---

## 3. Betrieb und Fehlerdiagnose

### CLI-Befehle

Alle Verwaltungsaufgaben können über `scripts/install-embedding-server.py` ausgeführt werden:

| Aktion | Befehl | Beschreibung |
| --- | --- | --- |
| Bereitschaft prüfen | `python3 scripts/install-embedding-server.py --action check` | Testet Bild- und Textanfragen mit synthetischen Daten |
| Verbindungsdaten anzeigen | `python3 scripts/install-embedding-server.py --action info` | Gibt URL, Port, Modell-ID und API-Key aus |
| Starten / GPU reparieren | `python3 scripts/install-embedding-server.py --action start` | Startet Container und repariert alte GPU-Zuordnungen |
| Stoppen | `python3 scripts/install-embedding-server.py --action stop` | Hält die Container-Instanz an |
| Deinstallieren (Modelle behalten) | `python3 scripts/install-embedding-server.py --action uninstall` | Entfernt Container; behält Modell-Downloads |
| Vollständig deinstallieren | `python3 scripts/install-embedding-server.py --action uninstall --delete-models` | Entfernt Container und löscht heruntergeladene Modelle |

Optional kann jedem Befehl `--language de` oder `--language en` mitgegeben werden.

<a id="troubleshooting"></a>
### Häufige Fehlerursachen

- **`invalid device ordinal`:** Tritt auf, wenn eine alte Container-Konfiguration eine Host-GPU-Nummer direkt im Container verwendet. Behebung: Repository mit `git pull --ff-only` aktualisieren und `python3 scripts/install-embedding-server.py --action start` ausführen. Dies repariert die GPU-Zuordnung automatisch, während API-Key, Profil und Modellvolume erhalten bleiben.
- **NVIDIA GPU im Docker-Container nicht verfügbar:** Das NVIDIA Container Toolkit fehlt möglicherweise oder ist nicht für Docker konfiguriert. Der Assistent bietet die Einrichtung auf unterstützten Ubuntu/Debian-Systemen an. Ist es bereits installiert, helfen folgende Befehle; der Docker-Neustart betrifft auch andere Container:
  ```bash
  sudo nvidia-ctk runtime configure --runtime=docker
  sudo systemctl restart docker
  ```
- **HTTP 401 / 403:** Der API-Schlüssel in den NextGallery-Einstellungen stimmt nicht mit dem Schlüssel in `~/.nextgallery-embedding-server/.env` überein.
- **Verbindung abgelehnt (Connection refused):** Der Container läuft nicht oder ist noch beim Initialisieren. Logs prüfen:
  ```bash
  docker compose --env-file ~/.nextgallery-embedding-server/.env \
    -f ~/.nextgallery-embedding-server/compose.json logs --tail=50
  ```
- **Zeitüberschreitung (Timeout):** Das Modell lädt noch herunter/wird in den Speicher geladen, oder die Firewall blockiert Port `9379`. Beachte, dass eine lokale Prüfung per `--action check` nicht die Netzwerkverbindung vom Nextcloud-Server garantiert. Verwende in NextGallery stets zusätzlich die Schaltfläche **Verbindung prüfen**.

---

## 4. Dasselbe Modell auf CPU und GPU

Ein übereinstimmender Modellname garantiert noch keine Identität der resultierenden Vektoren:
- Im Standard-Profil fließen die Dateien im Modellverzeichnis sowie die installierten Versionen von PyTorch, Transformers und Sentence Transformers in die Raum-ID (`space`) ein.
- Das Originalmodell verwendet auf CPU und NVIDIA CUDA FP32. FP16 ist für EmbeddingGemma 2 ungeeignet. Auf CUDA ist TF32 ausgeschaltet, damit die Berechnung ebenfalls mit FP32 erfolgt.
- Offizielle PyTorch-CPU- und CUDA-Pakete derselben Version erhalten dieselbe Raum-ID. Die bisherige FP32-CPU-Kennung bleibt erhalten; andere Paketversionen, Modellstände und Verarbeitungseinstellungen bleiben unterscheidbar. `/v1/info` nennt die tatsächlich installierten Bibliotheksversionen unter `runtime`.
- Ältere GPU-Dienste müssen mit dem aktualisierten Code neu gebaut und gestartet werden. Erst danach in NextGallery die Verbindung prüfen. Vorhandene CPU-Vektoren können bei bestätigter Übereinstimmung behalten werden.
- Eine Übereinstimmung der Raum-ID ist eine Schutzprüfung, keine Garantie für bitgenaue numerische Gleichheit.
- Bei abweichender Raum-ID niemals die Schutzprüfung umgehen oder voreilig Vektoren löschen. Prüfe zuerst Modellstand, Software-Revisionen und Vorverarbeitungseinstellungen. Ein nicht erreichbarer Server deutet nicht auf einen Modellwechsel hin.
