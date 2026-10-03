# Validierung – 2026-10-03, App 0.3.3

**0.3.3 auf der echten Nextcloud 32.0.5 installiert; eigener Worker online, pausiert, tägliche Automation ausgeschaltet.**

| Prüfung | Ergebnis |
| --- | --- |
| Python | 68 Tests erfolgreich, inklusive echter SSE-Testserver |
| PHP 8.2 | 177 Assertions: Admin/Owner 29, Metadaten/Suche 23, Sprache 6, Einstellungen 100, Worker-Auth 19 |
| Syntax | Alle PHP-Dateien und drei JavaScript-Dateien gültig |
| Streaming-Abbruch | Langsamer HTTP-Testserver beobachtet geschlossenen Client deutlich vor Ende der Schleife |
| Retry-Grenze | Drei Korrekturen, vier Gesamtversuche; neuer Kontext beim nächsten Bild |
| Formatfehler | Fehlendes/trunkiertes JSON, doppelte/zusätzliche Schlüssel, Tool-Aufrufe, finish_reason=length und fehlendes DONE abgewiesen |
| Leere OCR | Nur vollständiges {"text":""} bei regulärem Abschluss als leeres Ergebnis akzeptiert; acht reale gültige leere Ergebnisse |
| Fehler ohne Publikation | Defekte Antworten nicht gecacht/veröffentlicht; erfolgreiche andere Stufe beim Retry weiter nutzbar |
| Wiederholungen | Gleiche Blöcke, ähnliche Zeilen, variierende lange Satzteile und fortlaufende Zahlen-Labels erkannt; reale Terminal-/Arbeitszeittabellen als Regression erlaubt |
| Grenzen / Berechtigungen | Falsche Typen, ungültige Grenzwerte und mehr als drei Korrekturen ohne Schreibeffekt abgewiesen; Owner kann Modellparameter nicht überschreiben |
| Live-Status | Versuch und empfangene Zeichen sichtbar, Fortschritt ohne zusätzliche Logzeilen |
| Frischer vollständiger Juni-Lauf | 45 Bilder in 1250,582 s (20 min 51 s), 45 veröffentlicht, keine endgültigen Dateifehler |
| Verwerfungen dieser Vollrunde | Fünf erkannte Schleifen und einmal mehr als 20 eindeutige Suchbegriffe; vier Dateien erfolgreich korrigiert |
| Nachträgliche OCR-Prüfung | Ein tatsächlich variierender Schleifentext zurückgezogen; nur eigener betroffener Datensatz/OCR-Cache/Suchzeile nach Sicherung entfernt |
| Reale OCR-Korrektur | 21,434 s, erfolgreiche Vision aus Cache; eine weitere Schleife abgefangen, 45 vollständige Ergebnisse wieder vorhanden |
| Kürzere Bildbeschreibung / Suchbegriffe | Alle 45 Vision-Stufen erneut in 630,383 s, 45 OCR-Cache-Treffer; ein Versuch mit zu vielen Suchwörtern erfolgreich korrigiert |
| Abschließender Kontrolllauf | 0,079 s, null Verarbeitung, null Publikation, keine neue Inferenz |
| Originale / Datenbank | Alle 45 Originale anhand SHA256 und ETag unverändert; eigene MariaDB enthält 45 Ergebnisse |
| Finale Antwortprüfung | Alle 45 gespeicherten Ergebnisse erneut mit aktueller Schema-/Schleifenprüfung geprüft; maximal 20 eindeutige Vision-Begriffe |
| Echte native Suche | Luna findet Notizzettel; Staubsauger findet vier passende Dateien, auch im Nextcloud-Browserdialog |
| Log-Layout | Nextclouds globales nowrap für die App überschrieben; lange Fehlertexte umbrechen innerhalb ihrer Spalte |
| Browser DE/EN | Neue Felder und Beschriftungen sichtbar, richtige Grenzen/Owner-Sperre; Desktop 1754 × 991 ohne äußeren Überlauf |
| Versionswechsel | Geschützte App-/State-Backups; occ upgrade erfolgreich, Worker aktiv |
| Privater Qualitätsvergleich | Alle 45 finalen Ergebnisse gegen vorher gespeicherte unabhängige Sichtprüfung einzeln bewertet; private HTML-Datei außerhalb von Git |

Die frische vollständige JSON-/Streaming-Runde war etwa 46 Prozent kürzer als
2319,310 s im vorherigen Protokoll. Prompts, JSON, Streaming und Temperatur
wurden gemeinsam verändert; das ist keine isolierte Temperaturmessung.
Die spätere OCR-Korrektur und komplette Vision-Verfeinerung sind separate
Durchläufe und nicht in den 1250,582 s enthalten.

**Gültiges Format und schleifenfreie Antworten bedeuten keine fehlerfreie
Erkennung.** Die finale qualitative Prüfung markiert acht Bilder mit besonders
unzuverlässiger OCR; weitere Bilder haben einzelne kleinere Text-/Bildfehler.
Große, gut lesbare Texte sind meist brauchbar, kleine/gedrehte Folienwarnungen,
Versandetiketten und Hardware-Kennungen werden teilweise falsch ergänzt.
Es gibt keine vollständige Referenztranskription und damit keine gemessene
Wortfehlerrate. Ursprüngliche Typfehler bei Monitorfotos und eine falsche
Druckerbehauptung sind beseitigt; manche Roboterbilder bleiben zu allgemein
beschrieben und einzelne ungesicherte Details bestehen.

Vision liefert eine kurze Beschreibung plus maximal 20 eindeutige Suchbegriffe;
OCR bleibt eine getrennte vollständige Transkription sichtbarer lesbarer Texte.
Duplikate zwischen tags/objects werden in Log und Seitenleiste ausgeblendet.
Unsichere Details sind nicht Teil des Suchtextes.

Die aktuelle LM-Studio-/Qwen-Kombination legt schema-valides JSON in
reasoning_content ab. Nur das vollständige Objekt nach regulärem Ende wird
über den sichtbaren Kompatibilitätsschalter akzeptiert. Der Worker sendet
bei max_tokens=0 weiterhin kein Ausgabe-/Thinking-Budget und keinen
Reasoning-Effort. Konversationen beginnen pro Bild neu; Korrekturversuche
enthalten nur eine kurze Fehlermeldung, keinen vollständigen alten Gedankengang.
Temperaturen: Vision 0,2, OCR 1,0, Korrektur 1,0. Der Nutzer stellt Medium in
LM Studio ein; dieser Level wird durch die API nicht unabhängig bestätigt.

Originaldateien wurden nicht geändert. Private Bilder, OCR und Rohantworten
bleiben außerhalb des privaten Quellcode-Repositories. Ein OCR-Datensatz wurde
gezielt nach Sicherung ersetzt; die ursprüngliche vollständige Vergleichsrunde
und ältere Qualitätsberichte bleiben privat erhalten.

Offen bleiben reales Android-Gerät, ein tatsächlich verifizierter schmaler
Browser-Viewport, HEIC/AVIF aus der Sammlung, Durchsatz der gesamten Sammlung
und GPU-VRAM-Messung. HTTP-Abbruch ist am Testserver nachgewiesen; echte
GPU-Stornierung wurde nicht durch separate GPU-Messungen bestätigt.

---

## Vorheriges Protokoll – 2026-10-02

**App 0.2.11 auf Nextcloud 32.0.5 installiert und aktiviert; eigener Worker als www-data aktiv.**

| Prüfung | Ergebnis |
| --- | --- |
| Python 3.13.7 | 47 Tests erfolgreich, letzter Lauf 0,717 s |
| PHP 8.3 in isoliertem Nextcloud-32-Container | 89 Assertions: Admin/Owner 21, Metadaten/Suche 23, Sprache 6, Einstellungen 20, Worker-Auth 19 |
| Alle PHP-Dateien | Syntaxprüfung erfolgreich |
| JavaScript | Alle drei App-Skripte mit `node --check` geprüft |
| Echte Installation | MariaDB-Migration, DI, Routen und systemd-Dienst erfolgreich |
| Native Worker-API | Ohne gültiges Bearer-Token HTTP 401 |
| Erster echter Pilot | 5 Bilder in 133,225 s, 0 Fehler, 5 publiziert, SHA256/ETag aller Originale unverändert |
| Initiales Inventar | 81.473 Bilder in 20,6 s; nur konfigurierte Batches analysiert |
| Vor Reset | 17 eigene KI-Datensätze; Prüfsummen aller 45 Juni-Originale unverändert |
| Echter KI-Reset über Pauls Oberfläche | Eigene MariaDB-Datensätze 0; SQLite files/results/search jeweils 0 |
| Echte Log-Löschung über Oberfläche | Logdateien 0; alle 45 Originale danach weiterhin unverändert |
| Dunkles Design | Fehlertext nutzt color-error-text: #FFCCCC statt Hintergrundfarbe #552121; alle sechs Meldungen im Browser lesbar |
| Dialogzentrierung | Globales Nextcloud-CSS überschrieben; 560 × 252 Dialog bei 1754 × 991 Viewport exakt zentriert (597; 369,5) |
| Dateiseitenleiste | Reale Bildanalyse, OCR, Tags und Unsicherheiten im Media-AI-Tab sichtbar |
| Browser-Volltextsuche | OCR-Wort LUNA findet das zugehörige Bild; Luna und LUNA liefern dieselbe Datei-ID |
| Native Dateiverknüpfung | `/f/538885` öffnet tatsächlich das Bild im Juni-Ordner |
| Ausgabe ohne App-Tokenlimit | Beide Stufen max_tokens=0; Wire-Regressionstest prüft, dass kein Ausgabe-/Reasoning-Limit gesendet wird |
| Vollständiger Medium-Juni-Lauf | 45 Dateien in 2319,310 s; zunächst 41 publiziert, 4 Fehler; nach Nachprüfung 39 publiziert, 6 Fehler |
| Fehleraufteilung | 2 leere finale OCR-Antworten, 2 dominante Wiederholungen, 2 fortlaufende Zahlen-Schleifen |
| Unveränderter Kontrolllauf | 0,080 s; 0 verarbeitete Dateien, 0 Publikationen, keine neuen Bilddownloads/Inferenzstufen; Fehler warten auf Retry |
| Abschlussprüfung Originale | SHA256 und ETag aller 45 Dateien unverändert; eigene MariaDB enthält 39 veröffentlichte Datensätze |
| Temperatur-Einzeltest | Identisches Bild: OCR 0,0 → 141 gleiche Zeilen; OCR 1,0 → 18,047 s, 457 Ausgabe-Tokens, 108 Reasoning-Tokens, keine dominante Schleife |

Der Nutzer hat in LM Studio Reasoning auf Medium gestellt. Der Worker sendet
keinen Reasoning-Override; die API bestätigt den aktuell gewählten Level nicht
unabhängig. Vision blieb bei Temperatur 0,2, OCR bei 0,0. Alle 45 Bilder wurden
vor den Modellergebnissen unabhängig angesehen und anschließend einzeln
verglichen. Der private HTML-Bericht enthält die vollständige Bewertung mit
Vorschauen; Bilder und personenbezogene OCR bleiben außerhalb von Git.

Vision-Stufen dauerten im Median 12,015 s (Maximum 15,686 s). Erfolgreich
beendete OCR-Stufen: Median 17,466 s, Maximum 117,856 s. Fehlgeschlagene
OCR-Anfragen benötigten ungefähr 219, 390, 238 und 30 s (aus sekundengenauen
Logzeitpunkten). Zwei zunächst akzeptierte OCR-Texte erfanden fortlaufende
Beschriftungen; die erweiterte Prüfung weist auch diese Zahlen-Schleifen ab.
Nur die beiden betroffenen eigenen KI-Datensätze, OCR-Caches und lokalen
Suchzeilen wurden nach Sicherung zurückgezogen; erfolgreiche Vision-Caches
bleiben für Retry erhalten. Alle sechs Dateien stehen auf Fehler.

Mehrere Monitorfotos werden fälschlich als Screenshots bezeichnet. Ein
Roboter-Dock wurde als Drucker erkannt. Große, klare Dokumenttexte sind meist
brauchbar; kleine, gedrehte Aufkleber liefern auch plausible, aber falsche
Zeichenfolgen. Das ist eine qualitative Prüfung, keine Wortfehlerrate anhand
einer vollständigen Referenztranskription.

Der isolierte Temperaturtest verwendete denselben Prompt und dasselbe Bild,
änderte nur die OCR-Temperatur auf 1,0 und schrieb weder Cache noch
Nextcloud-Suchdaten. Große Produktangaben stimmen, kleine Etikettentexte bleiben
teilweise falsch. Ein Einzeltest rechtfertigt noch keine allgemeine
Zuverlässigkeitsbehauptung. Die produktiven Temperaturen wurden nicht geändert.
Pipeline pausiert, täglicher Lauf deaktiviert; Worker bleibt online.

Der frische vollständige Juni-Lauf umfasst **45 JPEG-Bilder, keine Videos**, 186.148.617 Bytes.
Tägliche Automation ist ausgeschaltet. Maximal 45 Dateien pro manuellem Lauf,
6 Stunden Batchbudget; bis zu 2 Stunden Netzwerkzeit pro Modellanfrage.
Das Batchbudget wird zwischen Dateien geprüft, beendet kein laufendes Reasoning.
Der Modellserver meldet aktuell einen geladenen Kontext von 114.810 Tokens.

Die frühere begrenzte OCR-Konfiguration lieferte bei vier Bildern einen
nicht normal beendeten Modellaufruf. Diese Antworten wurden nicht publiziert.
Auf Benutzerwunsch wurden eigene KI-Ergebnisse/Cache/Logs zurückgesetzt und
beide Ausgabegrenzen entfernt. Finalbeschreibung knapp, OCR vollständig,
Reasoning unverändert dem Modell überlassen. Die anschließende Qualitätsprüfung
ist oben dokumentiert; persönliche Bilder und OCR-Texte
werden nicht in dieses Repository aufgenommen.

Die Tests umfassen unveränderte/geänderte Quellen, Cache/Wiederaufnahme,
getrennte Stufen, Publikationsfehler, eigene Daten-Resets, Authentifizierung,
Admin/Owner-Rechte, unzulässige Pfade und Werte, per-user DE/EN, ungültige und
abgeschnittene Modellantworten. Unit-Doubles sind ausdrücklich von echter
Nextcloud-Integration getrennt. Lokaler isolierter Nextcloud-32/MariaDB-10.11-
Integrationstest bestätigt Migration, Inventar und API-Authentifizierung.

Noch offen: reales Android-Gerät,
HEIC/AVIF aus der Sammlung, Durchsatz der gesamten Sammlung und GPU-VRAM-Messung.
Responsive Regeln sind vorhanden; eine Handyansicht gilt erst bei tatsächlich
verifiziertem schmalem Browser-Viewport als geprüft.

Historischer Test mit künstlichen Bildern: [Live-GPU-Pilot](docs/LIVE_PILOT.md).

Der echte 28.872-Zeichen-OCR-Fehler mit 2.624 Wiederholungen von
`[unlesbar]` wird von der neuen Prüfung ausdrücklich abgewiesen.
Regressionstests bestätigen: Dateifehler, kein OCR-Cache/Suchtreffer/Publishing,
normaler Kassenzettel erlaubt, manuelle Wiederholung verarbeitet erneut und
verwendet die erfolgreiche Vision-Stufe weiter. Nutzer hat in LM Studio
Reasoning auf Medium gesetzt und einen zweiten vollständigen eigenen
KI-/Log-Reset mit frischem Juni-Lauf angefordert.
