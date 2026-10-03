# Validierung – 2026-10-02

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
