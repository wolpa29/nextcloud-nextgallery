# Lokale Validierung – 2026-10-02

**Grundgerüst vorhanden; keine App-Installation/Aktivierung auf 10.1.10.186. Echter GPU-Pilot auf dem bereits laufenden LM-Studio-Endpunkt erfolgreich; ausschließlich künstliche Testbilder verarbeitet.**

| Prüfung | Ergebnis |
|---|---|
| Python 3.13.7, Pillow 11.3.0; `python3 -m unittest discover -s tests -q` | 31 Tests erfolgreich, letzter Lauf 0,664 s. |
| Python- und Beispiel-JSON-Syntax | Gültig. |
| Nextcloud-App `info.xml` | Wohlgeformtes XML; keine vollständige Nextcloud-App-Store/XSD-Validierung behauptet. |
| Sieben PHP-Dateien, `php -l` in isoliertem PHP-8.2-CLI-Container | Alle ohne Syntaxfehler. |
| `tests/php_contract.php` im selben Container ohne Netzwerk | 21 Assertions erfolgreich; Nextcloud-Schnittstellen hier durch kleine Test-Doubles ersetzt. |
| systemd-Vorlagen | `systemd-analyze verify` mit temporär auf vorhandenes Python umgeschriebenen Pfaden: Exit 0. Keine Units installiert oder aktiviert. Originale `/opt`-Pfade sind Installationsplatzhalter. |
| Öffentliche Nextcloud-CA-Datei | X.509 lesbar, CN `Paul Nextcloud Local CA`, Gültigkeit 2026-09-30 bis 2036-09-27. Live-Zertifikatskette nicht erneut abgefragt. |

## Welche Fehlerfälle wurden geprüft?

- Neuer Durchlauf und Suche nach Beschreibung sowie wörtlichem OCR-Text.
- Unveränderte Datei: kein zweiter Download und keine zweite Inferenz.
- Geänderte Bytes/Version: erneute Analyse; neue Modellrevision: nur betroffene Stufe neu.
- Umbenennung mit gleicher Datei-ID: aktualisierter Suchpfad ohne Inferenz.
- Entfernte Dateien: nicht mehr lokal auffindbar, gespeicherter Verlauf bleibt erhalten.
- Bytegleiche Duplikate: zwei Dateitreffer, nur ein Aufruf je Modellstufe.
- Teilfehler nach erfolgreicher Vision-Stufe: OCR wird erneut versucht, Vision aus Cache.
- GPU offline: keine Inventarisierung und kein Bilddownload; fertige Ergebnisse bleiben bestehen.
- Unvollständiges WebDAV-Inventar: keine vorhandenen Dateien als verschwunden markieren.
- Quelle während Analyse geändert: kein fertiger Suchtreffer für diese Version.
- Überlappende Controller gesperrt, Sperre nach Ende freigegeben.
- Nextcloud-Publishing ausgefallen: lokales Ergebnis bleibt fertig; Übermittlung auch ohne GPU nachholbar und idempotent.
- Pilot-Paketgröße und Bildgrößenlimits eingehalten.
- EXIF ausgelesen, Bildkopien verkleinert, Originalbytes unverändert.
- Symlinks ausgeschlossen, ersetzte Dateien erkannt, Hardlinks als getrennte Pfade erfasst.
- Echte lokale HTTP-Testserver: Modellliste, Bild-Data-URL, API-Key, getrennte OCR-Antwort und OCS-POST mit App-Authentifizierung.
- Abgeschnittene Modellantwort, ungültiges JSON-Schema, Redirect, übergroße Antwort, ungültiger WebDAV-Pfad und API-Konflikt verworfen.
- PHP: Zugriff auf unlesbare/nicht vorhandene Bilder, nicht angemeldete Benutzer, fehlender OCS-Header, ungültiger Hash und geänderter ETag verweigert; abgelehnte Anfragen schreiben keine Daten.
- PHP: Suchtreffer mit geändertem ETag oder entzogenem Lesezugriff unterdrückt; Metadaten nur über gebundene Parameter in eigener Tabelle geschrieben.

PHP-Testimage: `php:8.2-cli`, Digest `sha256:8c0ac7363ba26de6279a0614a9072277e3c86f90d11447002bdf74ad7db9aac3`.

## Noch nicht live validiert

GPU-PC inzwischen erreichbar: API-Anbindung und Modell `qwen/qwen3.8-27b` in Q4_K_M mit vier künstlichen Dateien erfolgreich live geprüft, Details in `docs/LIVE_PILOT.md`. VRAM-Verbrauch, repräsentative Foto-/OCR-Qualität und Durchsatz bei echten großen Bildern noch offen. HEIC/AVIF/seltene Bildformate mit realen Dateien noch nicht geprüft. Keine System-/Modellkonfiguration auf dem GPU-PC geändert.

Nextcloud-App nicht aktiviert: echte Routing-/Authentifizierungs-Middleware, MariaDB-Migration/Volltextindex, Server-Dependency-Injection und Browser-/Android-Suche müssen auf dem tatsächlichen Nextcloud-System geprüft werden. Die PHP-Prüfungen ersetzen diesen Integrationstest nicht.

Vor dem Gesamtlauf: 100 reale Bilder verschiedener Typen testen, Qualität manuell prüfen, Laufzeit/VRAM/Controllerlast und zusätzlichen Indexplatz messen. Danach Tagespaket und Laufzeitbudget festlegen. Originaldateien bleiben in allen vorgesehenen Stufen nur lesbar.
