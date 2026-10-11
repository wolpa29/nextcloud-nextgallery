# Fünf Suchverfahren auf denselben künstlichen Bildern

Dieser Ordner ergänzt den älteren, rein textbasierten Benchmark im übergeordneten Verzeichnis. Die älteren Resultate bleiben erhalten.

- `REPORT.md`: Ergebnisse, Beispiele, Laufzeit und genaue Aussagegrenzen.
- `compare.html`: interaktiver Vergleich aller fünf Verfahren; `thumbs/` und `contact-sheet.jpg` gehören dazu.
- `fixtures.json`: vor der Modellanalyse festgelegte Suchanfragen und Solltreffer.
- `dataset.json`: derselbe Bestand mit tatsächlich durch die aktuelle Pipeline erzeugten Beschreibungen, Tags, Objekten und OCR.
- `results.json`: vollständige Trefferlisten, Ähnlichkeitswerte, Metriken und Zeiten.
- `protocol.json`, `provenance.json`, `model-api.json`: vorab festgelegtes Verfahren, unveränderter Quellstand, Bildprüfsummen und Modellrevisionen.
- `images/`: sechs mit dem eingebauten imagegen-Werkzeug erzeugte künstliche Fotos sowie künstliche Dokumente und Grafiken. Prompts stehen in `imagegen-prompts.json`.

## Retrieval wiederholen

Die isolierte Python-Umgebung befindet sich unter `../.venv`. `requirements.lock.txt` enthält ihren Stand. Docker wird für eine eigene kurzlebige Nextcloud und MariaDB benötigt.

```bash
../.venv/bin/python rerun.py
```

Das prüft die eingefrorenen Bilder/Solltreffer, lädt dieselben Modellrevisionen und wiederholt Dateisuche, Media-AI-Volltextsuche sowie die drei Embedding-Varianten. Die produktive Nextcloud und ihre Daten werden nicht benutzt. Beide Testcontainer samt ihren anonymen Volumes werden anschließend entfernt. Alle Ports sind ausschließlich an Loopback gebunden.

## Pipeline erneut ausführen

`run_inference.py` benutzt die unveränderte Pipeline aus `source-snapshot`, deren Standardprompts und den vorhandenen Modellserver, ausschließlich mit `images/` als Quelle. Ein vollständiger neuer Inferenzlauf braucht einen eigenen neuen State-Pfad oder eine separat gesicherte bisherige State-Datenbank; mit dem bisherigen State greift die Pipeline auf ihren Cache zurück. Die Modellantworten sind probabilistisch und ändern bei einem echten Neulauf möglicherweise die Ergebnisse. Der normale Retrieval-Neulauf verwendet deshalb die erhaltenen Metadaten.

## Einschränkungen

20 künstliche Bilder und 62 diagnostische Suchanfragen sind kein Nachweis für 30.000 echte Fotos oder ein bestimmtes Handy. Die 30.000-Vektor-Messung wiederholt diese Vektoren und misst nur Laufzeit/Speicher. Stock Nextcloud 32.0.5 nutzt im Versuch SQLite; die tatsächliche unveränderte Media-AI-PHP-Suche nutzt separat MariaDB 11.8.9. Deren Dienstaufruf misst nicht den kompletten Nextcloud-Provider oder die Gallery-App.
