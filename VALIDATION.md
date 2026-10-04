# Ergänzung – 2026-10-04: Video-Frames anhand vorhandener Zeitstempel

- Erster und letzter decodierter Quellframe plus nächstgelegene unterschiedliche Frames zu gleichmäßig verteilten Zielzeitpunkten. Weniger als die eingestellte Anzahl: alle vorhandenen Frames. Eine Stichprobe: nächstgelegener Frame zur zeitlichen Mitte. Tatsächliche Framezahl und Zeitstempel werden decodiert, nicht aus nomineller Bildrate/Dauer geschätzt. Frame-Index, Ziel-/Istzeit, ursprünglicher Zeitstempel und Abstand zum nächsten Quellframe stehen in der Provenienz; die letzte Frame-Dauer kann unbekannt sein.
- Auswahl erfolgt in einem FFmpeg-Durchlauf anhand der Quellframe-Indizes, ohne Seeking auf möglicherweise nicht vorhandene Zeitpunkte. Drehung, Qualitätsbudget, gemeinsame Vision-Anfrage, ausgelassene Video-OCR, Datei-/Zeitgrenzen und unvollständige Ergebnisse als Fehler bleiben erhalten. Neue Video-Stufenrevision verhindert alte Cache-Treffer bei erneuter Verarbeitung; ein Codewechsel allein setzt fertige Dateien nicht zurück.
- 100 Python-Tests lokal erfolgreich. Darunter 12 Video-Prüfungen: 1/2/5-Frame-Kurzvideos, erster/letzter Frame und pixelgenau geprüfte Auswahl, variable Bildrate, Startzeitversatz, fehlende Laufzeit/falsche Header-Framezahl, kollidierende Ziele ohne doppelte Stichproben, einzelne Stichprobe, Cache-Wechsel, Drehung, keine OCR, unveränderte Originalbytes, Zeitbudget und fehlgeschlagene Extraktion ohne Teilveröffentlichung.
- Dieselben 12 Video-Prüfungen auf dem Nextcloud-Server mit dessen FFmpeg 5.1.9 und Python 3.11.2 erfolgreich. Alle Testmedien synthetisch und temporär; Modell-/Publisher-Prüfungen simuliert, kein produktiver KI-Aufruf für die Tests und kein Öffnen privater Medien.
- Die zwei geänderten Worker-Dateien auf Nextcloud installiert; SHA256 identisch zum getesteten Checkout. Backup unter `/root/media-ai-backups/20261004-video-endpoints`, inklusive alter Quellen, ursprünglicher Steuerdatei und SQLite-Sicherung. Laufende Datei vor der kurzen Dienstunterbrechung vollständig abgeschlossen. Gespeicherte App-Einstellungen, 1.267 Nextcloud-Analysezeilen, native SQLite-Datei und Bootstrap vor/nach Installation und pausiertem Neustart per Hash identisch. Kein App-Upgrade oder Zurücksetzen von Ergebnissen erforderlich.
- Zusätzliche Probe der tatsächlich installierten Implementierung als Dienstbenutzer `www-data`: synthetische Videos mit 1/2/5/30 Quellframes liefern 1/2/5/10 unterschiedliche Stichproben mit korrektem ersten/letzten Frame und unveränderten Originalbytes. Worker danach wieder online und laufend, Pause aufgehoben und Fortsetzungsanforderung konsumiert; neue MainPID 1324584. Die konkreten privaten Fehlervideos wurden nicht geöffnet oder manuell analysiert; ihre tatsächlichen Ergebnisse ergeben sich erst bei erneuter Verarbeitung.

# Ergänzung – 2026-10-03, App 0.5.0: Bestandsübersicht und eigene Ansichten

- Sechs getrennte Ansichten statt einer langen Seite mit Sprungmarken: Übersicht, Dateien, Verarbeitung, Aktivitäten, Einstellungen und Wartung. URL, Browser-Zurück und mobile Navigation bleiben synchron. Speicherstatus und ungespeicherte Eingaben bleiben beim Ansichtswechsel erhalten. Laufbuttons berücksichtigen Workerzustand, Pause-Anforderung, offene Dateien und Fehler; doppelte oder unpassende Steueraktionen werden auch serverseitig unter der bestehenden Steuerdateisperre abgewiesen.
- Lesende Bestandsübersicht mit Bildern/Videos, Fortschritt, offenen Dateien, Fehlern, ausgewählten Ordnern und zuletzt gespeicherten Ergebnissen. Dateiansicht mit lazy geladenem Ordnerbaum, Pfadnavigation, Suche, Statusfiltern und höchstens 100 Dateien pro Seite. Ein grüner Haken setzt gespeicherte Metadaten mit aktuellem ETag voraus; Ordner sind erst vollständig, wenn alle ausgewählten Medien bearbeitet sind. Die Vorschau der nächsten Ausführung berücksichtigt den aktuellen Bestand und Retry-Zeitpunkte. Neue Dateien und Modelländerungen werden beim tatsächlichen Start erneut geprüft.
- Nextcloud-Dateiliste: zusätzliche Spalte Media AI mit Dateistatus und Ordnerzahlen. Registrierung als Init-Script vor dem Aufbau der nativen Files-Oberfläche; bestehende Views und Spalten bleiben erhalten. Batch maximal 100 lesbare IDs, Metadaten nach Benutzer getrennt. Nextcloud blendet zusätzliche Spalten in der Kachelansicht und in schmalen Listen aus; die eigene App-Dateiansicht zeigt Status auch mobil.
- Keine Bilddownloads oder Analyse-JSON für Bestands-/Statusanzeigen. Inventar und Warteschlange der aktuellen Auswahl werden lesend zusammengeführt; SQLite wird im Read-only-Modus geöffnet. Servercache 10 Sekunden, sichtbare App-Ansicht maximal alle 15 Sekunden, native Statusanzeige mit kurzen Caches. Benchmark der tatsächlichen PHP-Library einschließlich Nextcloud-Bootstrap: erster Aufruf 367–413 ms, Cache-Aufrufe 14–20 ms, Spitzenverbrauch 81–82 MiB. Das sind Servermessungen, keine vollständigen Browser-Ladezeiten.
- 345 PHP-Assertions erfolgreich, einschließlich 44 neuer Library-/Statusprüfungen: ETags, Scope-Isolation, unveränderte SQLite-Datei, Retry-Vorschau, Ordnersummen, Suche, 100er-Paginierung, unlesbare IDs und Benutzertrennung. Sämtliche PHP-Dateien sowie vier Frontend-Skripte syntaxgeprüft; `git diff --check` erfolgreich. `npm ci` und Neubau erzeugen denselben ausgelieferten Statusspalten-Bundle. Worker-Implementierung unverändert.
- Isolierte Browser-Fixtures prüfen getrennte Ansichten, History-Zurück, DE/EN, Admin/Owner/403, laufenden/pausierten/offline Worker, mobile Navigation, Speicherstatus bei Ansichtswechsel/Rücksetzen, Suche und Paginierung. Simulierte Antworten verwenden ausdrücklich einen Testbestand. Helle/dunkle Layouts und 390×844 Pixel visuell geprüft; kein horizontaler Überlauf. Die 403-Prüfung wartet auf die abgeschlossene Berechtigungsantwort.
- Echter Browser: 28.910 ausgewählte Medien (27.108 Bilder, 1.802 Videos), 269 bearbeitet, 28.641 offen einschließlich eines Fehlers. November 2014: 96 Dateien, davon 30 bearbeitet und 66 offen; Bearbeitet-Filter zeigt genau 30 Dateien. Gespeicherte Beschreibung/OCR lassen sich öffnen. Native Files-Liste zeigt grüne Haken für bearbeitete Dateien und korrekte Ordnersummen. Die vorherige native Kachelansicht wurde nach der Prüfung wiederhergestellt. Screenshots: `../media-ai-workspace-live-20261003.png`, `../media-ai-files-live-20261003.png`, `../media-ai-nextcloud-files-status-20261003.png`.
- Nextcloud 0.5.0 installiert, alle 16 geänderten App-Dateien per SHA256 zum Checkout identisch. Backup: `/root/media-ai-backups/20261003-workspace-0.5.0/app-before.tar.gz`. Worker war schon vor der Installation pausiert; MainPID 345144, Steuerdatei und gespeicherte Konfiguration vor/nachher unverändert. Der kurze Upgrade-Wartungsmodus verursachte einen HTTP-503-Logeintrag; der Worker erholte sich ohne Neustart und blieb pausiert. Kein neuer Analyselauf, kein Speichern produktiver Einstellungen und kein Eingriff in Originaldateien oder vorhandene Analyseergebnisse.

# Ergänzung – 2026-10-03, App 0.4.5: Design und Seitenleiste

- Nextcloud-Seitenleiste mit Status, Verarbeitung, Aktivitätslog, Einstellungen und Wartung. Ausgewählte Bereiche werden angesprungen und bei Bedarf geöffnet; die mobile Navigation klappt nach der Auswahl zu. Zentrierter Inhalt, kompaktere Formulargruppen, flache Statuszahlen und aufklappbare Modellfelder. Farben folgen dem Nextcloud-Theme. Dateiname und Dauer bleiben im Log sichtbar, technische Angaben sind separat aufklappbar. Speicherstatus und vorhandene Berechtigungen bleiben erhalten.
- PHP-8.2-Templatesyntax, JavaScript-Syntax, `git diff --check` und 177 bestehende PHP-Assertions erfolgreich. Isolierte Chromium-Prüfungen für Sidebar, Admin/Owner/403, DE/EN sowie Regressionen für Speicherzustände und elf Dauerfälle erfolgreich. Speicherantworten in diesen Fixtures simuliert. Helle/dunkle Vorschau und 390-Pixel-Layout visuell geprüft: kein horizontaler Seitenüberlauf, mobile Navigation schließt nach der Auswahl.
- Frontend auf der echten Nextcloud während einer laufenden Analyse ausgetauscht. Backup: `/root/media-ai-backups/20261003-design-sidebar/frontend-before.tar.gz`. Alle fünf installierten Dateien per SHA256 zum lokalen Stand identisch. Kein Upgrade, Wartungsmodus oder Worker-Neustart; MainPID vor/nach Austausch 345144, Steuerdatei und gespeicherte Konfiguration unverändert. Keine produktive Konfiguration gespeichert.
- Live-Browser bestätigt 224-Pixel-Seitenleiste, mittigen Inhalt (177 Pixel Abstand links/rechts bei 1754 Pixel Breite), laufenden Worker und „Gespeichert“. Navigation öffnet Einstellungen, Ordnerdialog lässt sich abbrechen, Analyse-Details enthalten weiterhin Pixel-/OCR-Angaben und Datei-Dauern sind sichtbar. Screenshot: `/home/paul/Desktop/Projects/tmp/media-ai-design-live-20261003.png`.

# Ergänzung – 2026-10-03, App 0.4.5: Dauer in Datei-Aktivitäten

- Ursache: `file_finished` enthält kein `seconds`, während Modell-/Stufenabschlüsse gemessene Sekunden enthalten. Der Standardfilter blendet Stufen aus; dadurch stand bei Dateiabschlüssen bisher „—“.
- Die Oberfläche verbindet vorhandene `file_start`-/Dateiabschluss-Einträge anhand von Lauf-ID und Datei-ID/Pfad. Aus Logzeitpunkten berechnete Dauer ist ausdrücklich mit „≈“ und einem erklärenden Tooltip markiert, da die Zeitpunkte auf Sekunden gerundet sind. Direkt protokollierte Sekunden (auch 0) bleiben maßgeblich. Fehlende/ungültige Startpunkte erhalten keine erfundene Dauer; beim Nachladen älterer Seiten wird neu berechnet.
- JS-Syntax und elf isolierte Chromium-Prüfungen erfolgreich: Zeitdifferenz, präzise Null/Fehler-/Stufenzeiten, erneute Bearbeitung derselben Datei, Lauftrennung, ungültige/fehlende Zeitpunkte, Seitenrand, Tooltip und Nachladen älterer Einträge. Fixture unter `/tmp/media-ai-log-duration-review-20261003`.
- Ausschließlich `js/admin.js` und `js/i18n.js` auf Nextcloud aktualisiert; kein `occ upgrade`, Wartungsmodus oder Worker-Neustart. Backup unter `/root/media-ai-backups/20261003-log-duration-hotfix`. Worker-PID vor/nach Austausch identisch (345144), Steuerdatei bytegleich, installierte Skripte identisch zum lokalen Stand. Der zuvor aktive Lauf endete regulär mit 158 verarbeiteten Dateien, 0 Fehlern und 6849,374 s; keine Stopp-/Pause-Anforderung durch diese Arbeit.
- Live-Browser bestätigt Datei-Dauern „≈ 58 s“, „≈ 58 s“, „≈ 55 s“ und protokollierte Laufdauer „6.849,374 s“. Die Logdateien und bestehende Analyseergebnisse wurden nicht geändert.

# Ergänzung – 2026-10-03, App 0.4.5: Zentrierung und Speicherstatus

- App-Inhalt mit automatischen seitlichen Abständen zentriert. Speicherbuttons zeigen „Gespeichert“, „Ungespeicherte Änderungen“, „Wird gespeichert …“ oder „Nicht gespeichert · erneut versuchen“. Bei offenen Änderungen/Speicherung bleibt eine Speicherleiste beim Scrollen sichtbar. Ohne Änderungen und während einer Anfrage sind Speicherbuttons deaktiviert.
- Vergleich mit dem geladenen/erfolgreich gespeicherten Formularstand; Änderungen zurück auf den Ausgangswert entfernen die Markierung. Ordnerdialog und Prompt-Reset lösen die Änderungsanzeige ebenfalls aus. Fehler erhalten die Eingaben; weitere Eingaben während einer laufenden Speicherung bleiben als neue ungespeicherte Änderungen erhalten. DE/EN unterstützt.
- Geprüft: PHP-8.2-Templatesyntax, 154 bestehende PHP-Settings-/Berechtigungsassertions, JS-Syntax und isolierte Chromium-Prüfungen für Admin, Owner, Englisch und fehlende Verwaltungsberechtigung. Prüfungen umfassen Rücksetzen, Checkbox, laufende/fehlgeschlagene/erneute Speicherung, Doppelabsenden, weitere Eingaben während der Anfrage, Ordnerdialog, Prompt-Reset und sichtbare Speicherleiste beim Scrollen. Fixtures unter `/tmp/media-ai-save-state-review-20261003`; Speicherantworten dort simuliert.
- Live als Owner: bei 1754 px links/rechts jeweils 289 px Abstand, kein horizontaler Seitenüberlauf. Änderung des Dateizeitbudgets und einer Speichergrenze zeigt sofort offene Änderungen; Zurücksetzen zeigt wieder „Gespeichert“. Speicherleiste in aufgeklappten Einstellungen bei y=74 sichtbar. Testeingaben zurückgestellt, keine produktive Konfiguration gespeichert.
- Version 0.4.5 erfolgreich auf Nextcloud installiert; geschütztes Backup unter `/root/media-ai-backups/20261003-layout-save-0.4.5`. Worker während des Upgrades gestoppt und danach online/pausiert. Einstellungen und Metadaten vor/nach Installation per SHA256 identisch, Steuerdatei bytegleich, installierte App-Dateien identisch zum lokalen Stand. Die vorhandene Ordnerauswahl `/InstantUpload/Camera` blieb erhalten.

# Ergänzung – 2026-10-03, App 0.4.4: Reihenfolge und Logo

- Status → sichtbare Verarbeitung mit Ordnern/Ausschlüssen und Speichern → Aktivitätslog → eingeklappte Einstellungen (Modellprüfung, Speichergrenzen, Modelle/Prompts) → eingeklappte Wartung.
- Ein gemeinsames Formular erhält die bestehende Konfigurationsspeicherung. Logbuttons sind explizit `type="button"`; die neu getrennten erweiterten Einstellungen werden bei fehlender Verwaltungsberechtigung ebenfalls ausgeblendet. Die Modellprüfung bleibt verfügbar.
- Lokal geprüft: PHP-8.2-Templatesyntax, JS-Syntax, 33 bestehende PHP-Berechtigungsassertions sowie isolierte Chromium-Prüfung für Reihenfolge, eingeklappte Bereiche, sichtbare Ordnerauswahl, Ordnerdialog, Speichern bei eingeklappten Einstellungen, Log-Aktualisierung ohne Speichern und fehlende Verwaltungsberechtigung. Lokale Darstellung visuell geprüft; Fixture/Screenshot unter `/tmp/media-ai-layout-review-20261003`.
- Version 0.4.4 auf der laufenden Nextcloud installiert und `occ upgrade` erfolgreich abgeschlossen. Zugang über die bereits authentifizierte SSH-Masterverbindung. Geschützte App-Backups unter `/root/media-ai-backups/20261003-layout-0.4.3` und `/root/media-ai-backups/20261003-logo-0.4.4`. Einstellungen und Metadatentabelle vor/nach beiden Updates per SHA256 identisch; Steuerdatei bytegleich, Worker online und pausiert. Installierte App-Dateien stimmen mit dem lokalen Stand überein.
- Live-Browserprüfung als Owner: Reihenfolge Status/Verarbeitung/Aktivitätslog/Einstellungen/Wartung, beide unteren Bereiche eingeklappt, Ordnerdialog erreichbar, Navigationslogo geladen, Worker online/pausiert und kein horizontaler Seitenüberlauf. Keine Konfiguration gespeichert.
- Neues monochromes SVG mit zwei Medienkarten; identisches Zeichen in der Nextcloud-Navigation und hell/dunkler GitHub-Wortmarke. SVGs geparst und Darstellung visuell geprüft. README auf 37 Zeilen gekürzt; ausführliche Controller-/Speicherhinweise nach `docs/CONTROLLER.md` verschoben.
- Das erste Layout-Update ohne Worker-Stopp erzeugte während Nextclouds kurzer Wartungsphase einen HTTP-503-Logeintrag. Beim anschließenden Logo-Update wurde der pausierte Worker für das Upgrade gestoppt und danach erfolgreich gestartet. Kein Scan oder Reset ausgelöst.

# Ergänzung – 2026-10-03, App 0.4.2

- Read-only LM-Studio-Modellprüfung für alle angemeldeten Nutzer; Konfigurations-/Verarbeitungszugriff bleibt auf Administrator und eingestellten Owner begrenzt. Anonyme Requests abgewiesen. Kein Laden eines Modells, keine Inferenz und kein Weitergeben von API-Keys oder rohen Serverantworten.
- 286 PHP-Assertions erfolgreich: 23 Contract, 121 Settings/Control, 19 Worker-Authentifizierung, 34 Media, 50 ModelConnection, 33 Admin-Berechtigungen und 6 Preferences. Neue Tests prüfen geladene Instanzen/Aliase, getrennte tatsächliche/maximale Kontextgrößen, fehlende/ungeladene Modelle, unbekannte Werte, HTTP-/Authentifizierungsfehler, Antwortgrenze, Fallbacks und identische Endpunkt-Abfragen. PHP-8.2-Syntax und JS-Syntax erfolgreich.
- Live in Nextcloud als nichtadministrativer Owner: beide Stufen verbunden und Qwen3.8-27B geladen; 114.810 Tokens tatsächlicher Kontext, 262.144 Modellmaximum, Q4_K_M, qwen35, Bildfähigkeit bestätigt. Zehn Frames bis zu 40.960 Bildtokens, 73.850 Tokens vor Prompt/Reasoning/Ausgabe übrig. Ungespeichertes Ändern auf zwanzig Frames aktualisiert die Schätzung auf 81.920/32.890; anschließend Feld auf zehn zurückgesetzt, keine Konfiguration gespeichert.
- Alle vier schreibgeschützten Promptfelder bleiben readOnly und haben wie die gesperrte Modell-ID rgb(153,153,153)/Opacity 0,7. Kein horizontaler Browserüberlauf bei 1754 px. Screenshots außerhalb des Git-Repositories dokumentiert.
- App 0.4.2 in Nextcloud installiert; geschütztes Backup von vorheriger App, State und eigenen Datenbanktabellen angelegt. Einstellungen und Metadaten vor/nach Update per SHA256 gleich. Bereits vor diesem Update waren nach einem zuvor protokollierten KI-Reset keine Metadatensätze mehr vorhanden; kein Scan oder Reset durch die Modellprüfung ausgelöst. Worker online und pausiert. Python-/SDK-Inferenzcode gegenüber 0.4.1 unverändert.

# Ergänzung – 2026-10-03, App 0.4.1

- Erneut am echten GPU-Server geprüft: SDK ohne zusätzliche Kantengrenze verarbeitet 4096² weiterhin mit rund 4096 Bildtokens. Test-Reload mit `--image-max-tokens 16384` änderte die Bildtokenzahl nicht; ursprüngliche Modell-Ladekonfiguration wiederhergestellt. Die wirksame aktuelle Grenze ist 4.194.304 Pixel bei 32-Pixel-Raster, kein universelles Modell-/LM-Studio-Maximum.
- Normale HTTP-Anfragen mit top-level oder verschachtelten Auflösungs-Overrides bleiben bei 3142 Prompttokens für 2048×2731: Felder werden ignoriert. Offizieller SDK-Zugang übernimmt die Einstellungen; neues SDK 2.0.0 mit fixierter npm-Abhängigkeitsliste, API-Token über stdin und zusätzliche CA für WSS.
- Aufbereitung optimiert auf Pixelbudget und tatsächliche Inhaltsauflösung, proportionaler vollständiger EXIF-Rahmen, Rasterauffüllung und PNG für alle Stufen. 4000×3000 wird zu 2347×1760 Inhalt auf 2368×1760 Leinwand statt 2048×1536; 31 Prozent mehr Inhalts-Pixel. Quellen/Decodergrenzen getrennt. Native OCR-Kacheln vollständig und unverkleinert; Video-Frames nutzen dieselbe Aufbereitung.
- 92 Python-Tests, 232 PHP-Assertions und drei Node-SDK-Protokolltests erfolgreich. Zusätzliche Tests: Budget/Raster/Seitenverhältnis/Ecken, verlustfreie kleine Eingänge, vollständige Originalpixelabdeckung der Budget-Kacheln und blockierter SDK-Pipe-Abbruch über denselben Dateitimer. Bestehende HTTP-/Streaming-/Cache-/Publikationsprüfungen weiterhin erfolgreich.
- Live-SDK-OCR: 4000×3000 synthetisches Original, größere Gesamtansicht und vier unverkleinerte Ausschnitte, eine Inferenz/erster Versuch, 36,848 s inklusive Aufbereitung, 20983 Prompttokens. Alle vier Codes korrekt und ohne Duplikate transkribiert.
- Live-SDK-Video: zehn gleichmäßige Frames aus sechs Sekunden, eine Inferenz/erster Versuch, 11,510 s, 3891 Prompttokens. Rotes Rechteck, grüner Kreis, blaues Dreieck und zeitliche Reihenfolge richtig; keine OCR-/Audioanfrage. Live-SDK-Zeitbudget 1 s beendet Anfrage nach 1,018 s. `stopAtLimit` statt Kontext-Trunkierung.
- Nextcloud 0.4.1 erfolgreich installiert, Backups von App/State/eigenen Datenbanktabellen geschützt angelegt. Beide Stufen eingestellt auf SDK, 4.194.304 Modellpixel und zusätzlichen 8192-Kantenschutz. OCR: 10 Prozent Mindestüberlappung, höchstens 20 gemeinsame Bilder; Video: zehn Frames, separat 512 MiB. Antwortlimit weiterhin 0, Dateibudget 600 s, Worker online/pausiert und Zeitplan aus.
- Installierter Nextcloud-Worker mit Node 18.20.4: echtes 12-MP-Juni-Foto isoliert gelesen und analysiert, 60,970 s, Vision und OCR jeweils erster Versuch, Vision 4581/OCR 20983 Prompttokens. Beide Gesamtansichten EXIF-gedreht 1760×2368 mit 1760×2347 Inhalt, OCR fünf gemeinsame Eingänge und 224 Ergebniszeichen. Quellhash/ETag unverändert, keine produktive Publikation.
- Browser bestätigt alle neuen Einstellungswerte und Owner-Schreibschutz der Modellkonfiguration, Framezahl bleibt editierbar. Kein horizontaler Überlauf bei 1754 px. Alle 45 Originalhashes/ETags und der Hash der 45 vorhandenen Metadatensätze unverändert. Kein neuer produktiver Gesamtscan.

# Ergänzung – 2026-10-03, App 0.4.0

- Auflösungsprobe am echten Qwen3.8-27B: 1536×2048 und 2048×2731 unter aktiver 2048-Kantenregel jeweils 3141 Prompttokens; 2048×2731 mit ausschließlich für die Anfrage angehobener Grenze und ignorierter Modellpräferenz 4084 Prompttokens. Keine globale LM-Studio-Änderung. Größere Uploads unter aktiver Grenze kosten gleich viel, weil sie erneut verkleinert werden; tatsächlich höhere nutzbare Auflösung kostet mehr.
- OCR: Gesamtansicht plus lückenlos abdeckende, überlappende Originalpixel-Kacheln gemeinsam in einer initialen Anfrage. Koordinaten und Zusatzprompt für Lesereihenfolge und Vermeidung von Überlappungsduplikaten. Standard maximal 20 Eingänge inklusive Übersicht; bei Überschreitung sichtbarer Fehler statt stiller Auslassung.
- Videos: native/portable Inventare, Zugriffsprüfung, gestreamter Download mit SHA256, private temporäre Datei, FFprobe/FFmpeg, zehn gleichmäßig verteilte Stichproben gemeinsam in einer initialen Vision-Anfrage, type=video, OCR ausdrücklich ausgelassen. Framezahl 1–20 konfigurierbar. Cache berücksichtigt Video-Sampling. Volltextsuche und Seitenleiste akzeptieren Videos.
- 89 Python-Tests erfolgreich, davon elf neue Prüfungen für Pixelidentität, vollständige Kachelabdeckung, Überlappung, EXIF-Drehung, Größenlimit ohne Trunkierung, byteidentische Mehrbildübertragung in genau einer echten HTTP-Anfrage, Videodecodierung, zehn Frame-Zeitpunkte, Drehung, Suchindex/Publikation/Cache und Decoder-Zeitbudget.
- 222 PHP-Assertions erfolgreich, davon 24 neue für Videozugriff/Inventar, StreamResponse ohne PHP-Vollpuffer, ETag-/Dateigrößenprüfung, Nichtmedien-Abweisung, Einstellungsvalidierung und Video-Metadaten. PHP- und JavaScript-Syntax sowie git diff --check erfolgreich.
- Echter synthetischer OCR-Test: 4000×3000 Original, ein Gesamtbild und sechs unverkleinerte 2048²-Kacheln, eine Anfrage/erster Versuch, 49,604 s, 28401 Prompttokens. ALPHA 7319, BRAVO 4826, CHARLIE 9052, DELTA 1638 vollständig und ohne Duplikate transkribiert.
- Echter synthetischer Videotest: sechs Sekunden, zehn Frames, eine Anfrage/erster Versuch, 11,637 s einschließlich Vorbereitung, 3891 Prompttokens. Alle drei Motive und Reihenfolge korrekt: rotes Rechteck, grüner Kreis, blaues Dreieck. Keine OCR- oder Audioanfrage.
- App 0.4.0 auf echter Nextcloud erfolgreich installiert; App, State und eigene MariaDB-/AppConfig-Tabellen geschützt gesichert. Vorher/nachher gleicher Hash sämtlicher 45 vorhandenen MariaDB-Metadatensätze. Alle 45 Original-SHA256/ETags über die native Downloadroute erneut identisch. Worker aktiv/pausiert, tägliche Verarbeitung aus. Im gewählten Juni-Ordner stehen 45 Bilder und derzeit keine Videos. Zusätzlicher isolierter Videolauf auf dem tatsächlichen Nextcloud-Worker mit FFmpeg 5.1.9: eine Vision-Anfrage, zehn Frames, 5,761 s, alle drei Formen/Reihenfolge korrekt, keine OCR/Publikation; Testdatei unverändert und temporärer Ordner anschließend entfernt.
- Echte Browserprüfung: neuer Titel, 600-s-Dateibudget, Framezahl 10 vom Owner änderbar, OCR-Kantenwert 2048, Überlappung 10 und Bildlimit 20 nur durch Administratoren änderbar; kein horizontaler Überlauf (1754 px).
- Kein Gesamtscan gestartet und keine künstlichen Dateien in den produktiven Dateibaum hochgeladen. Die Modelltests prüfen einfache synthetische Motive; reale OCR-Fehler, Duplikate/Lesereihenfolge und kurze Videoereignisse zwischen Stichproben bleiben mögliche Erkennungsgrenzen. Ein initialer Aufruf kann bei bestehenden Schema-/Schleifenfehlern wie bisher Korrekturanfragen auslösen. Bilder behalten getrennte Vision-/OCR-Stufen.

# Ergänzung – 2026-10-03, App 0.3.5

- Aktive LM-Studio-Konfiguration über offizielles SDK gelesen: Qwen3.8-27B/Q4_K_M, Qwen35-Architektur, Bildkante 2048, Modellpräferenz wird respektiert, Kontext 114810. Keine globale Änderung am GPU-Server.
- Reale synthetische Eckmarkierungen: 2048², 4096² sowie 4096² mit ausschließlich für diese Anfrage gesetztem 4096-Kantenwert und deaktivierter bevorzugter Modellkante. Jeweils 4181 Prompttokens (Bild plus identischer kurzer Prompt), keine höhere Bildtokenzahl. Alle vier Ecken/Farben/Beschriftungen im Ergebnis bzw. Reasoning erfasst. Laufzeiten 9,441 / 10,428 / 13,006 s. Ein Tokenlimit von 256 ausschließlich in diesem synthetischen Protokolltest, Produktions-Reasoning/Antwortlimit unverändert bei 0. Daraus folgt kein universelles Hardware-/Uploadmaximum.
- Vision/OCR in der installierten App auf 2048 längste Kante gesetzt. Anzeige errechnet 4,19 MP nur für quadratische Bilder. Original-Lade-/Decodergrenzen in separatem aufklappbarem Bereich. Seitenverhältnis, EXIF-Rotation und gesamter Rahmen erhalten; keine Vergrößerung. Neue Bildangaben in Provenienz und bestehenden Versuch-/Abschlusslogs, keine zusätzliche Start-/Ende-Logserie.
- 78 Python-Tests erfolgreich: Quer-/Hochformat, Panorama, kleine Bilder, alle vier Randfarben, EXIF-Drehung, Quellhash unverändert, getrennte Decodergrenze, Bildangaben in Logs/Metadaten sowie vollständige byteidentische Base64-Übertragung über echten HTTP-Testserver.
- 198 PHP-Assertions erfolgreich, PHP-/JavaScript-Syntax geprüft.
- Isolierter Live-Pipeline-Test auf zwei echten Juni-Bildern: 56,974 s, 2 vollständig erfolgreich, alle vier Vision-/OCR-Versuche beim ersten Versuch abgeschlossen; nichts im produktiven Suchindex überschrieben. Je Bild 4000×3000 Quellpixel, per EXIF auf Hochformat gedreht und vollständig auf 1536×2048 (3.145.728 Pixel) verkleinert. Vision 7,257/5,978 s; OCR 7,822/35,720 s.
- Live-UI: beide Felder zeigen 2048 und 4,19-MP-Hinweis, Speichergrenzen standardmäßig eingeklappt, Owner-Modellfelder bleiben schreibgeschützt, Ansicht ohne horizontalen Überlauf bei 1754×991. Keine Android-Geräteprüfung.
- Installation 0.3.5 erfolgreich, Backup der vorherigen App und gespeicherten Einstellungen geschützt angelegt. Alle 45 Originalhashes/ETags unverändert, 45 alte Ergebnisse erhalten. Worker online/pausiert, tägliche Automation aus. Geänderte OCR-Bildkante wird beim nächsten bewussten Start neu analysiert; kein unbeauftragter Gesamtscan.
- Das Backend meldet keine interne Encoderauflösung zurück. Übertragung des vollständigen Rahmens und Ende-zu-Ende-Ecktest ersetzen keine Garantie für die Erkennung jeder kleinen Schrift oder jedes Details.

# Ergänzung – 2026-10-03, App 0.3.4

- Standard- und Korrekturprompts stammen für Anzeige/Worker aus einer gemeinsamen JSON-Datei; bisherige Texte byte-identisch.
- Promptfelder zeigen den Standard; Rücksetzknopf, Status Standard/Eigener Prompt, lesbare Owner-Felder und englische Beschriftungen.
- Isolierter Admin-Browsertest bestätigt: unveränderter Standard bleibt leerer Override, eigener Prompt bleibt erhalten, Rücksetzen und Speichern aktivieren wieder Standard.
- Integrierte App entfernt Dateilimit und Gesamtzeitbudget vollständig; der Worker setzt alte gespeicherte Grenzen nicht mehr ein.
- Gemeinsames Bildbudget standardmäßig 600 s (0 = unbegrenzt): Download, Vorverarbeitung, Vision/OCR und alle Korrekturen. Sichtbarer Timeout-Fehler, Weiterarbeit am nächsten Bild, keine Publikation unvollständiger Antworten.
- 73 Python-Tests erfolgreich. Echter HTTP-Testserver mit langsamem fortlaufendem Stream wird innerhalb des Bildbudgets getrennt; erster Bildversuch als Timeout, zweites Bild erfolgreich publiziert, kein zusätzlicher Retry nach Ablauf. Budget wird zwischen Stufen nicht erneuert und kann mit 0 deaktiviert werden.
- 196 PHP-Assertions erfolgreich; öffentliche Promptanzeige, eigene Overrides, Owner-Rechte, Bildbudget-Grenzen und Entfernen alter Laufgrenzen geprüft.
- Unbegrenzter Regressionstest verarbeitet 105 Bilder über das frühere Standardlimit hinaus. Kontrolllauf auf echter Nextcloud: 0,094 s, keine neue Inferenz/Publikation; alle 45 bisherigen Ergebnisse und Originale unverändert.
- Echte LM-Studio-Probe: 1-s-Bildbudget beendet den Aufruf nach 1,001 s, nur ein Versuch; der folgende Aufruf endet regulär nach 11,332 s. Isoliert, nichts publiziert.
- Echte Nextcloud-Speicherung unveränderter Standards hält beide Prompt-Overrides leer; Ordner/Zeitplan bleiben erhalten, alte Laufgrenzen verschwunden.
- CPU-Vorverarbeitung prüft das Budget an sicheren Prüfpunkten; keine unsichere Unterbrechung nativer Bilddecoder. GPU-Stopp nicht separat instrumentiert.

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
