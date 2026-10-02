# Integrierte App (0.2.9)

Die Nextcloud-App enthält den Python-Worker. Er läuft als eigener systemd-Dienst
mit niedriger CPU-/IO-Priorität, unabhängig von PHP-FPM und Nextcloud-Cron.
Der GPU-Rechner benötigt ausschließlich einen erreichbaren OpenAI-kompatiblen
Vision-Endpunkt (hier LM Studio). Dort wird kein Nextcloud-Plugin installiert.

## Bedienung

- Apps: **Media AI**, App-ID `media_ai`.
- Administration → **Media AI**: Status, Einstellungen, täglicher Start,
  Ordner/Ausschlüsse, getrennte Vision-/OCR-Endpunkte, Modelle, Prompts,
  Größen-/Zeit-/Token-Grenzen und API-Keys.
- Start/Pause/Fortsetzen/Fehler erneut versuchen. Pause greift zwischen Dateien.
- Tageslogs: Datei, Stufe, Dauer, Cache-Treffer und Fehler; ältere Einträge
  paginiert. Standardansicht: Dateien und Ergebnisse; sämtliche Stufen bleiben
  unter „Alle Ereignisse“ sichtbar. Nur abgeschlossene Dateien erhalten einen
  Ergebnisbereich, der Beschreibung/OCR bei Bedarf aus der eigenen Tabelle
  nachlädt. Keine API-Keys oder vollständigen OCR-Texte in JSONL-Logs.
- In Dateien: Details-Seitenleiste → **Media AI** für Beschreibung, OCR,
  Suchbegriffe, unsichere Angaben und vollständige gespeicherte Metadaten.
- In der globalen Nextcloud-Suche: Suchanbieter **Bildinhalt und OCR**.

Standardmäßig sind tägliche Verarbeitung deaktiviert und der Worker pausiert.
Während einer aktiven Analyse wird Speichern abgewiesen; zuerst pausieren und die aktuelle Datei beenden lassen. Ein manueller Start
arbeitet maximal `limit` Dateien und bis zur Zeitgrenze; eine angefangene Datei
wird fertiggestellt. Eine Wiederaufnahme ist ein neuer begrenzter Lauf.
Ist der GPU-Endpunkt nicht verfügbar, startet keine Bildanalyse; ein neuer
manueller Lauf oder der nächste tägliche Lauf versucht es erneut.

Die konfigurierte Person kann eigene Ordner, Ausschlüsse, Laufgrenzen und
Zeitplan verwalten. Modellserver, Prompts, API-Keys und Zielbenutzer ändern nur
Nextcloud-Administratoren. Der Sprachschalter ist pro Benutzer gespeichert und
ändert nur die Oberfläche. Analysesprache bleibt Deutsch, OCR Originalsprache;
ein Sprachwechsel startet keine erneute Analyse.

Unter Wartung können pausiert die Aktivitätslogs geleert oder sämtliche eigenen
KI-Ergebnisse und der SQLite-Analysecache zurückgesetzt werden. Beide Aktionen
benötigen eine ausdrückliche Bestätigung im Dialog; Originale und andere
Nextcloud-Tabellen sind nicht Teil des Resets. Der KI-Reset benötigt den Worker
online, läuft zwischen Analysen und bleibt bei Fehlern als Anforderung erhalten.

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
Ausgabe-Tokens: `0` lässt den Parameter vollständig weg. Die App setzt weder
Reasoning-Effort noch Thinking-Budget. Der Standardprompt begrenzt nur die finale
Beschreibung auf 2–4 Sätze; OCR wird nicht gekürzt. Eine Zeitgrenze pro Anfrage
bleibt für Verbindungsprobleme konfigurierbar (15–7200 Sekunden).
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

## Wie die Suche arbeitet

MariaDB erstellt einen gemeinsamen FULLTEXT-Index über `description`,
`ocr_text`, `tags_text` und `objects_text`. Der Provider zerlegt die Eingabe in
bis zu 16 Wörter ab drei Zeichen und fragt im Boolean Mode `+Wort*` ab:
alle Wörter müssen beim selben Bild vorkommen, Wortanfänge sind erlaubt.
Suchbegriffe und die vier indexierten Textkopien werden klein geschrieben,
weil Nextclouds binäre Tabellen-Kollation sonst Groß-/Kleinschreibung trennt.
Das Original einschließlich Schreibweise bleibt in `metadata_json` erhalten
und wird für Oberfläche und Suchvorschau verwendet.
Die Suche liest gespeicherte Analysen, startet keine Modellanfrage und arbeitet
auch bei ausgeschaltetem GPU-PC. Synonyme, Tippfehler, beliebige Teilwörter und
semantische Ähnlichkeit sind nicht implementiert. MariaDB-Stopwörter gelten.
Dateizugriff und ETag werden vor Anzeige erneut geprüft. Treffer verlinken auf
den nativen Nextcloud-Dateilink `/f/{fileid}`.

## Fehler durch Wiederholungen

Fertige OCR-Texte und Bildbeschreibungen werden vor Cache/Publikation auf
offensichtliche dominante Schleifen geprüft: mindestens 20 gleiche Zeilen
mit mindestens 80 Prozent Anteil, oder ein kurzer Textblock mit mindestens
20 Wiederholungen und mindestens 80 Prozent Anteil. Vereinzelte unlesbare
Stellen und normale wiederkehrende Beträge auf Belegen werden zugelassen.
Die Prüfung kürzt keinen Text und beschränkt kein Reasoning. Sie ist eine
konservative Heuristik, keine Garantie gegen sämtliche Modellfehler.

Bei Erkennung bleibt die Datei im Status Fehler; der fehlerhafte Stufentext
wird weder gecacht noch als Suche publiziert. Der Button „Fehler erneut
versuchen“ hebt die Wartezeit auf und wiederholt die fehlgeschlagene Stufe.
Eine bereits erfolgreiche Bildanalyse kann dabei aus dem Cache kommen.
Ungültige ältere Cache-Einträge werden verworfen, damit ein Retry tatsächlich
eine neue Modellanfrage auslösen kann. Im Aktivitätslog steht die Ursache
ohne den wiederholten privaten Text. Die Erkennung prüft die fertige Antwort;
es handelt sich nicht um einen Abbruch während des Reasonings.
