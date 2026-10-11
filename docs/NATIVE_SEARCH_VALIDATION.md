# Native Android-Suche: lokale Prüfung vom 11.10.2026

Basis: Server `b8c0540`, Android `b2d3015`. Die Änderungen wurden weder auf Produktion installiert noch veröffentlicht. Server-Version 0.4.10 und Android-Version 0.6.6 / Code 19 bleiben unverändert.

## Serverprüfung

Isolierte echte Nextcloud-Instanzen, ausschließlich synthetische Medien und Testkonten:

| Fall | Nextcloud / Datenbank | Ergebnis |
| --- | --- | --- |
| Neuinstallation | 35.0.1 / SQLite | 27 HTTP-Prüfungen, 30 native Suchverträge einschließlich inkompatiblem Vektorraum, Unicode-Metadaten, Zeitzonenfilter und großer OCR-Antworten, bestehende Integrationsverträge bestanden |
| Umbenennung der bisherigen Apps | 32.0.5 / MariaDB | 27 HTTP-Prüfungen, 24 native Suchverträge, bestehende Integrationsverträge bestanden |
| Upgrade der bisherigen Apps | 32.0.5 / MariaDB | 27 HTTP-Prüfungen, 30 native Suchverträge einschließlich inkompatiblem Vektorraum, Unicode-Metadaten, Zeitzonenfilter und großer OCR-Antworten, bestehende Integrationsverträge bestanden |

Abgedeckt sind die sechs Textfelder, Textphase ohne Modellaufruf, hybride Treffer ohne Textübereinstimmung, maximaler Videoframe-Score, aktuelle ETags, Modellfehler und Modellwechsel, Pagination über 65 Medien, Medienart, Ordner außerhalb der Webgalerie, leere/überlappende/unzulässige Ordner, fremdes Konto und unveränderte Webgalerie-Einstellungen. Die HTTP-Prüfungen verwenden echte Anmeldung und prüfen außerdem den erforderlichen OCS-Header und HTTP-Fehler für ungültige Ordner.

Die letzten SQLite-/MariaDB-Läufe ergänzen gespeicherte Nextcloud-Metadaten mit Umlauten, einen Datumssprung an der Monatsgrenze zwischen UTC und Europe/Vienna sowie fünf vollständige OCR-Ergebnisse mit jeweils 480.000 Zeichen. Letztere werden auf mehrere Seiten verteilt und ohne fehlende oder doppelte Treffer nachgeladen. Die Umbenennungsprüfung lief vor diesen letzten Ergänzungen; ihre 24 Verträge sind entsprechend separat ausgewiesen.

PHP-Syntaxprüfung und alle elf bestehenden Webtests bestanden. Das installierbare Archiv enthält die neue Route, den Controller und den Dienst. Aus dem entpackten Archiv wurden Worker-Einrichtung, LM-Studio-SDK-Import und strukturierter Transportfehler geprüft.

Lokale Belege: `artifacts/native-search-{fresh35,rename32,upgrade32}.txt`, `artifacts/native-search-php-lint.txt`, `artifacts/native-search-web-regression.txt`, `artifacts/native-search-package/manifest.json`. Die Testinstanzen werden vom Integrationsprogramm entfernt.

Der letzte MariaDB-Lauf wurde zur möglichen Diagnose mit `--keep` gestartet. Nach seinem erfolgreichen Abschluss wurden seine drei Container und sein eigenes Netzwerk ausdrücklich entfernt. Auch die beiden gestarteten Android-Emulatoren sind nach bestätigter Datenwiederherstellung beendet. Die abschließende Bestandsprüfung zeigt keine verbleibende NextGallery-Testinstanz.

## Grenzen

Das Embedding-Ergebnis in den Vertragsprüfungen ist synthetisch. Es wurde weder ein Produktivmodell auf privaten Medien getestet noch die Geschwindigkeit großer Produktionsbestände gemessen. Die vorhandene Dienstkonfiguration wird unverändert verwendet; keine Server-Zugangsdaten gelangen an Android. Android-Emulatorprüfungen sind im zugehörigen App-Repository dokumentiert.

Parallel entstandene Änderungen an `worker/embedding_setup.py` und `pipeline/tests/test_embedding_setup.py` wurden nicht bearbeitet oder als Teil dieser Suchänderung bewertet. Die Prüfungen hier betreffen die Suchschnittstelle; die separate Installer-Arbeit hat ihre eigenen Validierungsgrenzen.
