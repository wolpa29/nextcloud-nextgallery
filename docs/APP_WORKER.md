# Integrierte App (0.3.5)

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
verarbeitet alle offenen Bilder der gewählten Ordner, ohne Dateilimit und ohne
Gesamtzeitgrenze. Pause greift nach der aktuellen Datei; Fortsetzen bearbeitet
die restlichen Bilder. Erfolgreiche unveränderte Bilder werden übersprungen.
Auch der tägliche Scan bearbeitet alle dann offenen Bilder. Ein über Nacht
hinaus laufender Scan läuft weiter; es wird kein zweiter Scan parallel gestartet.

`image_timeout_seconds` ist das Zeitbudget für **ein Bild**, gemeinsam für
Download/Quellprüfung, Vorverarbeitung, Vision, OCR und alle Korrekturversuche.
Standard: 600 Sekunden (10 Minuten), 0 deaktiviert die Zeitgrenze, maximal
86.400 Sekunden. Ein Überschreiten beendet den Bildversuch mit einem sichtbaren
Timeout-Fehler und der Scan arbeitet am nächsten Bild weiter. Bereits
vollständig erfolgreiche Stufen bleiben für eine spätere Wiederholung im Cache.
Unvollständige Antworten werden nicht als Ergebnis veröffentlicht.
Der Worker schließt beim Ablauf aktive HTTP(S)-Sockets auch bei fortlaufendem
Streaming; CPU-Vorverarbeitung wird an den nächsten Prüfpunkt gebunden, nicht
mit einem unsicheren Thread-Abbruch beendet. Das separate Modell-Zeitlimit ist
nur eine Netzwerk-Wartezeit je Anfrage ohne Daten. Es startet das Bildbudget
nicht neu. Es gibt weiter kein Ausgabe-/Thinking-Tokenlimit bei max_tokens=0.
Alte gespeicherte `limit`/`max_seconds` werden ignoriert; sie können den ersten
Gesamtscan nicht mehr unbemerkt beenden.

Die Promptfelder zeigen den vollständigen wirksamen Standard- oder eigenen
Text. „Standardprompt wiederherstellen“ setzt den Feldinhalt zurück; erst
Speichern übernimmt Änderungen. Unveränderter Standardtext wird als leerer
Override gespeichert und folgt zukünftigen Standards. Python und PHP lesen
die Standard-/Korrekturprompts aus derselben `worker/default_prompts.json`.
Die Refaktorierung hat keinen Prompttext verändert und invalidiert deshalb
keine bestehenden Analysen. Der konfigurierte Owner kann Modellprompts lesen
und kopieren; Änderungen bleiben Administratoren vorbehalten.
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
Beschreibung auf 1–2 Sätze und die unterschiedlichen Suchbegriffe in tags/objects auf zusammen 20; OCR wird nicht gekürzt. Eine Wartezeitgrenze ohne Netzwerkdaten pro Anfrage
bleibt für Verbindungsprobleme konfigurierbar (15–7200 Sekunden).
Die Temperatur ist modellabhängig: Qwen empfiehlt für Qwen3.8 im Thinking-Modus
1,0 mit top_p=0,95 und top_k=20. Die bisherigen Testwerte Vision 0,2 / OCR 0,0
sind keine allgemeine Empfehlung für dieses Modell. Änderungen zuerst an
fehlerhaften und klar lesbaren Kontrollbildern vergleichen; niedrige Temperatur
garantiert weder korrekte OCR noch Schutz vor Wiederholungen.
Quelle: [offizielle Modellkarte](https://huggingface.co/Qwen/Qwen3.8-27B).
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

## JSON, Streaming und Korrekturen

Beide Stufen verlangen `response_format: json_schema` mit `strict: true`.
Vision liefert Typ, kurze Beschreibung, Tags, Objekte und Erkennungsgrenzen.
OCR liefert genau `{"text":"..."}`. Nur `{"text":""}` bedeutet bei regulärem
Abschluss sicher eine leere Modellausgabe; ein leeres Antwortfeld, fehlende
Schlüssel, zusätzliche/duplizierte Schlüssel, ungültiges JSON, Tool-Aufrufe,
`finish_reason != stop` oder ein Stream ohne `[DONE]` gelten als Fehler.
Das prüft das Antwortformat, nicht die sachliche Richtigkeit der Erkennung.

Die derzeitige Qwen-/LM-Studio-Kombination legt das vollständige JSON teilweise
in `reasoning_content` ab, obwohl die offizielle Schnittstelle `content`
vorsieht. Der explizite Schalter „schema-valides JSON aus Reasoning-Feld erlauben“
ist deshalb in beiden Stufen aktiviert. Er akzeptiert ausschließlich das ganze,
schema-valide Objekt nach regulärem Ende; weder beliebige Gedanken noch ein
JSON-Fragment aus einem langen Reasoning werden herausgeschnitten.
[LM-Studio-Dokumentation](https://lmstudio.ai/docs/developer/openai-compat/structured-output).

Streaming prüft Reasoning und Antwort getrennt über beliebige Fragmentgrenzen.
Standard: Abbruch bei 5 aufeinanderfolgenden gleichen Textblöcken bis 256 Tokens,
auch bei kurzen Wörtern; verkettete Wörter ohne Trennzeichen und ähnliche Zeilen
mit langem, gleichbleibendem Satzanfang werden ebenfalls erkannt. Auch ein
langer gleicher Satzteil in fünf verschiedenen Zeilen wird geprüft; diese
zusätzliche Regel spart Zahlen-/Codezeilen aus, damit reale Tabellen und
Terminalvorlagen nicht als erfundene Schleifen gelten. Zahlenreihen
wie JUSB1…JUSB1000 haben einen eigenen konservativen Grenzwert von 40. Normale
nummerierte Anweisungen mit verschiedenen Inhalten und kurze echte Anschluss-
beschriftungen werden durch Regressionstests abgesichert. Die Erkennung ist
eine Heuristik: wiederholt gedruckter Bildtext kann einen Fehlalarm auslösen,
inhaltliche Halluzinationen ohne Wiederholung können durchkommen.

Bei Erkennung wird die HTTP-Stream-Verbindung geschlossen. Ein neuer Versuch
enthält dasselbe Bild, eine kurze Assistant-Fehlernotiz und den Korrektur-Prompt;
das riesige fehlerhafte Reasoning wird nicht erneut mitgesendet. Maximal drei
Korrekturen ergeben vier Gesamtversuche je Stufe. Jede neue Datei beginnt mit
einem frischen Chat. Standardtemperatur bei Korrekturen: 1,0; ein anderer Wert
ist konfigurierbar. Es werden kein Reasoning-Effort und kein Thinking-Budget
gesendet. `max_tokens=0` lässt den Ausgabeparameter weiterhin vollständig weg.

Pro Stufe stehen Streaming, Textblock-Grenzwert (3–20), Zahlenreihen-Grenzwert
(20–200), Korrekturen (0–3), Korrektur-Temperatur (0–2) und Korrektur-Prompt in
den Modelleinstellungen. Änderungen benötigen Administratorrechte. Ohne
Streaming erfolgt die Prüfung erst nach Eingang der vollständigen Antwort.
Modellkonfiguration und Prompts sind Bestandteil des Stufen-Fingerprints;
Änderungen lösen für diese Stufe eine neue Analyse aus.

Das Aktivitätslog zeigt Versuch/Gesamtzahl, Temperatur, Dauer, Schleifenart,
Reasoning/Antwort und ob eine Korrektur angefordert wurde. Erfolgreich korrigierte
Dateien tragen die Zahl der Korrekturen. Der Standardfilter und „Nur Fehler“
zeigen verworfene Versuche auch dann, wenn die Datei später erfolgreich wird.
Start/Ende jeder einzelnen Inferenz sind unter „Alle Ereignisse“ sichtbar.
Alle 15 Sekunden wird bei langen Streams nur der Live-Status aktualisiert,
mit Anzahl empfangener Zeichen; das erzeugt keine zusätzlichen Logzeilen.
Vollständige Reasoning-Texte, Bilddaten und Keys stehen nicht im Aktivitätslog.

Nach erfolglosen Korrekturen bleibt die Datei im Status Fehler; die fehlerhafte
Stufe wird weder gecacht noch publiziert. „Fehler erneut versuchen“ hebt die
Wartezeit auf. Bereits erfolgreiche Stufen bleiben im Cache. Ganze Dateien
werden erst nach erfolgreicher Vision und OCR und erneut bestätigtem ETag
in Nextcloud gespeichert. Originaldateien werden nur gelesen.

## Bildauflösung und vollständiger Bildrahmen (0.3.5)

Der Modellparameter `max_edge` begrenzt die längste Kante der **übertragenen Kopie**, keine Originaldatei. Vision/OCR starten mit 2048, passend zur am 03.10.2026 über das offizielle SDK ausgelesenen LM-Studio-Basiskonfiguration (`userMaxImageDimensionPixels=2048`, `ignoreModelPreferredMaxImageDimension=false`). Das sind bei einem Quadrat 4.194.304 Pixel, bei 4:3 2048×1536. Es handelt sich um die aktuell eingestellte Verarbeitung, nicht um eine universelle API-Annahme oder ein Hardwaremaximum. Höhere App-Werte können erneut im Backend verkleinert werden; bei Änderungen an LM Studio beide Stufen abgleichen.

Die Vorverarbeitung dreht mit EXIF, erhält das Seitenverhältnis und passt den ganzen Rahmen ein. JPEG (Vision) / PNG (OCR) werden komplett als Base64 übertragen. Log und `provenance.images` dokumentieren Quellgröße, orientierte Größe, gesendete Größe, Pixelzahl, Bytezahl, Format, SHA256, Verkleinerung und `full_frame=true`. Das bestätigt den vollständigen Rahmen auf unserer Seite; die API meldet die intern vom Vision-Encoder verwendete Auflösung nicht zurück. Kleine Schrift und Details können bei Verkleinerung verloren gehen. Ein erfolgreicher Aufruf garantiert keine vollständige oder fehlerfreie inhaltliche Erkennung. Animationen bleiben auf das erste Bild beschränkt.

`max_download_bytes` (64 MiB) und `max_image_pixels` (80 MP) sind davon unabhängige Lade-/Decoder-Speichergrenzen im aufklappbaren Bereich „Speichergrenzen für Originaldateien“. Größere Dateien werden mit Fehler sichtbar übersprungen, statt den Container unkontrolliert zu belasten. Beim Wechsel der OCR-Bildkante von 4096 auf 2048 wird beim nächsten Start nur der betreffende OCR-Cache ungültig; passende Vision-Ergebnisse bleiben nutzbar. Vorhandene Metadaten bleiben bis zur vollständigen erfolgreichen neuen Analyse gespeichert.
