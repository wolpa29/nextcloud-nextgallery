# Integrierte App (0.2)

Die Nextcloud-App enthält den Python-Worker. Er läuft als eigener systemd-Dienst
mit niedriger CPU-/IO-Priorität, unabhängig von PHP-FPM und Nextcloud-Cron.
Der GPU-Rechner benötigt ausschließlich einen erreichbaren OpenAI-kompatiblen
Vision-Endpunkt (hier LM Studio). Dort wird kein Nextcloud-Plugin installiert.

## Bedienung

- Apps: **Media AI search**, App-ID `media_ai`.
- Administration → **Media AI**: Status, Einstellungen, täglicher Start,
  Ordner/Ausschlüsse, getrennte Vision-/OCR-Endpunkte, Modelle, Prompts,
  Größen-/Zeit-/Token-Grenzen und API-Keys.
- Start/Pause/Fortsetzen/Fehler erneut versuchen. Pause greift zwischen Dateien.
- Tageslogs: Datei, Stufe, Dauer, Cache-Treffer und Fehler; ältere Einträge
  paginiert und Filter nach Fehlern. Keine API-Keys oder vollständigen OCR-Texte
  im Aktivitätslog.
- In Dateien: Details-Seitenleiste → **Media AI** für Beschreibung, OCR,
  Suchbegriffe, unsichere Angaben und vollständige gespeicherte Metadaten.
- In der globalen Nextcloud-Suche: Suchanbieter **Media AI**.

Standardmäßig sind tägliche Verarbeitung deaktiviert und der Worker pausiert.
Speichern ändert die Konfiguration für den nächsten Lauf. Ein manueller Start
arbeitet maximal `limit` Dateien und bis zur Zeitgrenze; eine angefangene Datei
wird fertiggestellt. Eine Wiederaufnahme ist ein neuer begrenzter Lauf.
Ist der GPU-Endpunkt nicht verfügbar, startet keine Bildanalyse; ein neuer
manueller Lauf oder der nächste tägliche Lauf versucht es erneut.

## Speicherorte und Zugriff

| Ort | Inhalt |
| --- | --- |
| App-Verzeichnis `media_ai/worker/` | Python-Code und Anforderungen |
| Nextcloud AppConfig | Einstellungen, mit Nextcloud `ICrypto` verschlüsselte Keys |
| Eigene Tabelle `oc_media_ai_meta` | Beschreibung, OCR, Objekte, Tags, EXIF-Kopie, Herkunft, ETag/SHA256 |
| `<datadirectory>/.media_ai/native.sqlite3` | Inventar, Fortschritt, Fehler, inkrementeller Ergebnis-Cache |
| `<datadirectory>/.media_ai/logs/YYYY-MM-DD.jsonl` | Nachvollziehbarer Aktivitätsverlauf |
| `<datadirectory>/.media_ai/status.json` | Aktuelle Datei/Stufe, Zähler, Heartbeat, letzter Lauf |
| `<datadirectory>/.media_ai/control.json` | Pause und manuelle Laufanforderung |
| `<datadirectory>/.media_ai/bootstrap.json` | Privates Worker-Zugangstoken, Nextcloud-URL und öffentliche CA |
| `/opt/nextcloud-media-ai-venv` | Isolierte Python-Abhängigkeiten |

Originale, EXIF in Originalen, Dateinamen, Ordner und bestehende Tags bleiben
unverändert. Der Worker lädt Bilder über die native Files-API und schreibt
Analyse nur über die App-API. Das Token gilt für den eingestellten Benutzer
und die ausgewählten Ordner; außerhalb davon verweigert die App den Zugriff.
Die globale Suche und die Detailanzeige prüfen Benutzer, Leserecht und ETag.
Eine eigene Tabelle alleine wäre kein Suchanbieter; `lib/Search/Provider.php`
verknüpft sie mit Nextcloud Unified Search.

OCR ist momentan ein eigener zweiter Modellaufruf, kein installiertes
Tesseract. Vision und OCR können verschiedene kompatible Modelle verwenden.
Modelländerungen oder Promptänderungen invalidieren die entsprechende Stufe.
Die Resultate bleiben KI-Ausgaben: Erkennung kann falsch sein; unsichere Details
sind separat gespeichert, keine automatischen Verschiebungen oder Löschungen.

## Installation

Vor der ersten Migration eine konsistente Datenbanksicherung erstellen.
App-Verzeichnis `nextcloud-app/media_ai` in einen konfigurierten beschreibbaren
Nextcloud-App-Pfad kopieren und `occ app:enable media_ai` als Webserver-Benutzer
ausführen. Anschließend als root:

```bash
scripts/install-worker.sh /var/www/nextcloud https://10.1.10.186 /path/to/public-ca.crt
```

Voraussetzung: Python >=3.10 mit venv, MariaDB/MySQL, Nextcloud 32, PHP >=8.2.
Die einmalige Installation benötigt root für venv/Dienst; täglicher Betrieb
und Einstellungen erfolgen aus Nextcloud. Kein Klartextpasswort in Git,
URLs, CLI-Argumenten oder Terminalausgaben. Ein öffentlicher CA-Key ist kein
privater Schlüssel. Browser-Seitenleiste verwendet die Nextcloud-32-API; für
Nextcloud 33 muss sie auf die neuere Sidebar-API umgestellt werden.

Vor Aktualisierung zuerst eigenen Worker-Dienst stoppen, Code aktualisieren,
Abhängigkeiten synchronisieren, `occ upgrade` ausführen, Dienst starten.
Zum Anhalten den App-Pause-Knopf oder `systemctl stop nextcloud-media-ai`
verwenden; Cache/Metadaten werden nicht gelöscht. Datenbank-Backup niemals
pauschal in eine laufende Instanz zurückspielen: es würde neuere Benutzerdaten
zurücksetzen. Bei App-Problemen zuerst Dienst stoppen/App deaktivieren.
