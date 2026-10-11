# Zwei unabhängige Verarbeitungsschritte

**Beschreibung und Texterkennung** erzeugt Beschreibung, Tags, Objekte und OCR (bei Videos ohne OCR). **Visuelle Bildsuche** erzeugt ausschließlich Bildvektoren; Beschreibungen werden weder vorausgesetzt noch eingebettet. Beide Schalter betreffen neue Verarbeitung. Bereits gespeicherte Texte bleiben durchsuchbar.

Die Galerie vereinigt Texttreffer und alle nach Ähnlichkeit sortierten Bildtreffer innerhalb derselben Galerie, desselben Kontos, desselben Medientyps und aktueller Dateistände. Kein UND-Gate und keine feste Ähnlichkeitsschwelle. Die Rangfusion nutzt gleiche Gewichte und RRF k=60. Alle verfügbaren Kandidaten bleiben nachladbar; eine vollständige semantische Trefferabdeckung kann das Modell nicht garantieren. Texttreffer erscheinen vor der Modellanfrage; die Bildsuche ergänzt anschließend die gemeinsame Rangfolge. Bei einem Ausfall bleiben Textsuche und Dateinamen sofort nutzbar, mit sichtbarer Meldung. Suchanfragen benutzen denselben aktiven Embedding-Dienst wie die Indexierung; die große Beschreibungspipeline läuft nicht bei der Suche. Offline bleibt die vorhandene lokale Dateisuche erhalten.

Fotos erhalten einen Vektor. Videos werden gleichmäßig anhand echter Frame-Zeitstempel abgetastet; jeder Frame erhält einen Vektor. Die am besten passende Szene bestimmt den Videorang, nicht der Durchschnitt. Stichproben können kurze Szenen verpassen; Audio ist nicht enthalten.

## Gemma auf der lokalen CPU

Unter **Embedding-Dienst** stehen **Gemma 2 · sparsam (CPU)**, **Gemma 2 · Original (CPU)** und **Externer Embedding-Server** zur Auswahl. Bei einem externen Server werden Modellfamilie, Modell-ID, API-Adresse und API-Key angezeigt. Es wird dort nichts installiert.

Ein fehlendes lokales Gemma wird **erst nach einem Klick auf „Gemma installieren“** heruntergeladen. Auswählen, Speichern, Aktivieren und Starten eines Medienlaufs starten allein keinen Modell-Download. Bei ungespeicherten Einstellungen heißt der Button **„Speichern und Gemma installieren“** und speichert die Auswahl vor der Installationsanforderung. Eine Installation kann auch bei ausgeschalteter visueller Bildsuche stattfinden. Der Button erscheint nur, solange die ausgewählte verwaltete Gemma-Installation fehlt; danach zeigt die Oberfläche „installiert“ bzw. „bereit“ und einen roten Papierkorb zum Deinstallieren.

Ein installierter, laufender NextGallery-Worker übernimmt die Einrichtung außerhalb des Web-Requests, auch wenn die Medienverarbeitung pausiert ist. Nur Nextcloud-Administratoren dürfen sie anfordern. Die Installationsanforderung ist vom Start-/Pause-Zustand der Medienpipeline getrennt und startet keine Medienanalyse. Ein Nextcloud-App-Download allein installiert keinen Systemdienst: Der gebündelte Worker muss einmalig mit `pipeline/scripts/install-worker.sh` installiert sein. Vorhandene Worker bei einem App-Upgrade neu starten; ohne Installationsunterstützung ist der Button deaktiviert und ein entsprechender Hinweis sichtbar.

Der Worker erstellt im privaten Zustandspfad `.nextgallery_ai/embedding-local/` eine eigene Python-Umgebung, installiert die gebündelten Anforderungen aus `worker/requirements-embeddings.txt` und lädt einmalig das Gemma-Modell (387.710.976 Bytes, ca. 388 MB) herunter. Die Python-Pakete benötigen zusätzlich Speicher und Internetzugang zu PyPI. Es werden keine Pakete in die System-Python-Umgebung installiert. Der festgelegte Modell-Download verwendet HTTPS; ein fertiges Modell wird erst nach Größen- und SHA-256-Prüfung atomar veröffentlicht.

In den zentralen Admin-Einstellungen erscheinen Einrichtungsstatus, Downloadfortschritt und Fehler. Benutzer sehen ausschließlich ihre Läufe, Dateien und KI-Daten. Fehlgeschlagene oder abgebrochene Downloads werden nicht als fertige Modelle verwendet. Der Worker versucht fehlgeschlagene Einrichtungen nach fünf Minuten erneut; Beschreibung/OCR und Textsuche können währenddessen weiterlaufen. Ein bereits angeforderter Medienlauf bearbeitet die Embeddings nach abgeschlossener Einrichtung, sofern er nicht pausiert wurde. Ein Wechsel auf extern oder manuelle Einrichtung beendet die laufende Einrichtung beim nächsten Worker-Durchlauf und verwirft die offene Installationsanforderung. Das Ausschalten der visuellen Bildsuche lässt eine ausdrücklich angeforderte Installation fertig werden. Installierte Dateien bleiben für einen späteren Wechsel zurück erhalten.

Das Modellpaket kommt aus dem offiziellen LiteRT-Community-Repository:

- Repository: `litert-community/embeddinggemma-2-text-vision-440m-litert-lm`
- Festgelegter Stand: `e301f74d5551b0c2641bd5cb4652a76239d5c5f8`
- Datei: `embeddinggemma-2-text-vision-440m.litertlm`
- SHA-256: `92dcbea108899e5d6e30d919b0744f90d9967e80c67a4ab5503ac16d54f62eb0`
- Download: https://huggingface.co/litert-community/embeddinggemma-2-text-vision-440m-litert-lm/resolve/e301f74d5551b0c2641bd5cb4652a76239d5c5f8/embeddinggemma-2-text-vision-440m.litertlm

Bei aktivierter visueller Bildsuche startet der Worker nach der Einrichtung den Dienst auf `127.0.0.1:9379` und hält ihn auch bei pausierter Medienverarbeitung für Suchanfragen aktiv. Das Modell bleibt geladen und wird nicht je Bild neu geladen. Nach einem Neustart werden Modell und Python-Umgebung weiterverwendet. Setup-Details stehen in der privaten Datei `embedding-local/setup.log`; der Webstatus enthält keine Python-Ausgaben, Zugangsdaten oder Medieninhalte. Die Installation setzt Python mit venv/ensurepip-Unterstützung und passende Binärpakete für den Host voraus. Bei einem inkompatiblen Host wird ein sichtbarer Einrichtungsfehler gemeldet.

**Manuelle Einrichtung:** Die Weboberfläche bietet die zwei verwalteten CPU-Varianten und einen externen Embedding-Server. Eigene Qwen- oder Gemma-Installationen werden als externer Dienst eingerichtet, gegebenenfalls auf demselben Rechner. Bereits gespeicherte abweichende lokale Modellpfade werden für bestehende Installationen weiter unterstützt; Modellpakete und ausführbarer Qwen-Code werden dabei nicht automatisch installiert.


### Varianten und Hardware

| Lokale Auswahl | Berechnung / Bildbudget | Download des Modells | Empfehlung zusätzlich zu Nextcloud |
| --- | --- | --- | --- |
| Sparsam | LiteRT, INT4/INT8, 140 Bild-Tokens | ca. 388 MB | 4 CPU-Kerne, 2 GB freier RAM, 2 GB freier Speicher |
| Original, mehr Bilddetails | Ursprüngliche BF16-Gewichte, CPU-Berechnung in FP32, 280 Bild-Tokens | ca. 1,52 GB | 4 CPU-Kerne, 6 GB freier RAM, 5 GB freier Speicher |

Beide verwalteten Varianten laufen auf der CPU; keine GPU erforderlich. Die Angaben sind Empfehlungen mit Reserve, keine gemessenen universellen Mindestgrenzen. Binärpakete für Python und den Host müssen verfügbar sein. Bei einem externen Dienst hängt die Hardware vom dort gewählten Modell ab; auf Nextcloud läuft dafür keine Embedding-Inferenz.

Die Originalvariante nutzt `google/embeddinggemma-2` am festgelegten Stand `914f7f89142e33e77833254d9c9b90c3cef7303b`. Dateiliste, Größen und SHA-256-Werte stehen in `worker/embedding-full-model.json`. Es werden ausschließlich Gewichte, Tokenizer- und Konfigurationsdaten geladen, kein ausführbarer Modellcode. `trust_remote_code=False` und `local_files_only=True` verhindern Code- und Modelldownloads bei der Inferenz. Das Audiomodul bleibt ausgeschaltet. Die Laufzeit aus `worker/requirements-embeddings-full.txt` nutzt CPU-Pakete von PyTorch und PyPI; der private Pfad ist `.nextgallery_ai/embedding-full/`.

Beide Installationen können nebeneinander gespeichert bleiben. Modellwechsel gelten für alle Benutzer. Unter **Server-Einstellungen → Benutzerläufe** kann der Administrator die Embedding-Läufe aller Benutzer pausieren; die aktuelle Datei wird abgeschlossen, vorgemerkte Starts werden entfernt und neue sowie automatische Starts bleiben bis zur Freigabe gesperrt. Embedding-Verarbeitung und Embedding-Modelländerungen teilen eine serverseitige Sperre, sodass kein alter Lauf während des Wechsels Vektoren veröffentlichen kann.

Beim Speichern eines geänderten Embedding-Modells erscheint **Abbrechen**, **Alle Vektoren löschen** oder **Vektoren behalten**. Die Bestätigung ist an die aktuelle Konfiguration gebunden. Reset löscht sofort die Vektoren aller Benutzer aus der Nextcloud-Datenbank und sämtliche Zeilen der lokalen `embeddings.sqlite3`-Verarbeitungsspeicher, auch außerhalb der aktuellen Ordnerauswahl. Beschreibungen, OCR, Galerieeinstellungen und Originaldateien bleiben erhalten. Optional werden die ausgewählten Ordner betroffener Konten automatisch für einen reinen Embedding-Lauf eingereiht; dazu ist keine zweite Benutzerbestätigung erforderlich. Ohne diese Option werden betroffene Starts pausiert und Benutzer starten später selbst.

**Behalten** wird nur freigegeben, wenn der neue erreichbare Dienst denselben tatsächlichen Vektorraum und dieselbe Dimension wie alle gespeicherten Vektoren und Caches nachweist. Dies eignet sich beispielsweise für einen Hostwechsel mit identischen Gewichten, Laufzeit, Präzision und Vorverarbeitung. Gleicher Modellname oder gleiche Dimension allein genügt nicht. Unbekannte bzw. inkompatible Räume erfordern einen Reset. Die Prüfung wird beim Speichern erneut serverseitig durchgeführt. Stille Modell-/Laufzeitänderungen am externen Dienst werden weiterhin erkannt und stoppen einen Lauf zur Bestätigung; externe Dienste deshalb nur über den bestätigten Admin-Modellwechsel aktualisieren. Eine bessere Trefferquote der Originalvariante ist ohne passenden Benchmark nicht zugesichert.


Am 10. Oktober wurde die Originalvariante mit synthetischen Bildern auf der Entwicklungs-VM (Intel i5-12400, 4 CPU-Threads) gemessen: ca. 3 GB maximaler Prozess-RAM, ca. 3,5 s pro Testbild und 65 ms für den ersten Suchtext. Das ist kein Performance-Nachweis für den produktiven Nextcloud-Host.

### Deinstallation

Bei einer installierten Variante erscheint direkt unter der Auswahl ein roter Papierkorb. Eine Bestätigung nennt die Folgen. Nur Administratoren dürfen die Deinstallation anfordern; der aktualisierte Worker muss online und die Medienverarbeitung pausiert sein. Er beendet den lokalen Dienst und entfernt ausschließlich die feste Modell-/Laufzeit-Unterstruktur der gewählten Variante. Eine andere installierte Variante, Originalmedien, Beschreibungen, OCR und gespeicherte Vektoren bleiben erhalten. Nach dem Entfernen ist die semantische Suche mit dieser Variante bis zur erneuten Installation nicht verfügbar; die Textsuche bleibt nutzbar. Es startet keine automatische Neuinstallation. Externe und manuell eingerichtete Modelle werden nicht gelöscht.

## Externer PC

Derselbe Dienst kann unabhängig von LM Studio gestartet werden. Python-Laufzeit und Gemma-Modellpaket müssen dort vorhanden sein. Gemma nutzt derzeit die CPU; Qwen wählt bei verfügbarem CUDA die GPU.

```sh
# NEXTGALLERY_EMBEDDING_KEY vorher als private Umgebungsvariable setzen.
python embedding_server.py --host 0.0.0.0 --port 9379 \
  --model-path /path/to/embeddinggemma-2-text-vision-440m.litertlm
```

In der Verwaltung **externer PC**, **multimodaler Embedding-Dienst**, `http://PC-IP:9379/v1` und denselben API-Key einstellen. Außerhalb eines vertrauenswürdigen lokalen Netzes TLS verwenden. Weiterleitung von HTTP-Anfragen wird nicht verfolgt. Den externen Dienst startet und beendet NextGallery nicht.

## Qwen3-VL-Embedding

Separat die offizielle Implementierung und deren Anforderungen aus https://github.com/QwenLM/Qwen3-VL-Embedding installieren. Keine automatische Installation von ausführbarem Quellcode im Web-Request. Es ist **Qwen3-VL-Embedding**, nicht das reine Qwen3-Embedding-Modell nötig.

```sh
python embedding_server.py --family qwen3_vl --model Qwen/Qwen3-VL-Embedding-2B \
  --model-path /path/to/Qwen3-VL-Embedding-2B \
  --model-revision PINNED_MODEL_REVISION --qwen-source /path/to/Qwen3-VL-Embedding
```

Entsprechende lokale Pfade oder externen Dienst konfigurieren. Eine Live-Inferenz mit Qwen wurde in dieser Änderung nicht nachgewiesen; Gemma wurde tatsächlich mit synthetischen Bildern, Frames und Suchtext getestet.

## Modellwechsel und Speicherung

Vektoren liegen als normalisierte Float32-Werte in einer eigenen Nextcloud-Tabelle. Konto, Datei-ETag, Modell-/Runtime-Fingerabdruck, Auflösung, Frame-Anzahl und Video-Frame werden berücksichtigt. Verschiedene Vektorräume werden nie vermischt. Ein Wechsel von CPU auf einen externen Dienst kann nur exakt kompatible Vektoren weiterverwenden. Alte Beschreibungen bleiben unverändert. Ein ausdrücklicher Gesamtrücksatz löscht auch Embeddings, nie Originaldateien. Nach einem Publikationsausfall werden berechnete Vektoren weiterverwendet.

Die aktuelle Suche vergleicht exakt alle berechtigten Vektoren auf dem Nextcloud-Host. Es gibt noch keinen ANN-Index; CPU- und DB-Aufwand wachsen mit dem Bestand und der Zahl der Video-Frames. Die Embedding-Laufzeit ist vom eigentlichen Vektorvergleich getrennt.

## Nachweis für LM Studio, 10. Oktober 2026

Am tatsächlich laufenden Server `10.1.10.78:1234` war `text-embedding-embeddinggemma-2` (Unsloth GGUF Q8_0, Kontext 8192) geladen. Text lieferte 768 Werte. Bild- und Video-Objekte am `/v1/embeddings`-Endpunkt wurden mit HTTP 400 zurückgewiesen. Zusätzliche `images`-Felder wurden ignoriert: roter Kreis und blaues Quadrat lieferten identische Vektoren. Diese API ist daher für diese Bild-Pipeline ungeeignet. Das sagt nichts über die grundsätzlichen multimodalen Fähigkeiten der Modellgewichte aus.

## Suchablauf und Speicherung

1. Die Galerie fragt zuerst ausschließlich die Textsuche ab (`phase=text`), ohne den Embedding-Dienst aufzurufen, und zeigt deren Treffer sofort anklickbar an. Sie durchsucht innerhalb der angemeldeten Benutzer- und Galerieauswahl Dateinamen und Pfade sowie aktuelle Beschreibungen, Tags, Objekte und OCR. Suchwörter sind mit ODER verknüpft; Dateinamen und strukturierte Begriffe werden bei der Sortierung stärker gewichtet.
2. Ist die Bildsuche aktiviert und indexiert, startet der Browser nach der Textanzeige eine unabhängige Hintergrundanfrage (`phase=hybrid`). Nextcloud sendet nur den Suchtext zum konfigurierten Embedding-Dienst: lokal auf dem Nextcloud-Host oder zum externen Server. Dieser erzeugt einen Suchvektor mit demselben Modell wie die gespeicherten Bildvektoren. Die Beschreibungspipeline wird nicht erneut ausgeführt.
3. Nextcloud vergleicht normalisierte Vektoren exakt per Skalarprodukt (entspricht Cosinus-Ähnlichkeit). Bei Videos gilt der höchste Standbildwert. Nur passende Modellräume, aktuelle Dateiversionen und derselbe Benutzer/Galerieumfang werden berücksichtigt.
4. Die Vereinigung aus Text- und Bildtreffern wird per gleich gewichteter Rangfusion sortiert. Es gibt keine feste Ähnlichkeitsschwelle; auch weiter hinten liegende Kandidaten bleiben nachladbar. Das begünstigt Recall, kann aber unpassende Vorschläge enthalten. Es ist keine Garantie, dass ein Modell jedes gesuchte Motiv erkennt. Bei einem ausgefallenen Embedding-Dienst bleiben die bereits angezeigten Texttreffer verfügbar. Währenddessen erscheint nur „Bildsuche läuft …“. Die beiden Dienstaufrufe sind jeweils auf zehn Sekunden begrenzt; nach insgesamt 22 Sekunden beendet auch der Browser die Hintergrundanfrage. Fehler, ausgeschaltete Server und Zeitüberschreitungen ersetzen die Texttreffer nicht. Neue Suchanfragen, Galerie- und Ansichtswechsel brechen die alte Browseranfrage ab; zusätzlich werden veraltete Antworten verworfen. Bereits serverseitig gestartete Berechnungen können noch bis zu ihrem Timeout weiterlaufen. Die vorhandene Rangfusion und Seitenauswahl bleiben erhalten; die Reihenfolge kann sich bei Eintreffen der Bildtreffer aktualisieren, vorhandene Schaltflächen werden wiederverwendet. Clients ohne `phase` bekommen weiterhin die vollständige hybride Antwort.

Die Suchvektoren und Bildvektoren liegen nicht dauerhaft auf dem externen Rechner. Die veröffentlichten Bild-/Standbildvektoren speichert Nextcloud in seiner normalen Datenbank in `<Tabellenpräfix>nextgallery_ai_vectors`, getrennt nach Benutzer, Datei, Dateiversion, Modellraum und Videostandbild. Beim bestehenden Server ist das `oc_nextgallery_ai_vectors` in MariaDB. Eine gesonderte Vektordatenbank wie Qdrant ist nicht eingerichtet; die aktuelle Suche führt einen exakten Vergleich der passenden gespeicherten Vektoren aus. Sehr große Bestände können später einen speziellen Vektorindex erfordern; eine Produktionslatenz wurde hier nicht gemessen.

Der Worker besitzt zusätzlich einen privaten Verarbeitungsspeicher `embeddings.sqlite3` für Vektoren, Wiederholungen, Fehler und Veröffentlichungsstatus. Das ursprüngliche Konto nutzt `/var/www/nextcloud-data/.nextgallery_ai/embeddings.sqlite3`; andere Konten liegen unter `.nextgallery_ai/accounts/<SHA256-Benutzer-ID>/embeddings.sqlite3`. Der zentrale bestätigte Reset entfernt sowohl die veröffentlichten Datenbankvektoren als auch alle Zeilen dieser Caches. Suchtextvektoren werden für die Anfrage erzeugt und nicht dauerhaft als Benutzerdateien gespeichert. Modellgewichte und ausdrücklich angelegte Sicherungen werden beim Vektorreset nicht entfernt.

Die Nextcloud-weite Suchoberfläche nutzt weiterhin ihre vorhandenen Suchanbieter. Die beschriebene Rangfusion läuft in der NextGallery-Galeriesuche; der NextGallery-KI-Anbieter in der globalen Nextcloud-Suche durchsucht KI-Texte.

## Native Android-Suche (Entwicklungsstand)

Die Android-Suche öffnet sich mit **Offline**. Der Cloud-/Offline-Schieber gilt nur für die geöffnete Suche und wird nicht je Galerie oder dauerhaft gespeichert. Offline durchsucht ausschließlich die vorbereitete lokale SQLite-Dateiliste, einschließlich heruntergeladener KI-Texte, ohne Suchanfragen an den Server. Das Info-Symbol erklärt beide Modi. Auch mit gespeichertem Katalog kann ausdrücklich **Online** gewählt werden.

Die neue authentifizierte OCS-Schnittstelle `POST /ocs/v2.php/apps/nextgallery/api/v1/search?format=json` nimmt `query`, `roots` (maximal 32), `kind`, `period`, `mode`, `timezone`, `offset`, `limit` und `phase` (`text` / `hybrid`) entgegen. Das Konto stammt ausschließlich aus der Anmeldung, nie aus einem übermittelten Benutzerfeld. Die Anfrage muss `OCS-APIRequest: true` setzen. Der Client benötigt nur sein bestehendes Nextcloud-App-Passwort; Embedding-Dienst-Zugangsdaten bleiben auf dem Server.

Die Ordner werden im Dateibaum des angemeldeten Benutzers geprüft. Die Suche verwendet Nextclouds Dateimetadatenindex und aktuelle KI-/Vektordatensätze innerhalb dieser Ordner, unabhängig vom Bestand oder der aktiven Galerie der Weboberfläche. Keine neue Galerie, kein Scanlauf, kein Öffnen der Originale und keine neue Medienanalyse wird durch eine Suchanfrage gestartet. Aktuelle Dateirechte, Pfade und ETags werden berücksichtigt. `mode` und `timezone` sorgen für dieselbe Datumsfilterung wie in Android. Dateinamen, Pfade, vorhandene serverseitige Metadaten und KI-Texte liefern Texttreffer. Die Bildsuche nutzt denselben konfigurierten Dienst und dieselbe gleichgewichtete Rangfusion wie die Webgalerie; Videos verwenden das stärkste passende Standbild.

Android zeigt die Textphase zuerst, ergänzt dann die hybride Rangfolge und behält Texttreffer bei einem Embedding-Ausfall. Suchwechsel und Moduswechsel brechen laufende HTTP-Anfragen ab; alte Antworten können neue Ergebnisse nicht überschreiben. Online-Ergebnisse und KI-Texte aus Suchantworten bleiben in der Sitzung und werden nicht in Offline-Kataloge geschrieben. Bei einer nicht erreichbaren Nextcloud fällt Android auf die lokale Suche zurück; eine spätere Verbindung aktiviert Online nicht selbstständig. Ohne die neue OCS-Schnittstelle bleibt die bewusst ausgewählte Online-Datei-/Ordnersuche über WebDAV verfügbar und wird entsprechend gekennzeichnet.

Die native Schnittstelle durchsucht den berechtigten Bestand exakt und berechnet eine Suchanfrage bei jeder angeforderten hybriden Seite. Es gibt weiterhin keinen ANN-Index und keine gemessene Produktionslatenz für große Sammlungen. Es werden keine Modelle oder Vektoren auf Android heruntergeladen.

Eine Seite enthält maximal die angeforderte Zahl an Einträgen. Umfangreiche OCR-/Videometadaten können die Seite zusätzlich verkürzen; vollständige Datensätze bleiben erhalten und `next` zeigt auf den nächsten noch nicht übertragenen Treffer. Android verwendet diesen Cursor, statt eine feste Anzahl Einträge pro Seite vorauszusetzen.
