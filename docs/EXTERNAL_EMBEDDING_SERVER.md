# Externen Embedding-Dienst einrichten

[English guide](EXTERNAL_EMBEDDING_SERVER.en.md)

Der externe Dienst berechnet Bild- und Suchtextvektoren. Nextcloud speichert die veröffentlichten Vektoren und führt die Suche durch. Beschreibungen und OCR bleiben eigene Verarbeitungen.

Du kannst **Gemma automatisch installieren** oder **ein eigenes Modell verwenden**. Eigene Modelle werden nicht auf Gemma oder Qwen beschränkt. Entscheidend ist, dass Bild und Text in denselben Vektorraum eingebettet werden und der Dienst die unten beschriebene API anbietet.

## 1. Gemma auf dem externen Rechner installieren

Voraussetzungen: Python 3.10 oder neuer, Docker mit Compose, genügend Speicherplatz für Image, Modell und Laufzeit. Für GPU-Berechnung zusätzlich NVIDIA-Treiber und NVIDIA Container Toolkit. Nextcloud muss den gewählten Port erreichen können.

```bash
git clone https://github.com/wolpa29/nextcloud-nextgallery.git
cd nextcloud-nextgallery
./scripts/install-embedding-server.sh --language de
```

Ohne Sprachparameter fragt der interaktive Assistent zuerst nach **Deutsch oder Englisch**. Unter Windows denselben Python-Assistenten verwenden:

```powershell
py -3 scripts/install-embedding-server.py --interactive --language de
```

Der Assistent fragt nach Modell, Hardware und Port:

- **Original (Full):** CPU oder NVIDIA-GPU, ca. 1,5 GB Modell-Download plus Laufzeit. Die benötigte RAM-/VRAM-Menge hängt von Bildern und Laufzeit ab.
- **Sparsam (Lite):** CPU, ca. 388 MB Modell-Download.
- Bei mehreren GPUs kannst du eine bestimmte Karte wählen. Die Nummer bezeichnet die Karte auf dem Rechner; im Container erhält eine einzeln durchgereichte Karte CUDA-Nummer 0.
- Der Standard-Port ist **9379**. Nur den Zugriff der benötigten Rechner erlauben. Für entfernte/netzübergreifende Verbindungen HTTPS über einen geeigneten Reverse Proxy verwenden.

Das Skript legt private Verbindungsdaten unter `~/.nextgallery-embedding-server` an und verwendet bei erneuter Einrichtung denselben API-Key und dasselbe Modell-Volume. Der API-Key ist vertraulich; keine vollständige Verbindungszusammenfassung öffentlich teilen.

## 2. Bereitschaft prüfen

Ein gestarteter Container kann noch Modelle herunterladen oder laden. Erst die Prüfung bestätigt, dass Bild- und Textanfragen funktionieren:

```bash
python3 scripts/install-embedding-server.py --language de --action check
```

Die Prüfung verwendet ausschließlich einen synthetischen Suchtext und ein kleines synthetisches Bild. Sie verändert keine Nextcloud-Einstellungen oder gespeicherten Vektoren. Sie bestätigt die API-Funktion, keine Trefferqualität und keine Kompatibilität mit bereits gespeicherten Vektoren.

Falls der Dienst noch nicht antwortet, den Download-/Ladefortschritt ansehen und die Prüfung nach Abschluss wiederholen:

```bash
docker compose --env-file ~/.nextgallery-embedding-server/.env \
  -f ~/.nextgallery-embedding-server/compose.json logs --tail=50
```

## 3. In NextGallery verbinden

In **NextGallery → Einstellungen → Visuelle Bildsuche · Embeddings**:

| Einstellung | Wert |
| --- | --- |
| Embedding-Dienst | Externer Embedding-Server |
| Modellfamilie | EmbeddingGemma 2, Qwen3-VL-Embedding oder Eigenes Modell |
| Modell-ID | Exakte Modell-ID des Dienstes; bei Gemma Full `embeddinggemma-2-full`, bei Lite `embeddinggemma-2` |
| API-Adresse | `http://SERVER-IP:9379/v1` oder die direkte HTTPS-Adresse |
| API-Key | Schlüssel des Dienstes |

1. Noch vor dem Speichern **Verbindung prüfen**. Diese Prüfung verwendet die aktuellen Eingaben und prüft auch den Vektorraum vorhandener Vektoren.
2. Zum Speichern die betroffenen Embedding-Läufe unter **Benutzerläufe** pausieren und die aktuelle Datei fertigstellen lassen. Die Oberfläche zeigt den Grund einer Speichersperre. Beschreibungen und OCR können bei einem reinen Embedding-Wechsel weiterlaufen.
3. **Für Benutzer aktivieren** und speichern. Nachgewiesen kompatible Vektoren bleiben erhalten. Bei einem anderen Vektorraum ist ein ausdrücklich bestätigter Neuaufbau nötig.

Ein nicht erreichbarer Dienst beweist keinen Modellwechsel. Wegen eines Verbindungsfehlers keine Vektoren löschen.

<a id="custom-models"></a>
## Eigene Modelle

Wähle im Assistenten **Eigenes Modell / vorhandenen Dienst prüfen** oder in NextGallery **Modellfamilie → Eigenes Modell**. Die Modell-ID ist frei wählbar und muss genau der vom Dienst gemeldeten ID entsprechen. Der Dienst darf seine eigene Modellfamilie melden; die Namen `gemma2` und `qwen3_vl` sind bei dieser Auswahl nicht vorgeschrieben.

### Vorhandenen Modellserver anbinden

Installiere und starte dein Modell mit seiner vorgesehenen Laufzeit. Der Dienst muss beide unten beschriebenen Endpunkte unterstützen. Falls seine API anders aussieht, ist ein Adapter nötig. Eine reine Text-Embedding-API oder Chat-/Bildbeschreibungs-API genügt für diese Bildsuche nicht.

Der Installer kann deinen bestehenden Dienst mit Bild und Text prüfen. Er installiert dabei kein Gemma, ersetzt keine Docker-Konfiguration und speichert den eingegebenen Schlüssel nicht. Anschließend übernimmst du URL, Modell-ID und API-Key in NextGallery.

### Eigenes Modell über den NextGallery-Adapter bereitstellen

Für Modellanbieter und Betreiber mit einem eigenen Modell gibt es eine [Adaptervorlage](examples/custom_embedding_adapter.py). Ergänze `load_backend()` mit der Modellimplementierung und installiere deren Anforderungen in einer eigenen Python-Laufzeit, einschließlich Pillow. Das Modell muss Text und Bilder in denselben Raum einbetten.

Die Vorlage startet erst, wenn ein echtes Modell angebunden ist. `backend.identity` muss Gewichte/Modellstand, Laufzeitversionen, Vorverarbeitung, Präzision und Aufgabenprompts reproduzierbar beschreiben. Bei Änderungen muss die Identität wechseln. Der Adaptercode fließt zusätzlich in die Raum-ID ein.

```bash
# NEXTGALLERY_EMBEDDING_KEY zuvor als private Umgebungsvariable setzen.
/path/to/model-venv/bin/python worker/embedding_server.py \
  --family custom --model 'anbieter/mein-bild-text-modell' \
  --model-path /pfad/zum/modell --custom-adapter /pfad/adapter.py \
  --device cuda --host 0.0.0.0 --port 9379
```

`--device cpu` verwendet die CPU, sofern dein Adapter das unterstützt. Der Adapter ist ausdrücklich ausgewählter lokaler Python-Code und wird beim Dienststart geladen. NextGallery lädt keine ausführbaren Modelladapter aus einer Web-Anfrage herunter. Die Installation der jeweiligen Modell-Laufzeit bleibt modellabhängig.

### API-Vertrag

Alle Anfragen verwenden bei konfiguriertem Schlüssel `Authorization: Bearer <API-Key>`. Keine Weiterleitungen. Der Dienst erhält Bildbytes oder Suchtext, keine Nextcloud-Dateipfade.

**`GET /v1/info`** liefert beispielsweise:

```json
{
  "model": "anbieter/mein-bild-text-modell",
  "family": "meine_modellfamilie",
  "dimensions": 512,
  "space": "aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa",
  "modalities": ["text", "image"]
}
```

Die Beispiel-Raum-ID muss durch einen tatsächlichen stabilen SHA-256-Fingerabdruck ersetzt werden. Unterstützt werden 128–4096 Dimensionen. `space` muss sich ändern, sobald Gewichte, Laufzeit, Präzision, Vorverarbeitung oder Aufgabenprompts den Vektorraum ändern; derselbe Modellname oder dieselbe Dimension genügt nicht.

**`POST /v1/embed`** akzeptiert genau eine der beiden Eingaben:

```json
{"model":"anbieter/mein-bild-text-modell","text":"ein rotes Auto"}
```

```json
{"model":"anbieter/mein-bild-text-modell","image":"BASE64_KODIERTE_BILDBYTES"}
```

Die Antwort enthält `{"space":"<derselbe Fingerabdruck>","embedding":[...]}` mit exakt der gemeldeten Anzahl endlicher Zahlen und einem von null verschiedenen Vektor. NextGallery normalisiert die Vektoren. Videos werden als einzelne Standbilder eingebettet.

## Betrieb und Fehlerdiagnose

| Aktion | Befehl |
| --- | --- |
| Bereitschaft prüfen | `python3 scripts/install-embedding-server.py --language de --action check` |
| Verbindungsdaten anzeigen | `python3 scripts/install-embedding-server.py --language de --action info` |
| Starten / alte GPU-Zuordnung reparieren | `python3 scripts/install-embedding-server.py --language de --action start` |
| Stoppen | `python3 scripts/install-embedding-server.py --language de --action stop` |
| Deinstallieren, Modelle behalten | `python3 scripts/install-embedding-server.py --language de --action uninstall` |
| Auch Modell-Downloads entfernen | Nur bei ausdrücklich gewünschtem Löschen: zusätzlich `--delete-models` |

**`invalid device ordinal`:** Repository mit `git pull --ff-only` aktualisieren, dann `--action start` ausführen. Damit wird die alte Host-/Container-GPU-Zuordnung korrigiert. Key, Profil und Modell-Volume bleiben erhalten.

**Docker kann NVIDIA nicht einbinden:** Der Assistent bietet die Einrichtung des NVIDIA Container Toolkit an. Auf unterstützten Ubuntu-/Debian-Systemen kann er es mit Administratorrechten einrichten. Eine Docker-Neustartaktion kann auch andere Container betreffen. Bei bereits installiertem Toolkit:

```bash
sudo nvidia-ctk runtime configure --runtime=docker
sudo systemctl restart docker
```

**401/403:** API-Key prüfen. **Verbindung abgelehnt:** Containerstatus und Logs prüfen. **Zeitüberschreitung:** Ladefortschritt, Adresse, freigegebenen Port und Firewall prüfen. Die lokale Prüfung bestätigt nicht, dass Nextcloud denselben Port über das Netzwerk erreicht; anschließend dort ebenfalls **Verbindung prüfen**.

## Dasselbe Modell auf CPU und GPU

Der Modellname allein beweist keinen gleichen Vektorraum. Beim Originalmodell fließen Modellverzeichnis und Versionen von PyTorch, Transformers und Sentence Transformers in die Raum-ID ein. NextGallery ergänzt Bildauflösung und Video-Standbildanzahl.

Die Full-Laufzeit verwendet FP32 auf CPU und FP16 auf CUDA. Diese Präzisionswahl wird bisher nicht separat in ihrer Raum-ID abgebildet. Eine erfolgreiche ID-Prüfung ist keine Messung numerischer Gleichheit. Bei abweichender ID zuerst Modellstand, Laufzeit und Verarbeitungseinstellungen vergleichen; die Schutzprüfung nicht umgehen.
