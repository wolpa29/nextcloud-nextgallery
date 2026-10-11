# Media-AI-Suchvergleich – 09.10.2026

**Ergebnis:** Im festgelegten synthetischen Testbestand findet EmbeddingGemma 2 wesentlich mehr passende Dateien auf Platz 1. Eine bessere Volltextlogik bringt ebenfalls einen Teil des Gewinns, erreicht aber die Embeddings hier nicht.

## Vergleich

56 handgeschriebene synthetische Datensätze mit Beschreibung, Tags, Objects und teilweise OCR; 54 vorher festgelegte Anfragen. 50 Anfragen haben mindestens eine passende Datei, vier haben absichtlich keine. Die Reihenfolge der Dateien wurde mit festem Zufallsstartwert gemischt. Keine privaten Bilder oder produktiven Datenbanken wurden gelesen.

| Methode | Richtiger Treffer auf Platz 1 | Richtiger Treffer unter den ersten 5 |
|---|---:|---:|
| Aktuelle Nextcloud-Suche | 21/50 (42%) | 22/50 (44%) |
| Volltext mit OR und Relevanz (Vorschlag) | 31/50 (62%) | 42/50 (84%) |
| EmbeddingGemma 2 · 768 Dimensionen | 48/50 (96%) | 50/50 (100%) |
| Kombination aus aktueller Suche und Embeddings | 48/50 (96%) | 50/50 (100%) |

**Die ersten fünf enthalten einen richtigen Treffer** bedeutet nicht, dass alle fünf richtig sind. Die semantische Suche liefert auch ähnliche, aber unpassende Kandidaten.

| Anfragegruppe | Aktuelle Suche: Platz 1 | OR-Volltext: Platz 1 | Embedding: Platz 1 |
|---|---:|---:|---:|
| Exakte Begriffe | 10/10 | 10/10 | 10/10 |
| Umformulierte Begriffe | 1/12 | 1/12 | 12/12 |
| Natürliche Fragen | 0/8 | 4/8 | 8/8 |
| Beziehungen und Verneinungen | 2/8 | 5/8 | 6/8 |
| Exakter OCR-Text | 8/8 | 8/8 | 8/8 |
| Sinngemäße OCR-Suche | 0/4 | 3/4 | 4/4 |

## Konkrete Beispiele

| Anfrage | Aktuelle Suche | Embedding: Platz 1 | Bewertung |
|---|---|---|---|
| Saugroboter Ladestation | Ein runder Saugroboter steht auf einem Holzboden neben seiner Ladestation. | Ein runder Saugroboter steht auf einem Holzboden neben seiner Ladestation. | Richtig |
| Winterwanderung | Kein Treffer | Zwei Wanderer gehen auf einem schneebedeckten Bergpfad. Sie tragen Rucksäcke und halten Wanderstöcke. | Richtig |
| Boote am Liegeplatz | Kein Treffer | Mehrere Motorboote liegen an einem Steg in einem Hafen. | Richtig |
| Wo haben wir draußen bei Kerzenschein gegessen? | Kein Treffer | Mehrere Personen sitzen bei Kerzenlicht an einem gedeckten Tisch auf einer Terrasse. Es ist dunkel, an der Wand hängen Lichterketten. | Richtig |
| Rechnung für den Austausch der Bremsen | Kein Treffer | Ein abfotografiertes Rechnungsblatt liegt auf einem Tisch. | Richtig |
| Hund links neben Katze | Eine Katze sitzt links neben einem Hund auf einer Bank.; Ein Hund sitzt links neben einer Katze auf einer Bank. | Eine Katze sitzt links neben einem Hund auf einer Bank. | Falsch |
| Wanderer ohne Schnee | Kein Treffer | Zwei Wanderer gehen auf einem schneebedeckten Bergpfad. Sie tragen Rucksäcke und halten Wanderstöcke. | Falsch |
| Rechnung 9988776655 | Kein Treffer | Ein abfotografiertes Rechnungsblatt liegt auf einem Tisch. | Falsch / keine passende Datei vorhanden |

## Fehler und wichtige Grenzen

- „Hund links neben Katze“: Das Embedding setzt die vertauschte Anordnung auf Platz 1; die richtige Datei ist auf Platz 2.
- „Wanderer ohne Schnee“: Winterwanderung fälschlich auf Platz 1; Sommerwanderung erst auf Platz 3.
- Die vier Anfragen ohne passende Datei werden von der aktuellen UND-Suche korrekt leer beantwortet. Embedding und die getestete Hybridvariante liefern bei allen vier unpassende Kandidaten. OR-Volltext liefert bei drei der vier Anfragen unpassende Kandidaten.
- Ein universeller Cosine-Grenzwert ist mit diesem Test nicht belegt. Die falsche Rechnung bei „Rechnung 9988776655“ erreicht ungefähr 0,706; der richtige Treffer bei der vorhandenen Nummer „471839“ ungefähr 0,709. Sehr ähnliche Scores ersetzen keine exakte Nummernprüfung.
- Der festgelegte Hybridversuch nutzt Reciprocal Rank Fusion, k=60, je 20 Kandidaten. Er verbessert hier den Platz-1-Wert gegenüber Embedding allein nicht und löst weder Verneinungen noch fehlende Treffer.
- Nur Beschreibungen einzubetten erreicht 44/50 richtige Treffer auf Platz 1; Beschreibung + Tags + Objects + OCR erreicht 48/50. Daraus folgt kein isolierter Effekt der Tags: In der ersten Variante fehlt auch OCR.
- Separates OCR und der alternative dokumentierte Prefix wurden als Robustheitsvarianten getestet und sind nicht die Grundlage einer nachträglichen Anpassung des Testbestands.

## Laufzeit auf diesem Rechner

CPU: 12th Gen Intel(R) Core(TM) i5-12400 in einer Linux-VM, Embedding-Laufzeit mit vier CPU-Threads. Keine GPU verwendet.

| Messung | Median | 95. Perzentil |
|---|---:|---:|
| Embedding einer Suchanfrage (54 Anfragen) | 43.08 ms | 49.54 ms |
| Vergleich mit 30.000 Vektoren und Auswahl der ersten 5 (20 Wiederholungen) | 2.99 ms | 3.30 ms |
| Aktuelle MariaDB-Suche im 56-Dateien-Testbestand | 0.27 ms | 1.21 ms |

Erneuter Modellstart mit vorhandenen Caches: 158 ms. Ein echter Kaltstart auf einem Handy wurde nicht gemessen.

Die 30.000 Vektoren wurden aus den 56 Testvektoren vervielfacht: Diese Messung prüft ausschließlich Rechenaufwand und Speicherumfang, nicht die Trefferqualität einer echten 30.000-Dateien-Galerie. Die Matrix umfasst genau 92.160.000 Bytes (92,16 MB / 87,89 MiB). SQL wurde nicht mit 30.000 Dateien verglichen. Netzwerk, Nextcloud-Dateiauflösung, Rechte-/ETag-Prüfung, App-Oberfläche und Festplattenladen der Vektoren fehlen in den Laufzeitwerten.

## Ausführung und Nachvollziehbarkeit

- Modell: `litert-community/embeddinggemma-2-text-270m-litert-lm`; Revision `9be6e8b90982095dc05c2bd162e4b954ee4dbac7`.
- Optimierter offizieller LiteRT-Export mit int4-QAT-Textgewichten, volle 768 Ausgabedimensionen, normalisierte float32-Vektoren. Kein Test des unquantisierten Google-Checkpoints.
- Laufzeit: LiteRT-LM 0.18.0; Modell SHA-256 `2d079ee2f6f066b1f368e8d7c819f55214eaef1d0513b312321901f30ab286fb`.
- Testdaten SHA-256 `ab04407b54dd76a03c31b2067cbb5bb1e3f2df5aef2f768a958dfbb9dad24b4e`.
- Datenbank: 11.8.9-MariaDB-ubu2404, `utf8mb4_bin`, InnoDB-FULLTEXT, Mindestwortlänge 3, Stopwörter aktiv.
- Baseline ruft die unveränderten Methoden `Metadata::validate`, `upsert` und `search` auf; Nextcloud-Schnittstellen werden nur für den Datenbankadapter ersetzt. Kopie und Herkunft des Quellcodes liegen im Projekt.
- Die Variante OR-Volltext ist ein zusätzlicher Vorschlag, keine vorhandene Produktfunktion. Sie wurde nach dem ersten Embedding-Lauf ergänzt, ohne die Daten oder Relevanzlabels zu ändern; danach wurden alle Varianten erneut ausgeführt.
- Die Android- und Worker-Varianten bilden die aus dem Code gelesene Textnormalisierung/Abfrage nach. Die Android-Sortierung nach Dateidatum wird hier nicht nachgebildet; ihre Platz-1-Werte sind daher keine App-Messung.
- Die beiden offiziellen Dokumentationen nennen unterschiedliche Suchprefixe. Primär wurden die Prefixe der Google-Modellkarte verwendet; die LiteRT-Prefixe stehen als eigene Variante im Rohbericht.

## Einordnung

Der Test rechtfertigt einen Pilot für eine zusätzliche semantische Suche. Er beweist keine allgemeine Trefferquote von 96 Prozent für private Galerien. Getestet wurde die Suche über bereits vorhandene Textmetadaten; direkte Bild-/Video-Embeddings und die Qualität der ursprünglichen KI-Beschreibung wurden nicht geprüft.

Exakte Begriffe und Nummern sollten weiterhin mit der Wortsuche geprüft werden. Ähnliche Kandidaten brauchen eine passende Darstellung oder eine separat validierte Ablehnungsregel. Bei Verneinungen und räumlichen Beziehungen bleiben Grenzen sichtbar.

## Dateien

- `compare.html`: durchsuchbarer Vergleich aller Anfragen und ihrer Kandidaten.
- `dataset.json`: vollständige Eingaben und vorher festgelegte Solltreffer.
- `results.json`: sämtliche Metriken, Messungen und die ersten zehn Kandidaten je Methode.
- `baseline-mariadb.json`: rohe Antworten der aktuellen MariaDB-Suche und OR-Variante.
- `document-vectors-768.npy` / `query-vectors-768.npy`: gemessene Embeddings.
- `benchmark.py`, `baseline.php`, `build_dataset.py`, `rerun.py`, `requirements.lock.txt`: Wiederholung des Versuchs.

Modellquellen: [Google-Modellkarte](https://ai.google.dev/gemma/docs/embeddinggemma/model_card_2), [LiteRT-Textmodell](https://huggingface.co/litert-community/embeddinggemma-2-text-270m-litert-lm), [LiteRT-Embedding-Dokumentation](https://developers.google.com/edge/litert-lm/embedding_models).
