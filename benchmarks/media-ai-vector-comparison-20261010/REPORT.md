# Ein gemeinsamer oder zwei getrennte Vektoren?

**Ergebnis:** Der gemeinsame Vektor erreicht 40/42 erste Treffer, zwei getrennte Vektoren mit Rangfusion 39/42. Zwei Vektoren mit gemittelter Ähnlichkeit erreichen ebenfalls 40/42. Es gibt in diesem Bestand keinen Qualitätsvorteil für zwei Vektoren. Ein gemeinsamer Vektor ist deshalb der einfachere Einstieg mit halbem Rohvektorspeicher; der kleine Test belegt keine generelle Überlegenheit.

Test vom 10.10.2026. 20 ausschließlich künstliche Bilder, unveränderte echte Pipeline-Metadaten, 62 zuvor festgelegte Suchanfragen.

Hauptvergleich: 42 inhaltliche Anfragen. Exakte OCR (10), Dateinamen (6) und nicht vorhandene Inhalte (4) sind separate Kontrollen.

## Trefferqualität

| Verfahren | Richtiger erster Treffer | Treffer in Top 5 | MRR | nDCG@5 |
|---|---:|---:|---:|---:|
| 2 Vektoren · Rangfusion | 39/42 (92.9%) | 42/42 | 0.960 | 0.968 |
| 1 Vektor · Bild + Beschreibung gemeinsam | 40/42 (95.2%) | 42/42 | 0.976 | 0.982 |
| Kontrolle · nur Beschreibung | 39/42 (92.9%) | 42/42 | 0.964 | 0.974 |
| Kontrolle · nur Bild | 37/42 (88.1%) | 42/42 | 0.931 | 0.951 |
| Diagnose · 2 Vektoren, mittlere Ähnlichkeit | 40/42 (95.2%) | 42/42 | 0.976 | 0.982 |
| Diagnose · 2 Vektoren, beste Ähnlichkeit | 40/42 (95.2%) | 42/42 | 0.976 | 0.980 |
| Diagnose · 1 gemittelter Vektor | 40/42 (95.2%) | 42/42 | 0.976 | 0.982 |
| Diagnose · 1 gemeinsamer Vektor, Text zuerst | 40/42 (95.2%) | 42/42 | 0.976 | 0.982 |

## Kategorien (richtiger erster Treffer)

| Kategorie | Zwei Vektoren, RRF | Ein gemeinsamer Vektor |
|---|---:|---:|
| Begriffe | 12/12 | 12/12 |
| Umschreibungen | 13/14 | 14/14 |
| Englisch | 8/8 | 8/8 |
| Visuelle Details | 6/8 | 6/8 |
| OCR exakt | 6/10 | 10/10 |
| Dateiname | 3/6 | 3/6 |

## Gemeinsame und unterschiedliche Treffer

{'both_correct': 39, 'joint_only': 1, 'both_wrong': 2}

| Suchanfrage | Solltreffer | Zwei Vektoren: Platz 1 | Gemeinsamer Vektor: Platz 1 |
|---|---|---|---|
| roter Kreis | circle_left, circle_right | circle_right | circle_left |
| Fahrrad unter Baeumen | bicycle_park | blue_bike_beach | bicycle_park |
| RECHNUNG 12345 | invoice_bike | invoice_coffee | invoice_bike |
| 12345 | invoice_bike | triangle_below | invoice_bike |
| 17:30 | chat_beach | chat_park | chat_beach |
| IC 507 | train_ticket | circle_right | train_ticket |
| IMG_0002 | winter_hiker | exit_sign | chat_park |

## Laufzeit und Speicher

- Rechner: CPU, vier Threads; Modellladezeit 367 ms; maximaler Prozessspeicher 1092 MiB.
- Indexierung für zwei Vektoren: 20651 ms gesamt / 20 Bilder; Median je Bild 1027 ms.
- Indexierung gemeinsamer Vektor: 20263 ms gesamt / 20 Bilder; Median je Bild 985 ms.
- Suchtext einbetten: Median 41.5 ms, identisch für beide Verfahren.
- 30.000 wiederholte Vektoren, joint: Median 3.13 ms, p95 5.46 ms.
- 30.000 wiederholte Vektoren, separate_rrf: Median 8.31 ms, p95 10.55 ms.
- 30.000 wiederholte Vektoren, separate_mean: Median 6.20 ms, p95 9.00 ms.
- Rohvektoren, 768 Dimensionen/float32, 30.000 Bilder: ein Vektor 87.9 MiB, zwei Vektoren 175.8 MiB. Datenbank-/Index-Overhead ausgeschlossen.

## Sensitivität

| Konfiguration | Zwei Vektoren, RRF | Ein gemeinsamer Vektor |
|---|---:|---:|
| primary | 39/42 | 40/42 |
| model_card_query_prefix | 39/42 | 40/42 |
| dimensions_256 | 37/42 | 40/42 |

## Durchführung und Grenzen

- Dasselbe gepinnte multimodale Modell erzeugt Bilder, Beschreibungen, gemeinsame Eingaben und Suchtexte. Keine Modellmischung.
- Hauptfall: LiteRT-Dokumentationspräfixe, 140 Bildtokens (Maximum des vorhandenen gepinnten Modellpakets), 768 Dimensionen, 2048 Kontextlimit. Überlauf löst einen Fehler aus; kein Abschneiden und kein automatisches Abschnittsmitteln. Der erste Startversuch mit 280 Bildtokens wurde vor der Inferenz vom Runtime abgewiesen und in startup-capacity-error.log aufbewahrt; anschließend wurden beide Verfahren mit denselben 140 Bildtokens getestet.
- Zwei Vektoren: Beschreibung inkl. Tags/Objekte separat vom Bild; feste gleichgewichtete Rangfusion k=60. Ein Vektor: Bild und derselbe Beschreibungstext gemeinsam in einem Modellaufruf.
- Alternativen (Mittelwert-/Maximal-Ähnlichkeit, gemittelter Vektor, Eingabereihenfolge) sind vorher festgelegte Diagnosen, keine nachträglich optimierten Hauptverfahren.
- Kontrollprüfung gemeinsamer Vektor: Median-Kosinus gegen Bild 0.8452, gegen Text 0.9657, gegen gemittelten Vektor 0.9531. Unterschiedliche Repräsentationen, kein umbenannter Durchschnitt.
- OCR und Dateinamen werden für beide Varianten gleichermaßen nicht in den Beschreibungstext eingebettet. Ihre exakte Suche gehört in der späteren hybriden Suche in den Textindex. Einige Inhalte können schon in der vorhandenen KI-Beschreibung stehen.
- Ohne Ablehnungsschwelle liefert Vektorsuche auch bei allen vier nicht vorhandenen Anfragen Treffer. Dieser Test misst deshalb keine zuverlässige Erkennung von »nichts gefunden«.
- Nur 20 unterschiedliche Bilder, darunter sechs künstliche Fotos und 14 Grafiken/Dokumente. Mehrere Fragen beziehen sich auf dasselbe Bild; die 42 Fragen sind keine 42 unabhängigen Szenen.
- Bestehende Testfragen waren in einem früheren Experiment bereits ausgewertet. Hier erfolgte keine neue Auswahl/Optimierung, es handelt sich aber nicht um einen neuen blinden Holdout.
- Ein kleiner diagnostischer Test ist kein allgemeiner Qualitätsnachweis für eine private Fotobibliothek. Der 30k-Lauf wiederholt Vektoren und belegt ausschließlich Rechenzeit/Rohspeicher auf diesem Rechner.
- Kein produktiver Serverzugriff, keine privaten Medien, keine Änderung an Pipeline, Queue oder App. Keine Gesichtserkennung getestet.

## Quellen und Wiederholung

- [Google-Modellkarte](https://ai.google.dev/gemma/docs/embeddinggemma/model_card_2)
- [LiteRT-Embedding-API und Präfixe](https://developers.google.com/edge/litert-lm/embedding_models)
- Modellrevision, Bild-/Datensatzprüfsummen und vorab festgelegtes Verfahren: `protocol.json`.
- Erzeugte Vektoren, alle Rankings, Metriken und Messzeiten: `*-vectors-768.npy`, `results.json`, `inference.json`.
- Wiederholung: `../media-ai-search-benchmark-20261009/.venv/bin/python benchmark.py` (neue Inferenz).
