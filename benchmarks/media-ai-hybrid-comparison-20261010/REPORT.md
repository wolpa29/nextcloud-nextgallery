# Bild-Embedding + Textsuche oder gemeinsames Bild/Text-Embedding + Textsuche?

20 ausschließlich künstliche Bilder; echte gespeicherte Gemma-Vektoren und echte gespeicherte MariaDB-Suchergebnisse. Vergleich vom 10.10.2026.

## Hauptvergleich

| Verfahren | Inhalt: Top 1 (42) | Inhalt: Top 5 (42) | Alle: Top 1 (58) | MRR Inhalt |
|---|---:|---:|---:|---:|
| Textsuche allein | 13/42 | 13/42 | 19/58 | 0.310 |
| Bild-Embedding allein | 37/42 | 42/42 | 48/58 | 0.931 |
| Bild + Beschreibung gemeinsam allein | 40/42 | 42/42 | 53/58 | 0.976 |
| Bild-Embedding + dieselbe Textsuche | 39/42 | 42/42 | 49/58 | 0.958 |
| Gemeinsames Bild/Text-Embedding + dieselbe Textsuche | 40/42 | 42/42 | 51/58 | 0.976 |

Beide Hybridvarianten verwenden identische Texttreffer (Beschreibung, Tags, Objekte, OCR) und identische Rangfusion, k=60, Gewicht 1:1. Der gemeinsame Vektor enthält Beschreibung, Tags und Objekte; OCR bleibt in der Textsuche. Die aktuelle Textsuche verlangt alle Suchwörter und sortiert nach Datensatz-ID. Dateinamen sind in dieser Textsuche nicht enthalten.

## Kategorien

| Kategorie | Bild + Textsuche: Top 1 | Gemeinsam + Textsuche: Top 1 |
|---|---:|---:|
| Begriffe | 12/12 | 12/12 |
| Umschreibungen | 13/14 | 14/14 |
| Englisch | 8/8 | 8/8 |
| OCR exakt | 8/10 | 8/10 |
| Visuelle Details | 6/8 | 6/8 |
| Dateiname | 2/6 | 3/6 |

## Abhängigkeit von der Zusammenführung

| Gleiche Variante für beide | Bild + Text: Top 1 Inhalt | Gemeinsam + Text: Top 1 Inhalt | Bild + Text: Top 1 alle | Gemeinsam + Text: Top 1 alle |
|---|---:|---:|---:|---:|
| primary | 39/42 | 40/42 | 49/58 | 51/58 |
| or_relevance | 33/42 | 33/42 | 43/58 | 45/58 |
| text_first | 38/42 | 40/42 | 50/58 | 53/58 |
| text_weight_half | 39/42 | 40/42 | 50/58 | 52/58 |
| text_weight_double | 39/42 | 40/42 | 49/58 | 51/58 |
| dimension_256 | 36/42 | 40/42 | 44/58 | 52/58 |
| model_card_query | 39/42 | 40/42 | 50/58 | 51/58 |
| top5_candidates | 39/42 | 40/42 | 49/58 | 51/58 |
| top10_candidates | 39/42 | 40/42 | 49/58 | 51/58 |

Diese Diagnosen wurden vor dem neuen Vergleich festgelegt. Keine Variante wurde anhand ihrer richtigen Antworten zum Gewinner optimiert.

## Abweichende erste Treffer

- **Fahrrad unter Baeumen** (Umschreibungen): Bild + Text → `blue_bike_beach`; gemeinsam + Text → `bicycle_park`. Soll: bicycle_park.
- **gelbes Dreieck ueber gruenem Stern** (Visuelle Details): Bild + Text → `triangle_above`; gemeinsam + Text → `triangle_below`. Soll: triangle_above.
- **gelbes Dreieck unter gruenem Stern** (Visuelle Details): Bild + Text → `triangle_above`; gemeinsam + Text → `triangle_below`. Soll: triangle_below.
- **Fahrrad_Park** (Dateiname): Bild + Text → `invoice_bike`; gemeinsam + Text → `bicycle_park`. Soll: bicycle_park.

## Kosten und Grenzen

- Beide Varianten speichern einen 768-dimensionalen Vektor je Bild. Die Vektorsuche und der Rohvektorspeicher sind gleich groß.
- Vorher tatsächlich gemessene Embedding-Zeit auf CPU (Median; 20 Bilder): nur Bild 984.7 ms, gemeinsam 985.0 ms. Kein neuer End-to-End- oder Server-Latenztest.
- Wer Textsuche in KI-Beschreibungen nutzen will, muss diese Beschreibungen weiterhin erzeugen. Ein Bild-Vektor spart diese Beschreibungspipeline nur, wenn man bei der Textsuche auf die Beschreibung verzichtet. OCR kann unabhängig davon benötigt werden.
- Alle vier Anfragen nach nicht vorhandenen Inhalten liefern bei beiden Hybridvarianten trotzdem Treffer, weil ungefilterte Vektorsuche immer ähnliche Bilder liefert. Die Textsuche allein bleibt bei allen vier leer. Es wurde keine Schwelle auf diesen vier Beispielen angepasst.
- Wiederverwendeter kleiner Testbestand, schon aus früheren Vergleichen bekannt: kein unabhängiger großer Nachweis. 20 Bilder, 42 Inhaltsfragen, 10 OCR-Fragen, 6 Dateinamenfragen, 4 Leer-Kontrollen. Der Dateiname-Test bewertet hier keine separate Dateinamen-Suche.
- Nur EmbeddingGemma 2 gemessen. Dieses Ergebnis beweist keine Überlegenheit bei Qwen3-VL-Embedding.
- Die SQL-Suchergebnisse wurden aus dem früheren echten isolierten MariaDB-Test übernommen. Die MariaDB-Suchlogik im aktuellen NextGallery-Code wurde gegen den damaligen Code auf identische Prädikate, Sortierung und Grenzen geprüft; keine neue Datenbankabfrage und kein Zugriff auf Produktionsdaten.
- Bildorientierung, Vektoren, Suchanfragen und Antworten bleiben unverändert. Die SHA-256-Prüfsummen stehen in protocol.json; keine neue Inferenz, keine privaten Medien, keine Änderung an der Pipeline.

## Entscheidung

Im Hauptvergleich: Bild-Vektor plus Textsuche 39/42, gemeinsamer Vektor plus dieselbe Textsuche 40/42 richtige erste Inhaltstreffer. Beide erreichen 42/42 in den ersten fünf. Ein knapper Unterschied, kein belastbarer allgemeiner Qualitätsnachweis. Gemeinsam gewinnt zwei Inhaltsfragen und verliert eine; bei gelbem Dreieck über beziehungsweise unter einem Stern kehrt sich die Stärke der beiden Varianten um. Die OR-Textsuche ergibt bei beiden nur 33/42 und zeigt, dass die Zusammenführung mehr ausmachen kann als der Eingabemodus. Empfehlung: beide konfigurierbaren Modi behalten. Ohne Beschreibungen nur Bild; vorhandene Beschreibungen können im gemeinsamen Vektor mitgenutzt werden. Exakte OCR- und Dateinamen-Suche gezielt behandeln; ungefilterte Vektortreffer sind keine sichere Aussage, dass gesuchte Inhalte vorkommen.
