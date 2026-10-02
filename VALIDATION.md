# Validierung – 2026-10-02

**App 0.2.9 auf Nextcloud 32.0.5 installiert und aktiviert; eigener Worker als www-data aktiv.**

| Prüfung | Ergebnis |
| --- | --- |
| Python 3.13.7 | 45 Tests erfolgreich, letzter Lauf 0,747 s |
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
| Dialogzentrierung | Globales Nextcloud-CSS überschrieben; 560 × 252 Dialog bei 1754 × 991 Viewport exakt zentriert (597; 369,5) |
| Dateiseitenleiste | Reale Bildanalyse, OCR, Tags und Unsicherheiten im Media-AI-Tab sichtbar |
| Browser-Volltextsuche | OCR-Wort LUNA findet das zugehörige Bild; Luna und LUNA liefern dieselbe Datei-ID |
| Native Dateiverknüpfung | `/f/538885` öffnet tatsächlich das Bild im Juni-Ordner |
| Ausgabe ohne App-Tokenlimit | Beide Stufen max_tokens=0; Wire-Regressionstest prüft, dass kein Ausgabe-/Reasoning-Limit gesendet wird |

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
wird nach Abschluss getrennt dokumentiert; persönliche Bilder und OCR-Texte
werden nicht in dieses Repository aufgenommen.

Die Tests umfassen unveränderte/geänderte Quellen, Cache/Wiederaufnahme,
getrennte Stufen, Publikationsfehler, eigene Daten-Resets, Authentifizierung,
Admin/Owner-Rechte, unzulässige Pfade und Werte, per-user DE/EN, ungültige und
abgeschnittene Modellantworten. Unit-Doubles sind ausdrücklich von echter
Nextcloud-Integration getrennt. Lokaler isolierter Nextcloud-32/MariaDB-10.11-
Integrationstest bestätigt Migration, Inventar und API-Authentifizierung.

Noch offen: kompletter frischer Juni-Lauf mit menschlichem Qualitätsvergleich,
reales Android-Gerät,
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
