# Media AI: Vergleich von fünf Suchverfahren

## Befund

Die zusätzliche semantische Suche bringt in diesem kleinen Test einen großen Gewinn gegenüber der aktuellen Volltextsuche. Die vorhandene Media-AI-Pipeline liefert dafür bereits brauchbare Daten: Metadaten-Embedding 50/52 richtige erste Treffer, aktuelle Volltextsuche 19/52. Direktes Bild-Embedding erreicht 49/52. Die vorab gewählte Rangfusion erreicht ebenfalls 49/52; eine feste 50/50-Kosinusmischung erreicht 50/52 und schlägt die Metadatenvariante auch nicht. Die Kombination ist damit hier nicht nachweisbar besser. Ein einzelner Treffer Unterschied ist bei dieser kleinen, künstlichen Stichprobe keine belastbare generelle Rangordnung.

Naheliegender erster Schritt für eure bestehende Pipeline: Beschreibung, Tags, Objekte und OCR zusätzlich embedden und Dateinamen-/exakte Textsuche weiter anbieten. Bildvektoren bleiben eine interessante Erweiterung, besonders für Inhalte, die die Beschreibung auslässt; ihr zusätzlicher Qualitätsgewinn ist mit diesem Versuch jedoch nicht belegt. Sie sind auch als eigenständige Alternative brauchbar und erfordern für ihre Erzeugung keine Qwen-Beschreibung.

Alle drei Embedding-Verfahren beantworten die vier absichtlich nicht vorhandenen Inhalte mit unpassenden Treffern, weil kein kalibrierter Relevanzfilter benutzt wurde. Auch die bestehende Volltextsuche liefert bei „Abfahrt 23:59“ einen falschen Treffer: die kurzen Zahlentokens fallen weg. Eine Kombination allein löst diese Grenzen nicht.

## Aufbau und tatsächliche Testgrenzen

20 künstliche Bilder: sechs mit dem eingebauten imagegen-Werkzeug erzeugte Fotos, acht deterministisch erzeugte Dokument-/Screenshotbilder und sechs Grafiken. Keine privaten Medien. 62 vorab festgelegte Suchanfragen: 52 Inhaltsanfragen, sechs Dateinamen-Kontrollen und vier nicht vorhandene Inhalte. Verwandte Anfragen an dieselben Bilder sind keine unabhängigen Stichproben. Dies ist ein kleiner diagnostischer Versuch, keine repräsentative Messung einer echten Fotobibliothek.

Die aktuelle Pipeline hat alle Bilder tatsächlich mit `qwen/qwen3.8-27b` (Q4_K_M) am vorhandenen Modellserver analysiert: unveränderter Worker, Standardprompts, Vision, OCR, Bildvorbereitung und LM-Studio-SDK-Transport. Eigene lokale Quelle, eigene Datenbank, keine Veröffentlichung an produktives Nextcloud. Die hier verwendeten Einstellungen entsprechen den Repository-Defaults; individuelle abweichende Einstellungen der produktiven Installation wurden nicht ausgelesen.

1. **Normale Nextcloud-Suche:** echter OCS-Provider `files` einer isolierten Nextcloud 32.0.5 mit SQLite über HTTP. Keine zusätzliche OCR-/Volltext-App, keine manuellen Tags. Der Provider sucht Dateinamen mit `LIKE`. Drei Dateinamen enthalten Inhaltsbegriffe, die übrigen überwiegend Kamera-/Scanbezeichnungen.
2. **Aktuelle Media-AI-Suche:** tatsächliche unveränderte PHP-Methoden `Metadata::validate/upsert/search`, isoliertes MariaDB 11.8.9 mit InnoDB-FULLTEXT, Binärkollation, Standard-Stopwörtern und Mindestwortlänge 3. Alle Suchwörter werden mit `+wort*` verknüpft; die Reihenfolge folgt der Datensatz-ID. Der Test ruft den Suchdienst direkt auf; Provider-HTTP, Berechtigungs-/ETag-Prüfungen und Android-Oberfläche wurden hierfür nicht gemessen.
3. **Nur Metadaten-Embedding:** Beschreibung, Tags, Objekte und OCR werden zu einem Text zusammengefügt. Keine Dateinamen, keine Bildpixel, keine lexikalische Rettung.
4. **Nur Bild-Embedding:** tatsächliche Bildpixel, ohne Pipeline-Metadaten, OCR-Text oder Dateinamen. Die Suche selbst bleibt eine Textanfrage; der multimodale Encoder vergleicht sie mit den Bildvektoren.
5. **Beide Embeddings:** zwei getrennte Vektoren pro Bild. Gleich gewichtete Rangfusion `1/(60+Textrang) + 1/(60+Bildrang)`. Vorab festgelegt, nicht anhand der Antworten optimiert. Eine feste 50/50-Mischung der Kosinuswerte ist separat als Diagnose enthalten.

EmbeddingGemma 2: optimierter Text-Encoder 270M und Text-/Bild-Encoder 440M, vollständige **768 Dimensionen**, normalisierte Float32-Vektoren und exakter Kosinusvergleich. Textgewichte int4-QAT, Bild-Encoder int8-QAT; 140 Bild-Tokens, das Maximum dieses LiteRT-Exports. Das sind nicht die unquantisierten Originalgewichte und nicht automatisch das Maximum jedes Google-Exports. Eingabegrenzen 128–512 Text-Tokens. Modellrevisionen, Originaldateien, Quellstand, Bildprüfsummen und vorab festgelegtes Verfahren liegen daneben.

Offizielle Modellquellen: [Text/Bild-LiteRT-Modell](https://huggingface.co/litert-community/embeddinggemma-2-text-vision-440m-litert-lm), [Google-Basismodell und Suchpräfixe](https://huggingface.co/google/embeddinggemma-2), [LiteRT-Dokumentation](https://developers.google.com/edge/litert-lm/embedding_models). Primär wurden die Präfixe der Google-Modellkarte benutzt. Die abweichenden Präfixe der LiteRT-Anleitung werden separat berichtet.

## Ergebnis für Bildinhalte – 52 beantwortbare Anfragen

| Verfahren | Richtig auf Platz 1 | Richtig unter ersten 5 | MRR |
|---|---:|---:|---:|
| Normale Nextcloud-Dateisuche | 1/52 (1.9 %) | 1/52 (1.9 %) | 0.019 |
| Aktuelle Media-AI-Volltextsuche | 19/52 (36.5 %) | 21/52 (40.4 %) | 0.379 |
| Embedding der Media-AI-Metadaten | 50/52 (96.2 %) | 52/52 (100.0 %) | 0.981 |
| Direktes Bild-Embedding | 49/52 (94.2 %) | 52/52 (100.0 %) | 0.968 |
| Metadaten- und Bild-Embedding (RRF) | 49/52 (94.2 %) | 52/52 (100.0 %) | 0.971 |

Hit@1 bedeutet: Mindestens eines der vorab markierten passenden Bilder steht ganz oben. Hit@5 bedeutet: Mindestens eines steht unter den ersten fünf. Das ist keine Genauigkeit über alle Bilder und keine Garantie, dass die übrigen Treffer passen. MRR bewertet den Rang des ersten passenden Bilds. Bei allgemeinen Einzelwörtern gibt es Interpretationsspielraum; „Fahrrad“ wurde auf Bilder mit sichtbarem Fahrrad bezogen.

## Einschließlich Dateinamen – 58 beantwortbare Anfragen

| Verfahren | Richtig auf Platz 1 | Richtig unter ersten 5 | MRR |
|---|---:|---:|---:|
| Normale Nextcloud-Dateisuche | 7/58 (12.1 %) | 7/58 (12.1 %) | 0.121 |
| Aktuelle Media-AI-Volltextsuche | 19/58 (32.8 %) | 21/58 (36.2 %) | 0.340 |
| Embedding der Media-AI-Metadaten | 53/58 (91.4 %) | 55/58 (94.8 %) | 0.937 |
| Direktes Bild-Embedding | 52/58 (89.7 %) | 55/58 (94.8 %) | 0.923 |
| Metadaten- und Bild-Embedding (RRF) | 52/58 (89.7 %) | 55/58 (94.8 %) | 0.926 |

Die sechs Dateinamen-Anfragen sind eine bewusste Kontrolle. Die Embedding-Varianten erhalten keine Dateinamen und sollen diese Fähigkeit im Test nicht vortäuschen. Eine reale Suche sollte Dateiname und genaue OCR-Begriffe zusätzlich unterstützen.

## Nach Suchart

| Suchart | Anzahl | Nextcloud | Media AI aktuell | Metadaten-Embedding | Bild-Embedding | Beide |
|---|---:|---:|---:|---:|---:|---:|
| Begriffe | 12 | 1/12 | 11/12 | 12/12 | 12/12 | 12/12 |
| Umschreibungen | 14 | 0/14 | 1/14 | 14/14 | 13/14 | 13/14 |
| Englisch | 8 | 0/8 | 0/8 | 8/8 | 8/8 | 8/8 |
| OCR exakt | 10 | 0/10 | 6/10 | 10/10 | 10/10 | 10/10 |
| Visuelle Details | 8 | 0/8 | 1/8 | 6/8 | 6/8 | 6/8 |
| Dateiname | 6 | 6/6 | 0/6 | 3/6 | 3/6 | 3/6 |

## Konkrete Beispiele

| Anfrage | Nextcloud | Media AI aktuell | Metadaten-Embedding | Bild-Embedding | Beide |
|---|---|---|---|---|---|
| Vierbeiner spielt im Wasser | ✗ Keine Treffer | ✗ Keine Treffer | ✓ Hund mit rotem Ball im Meer | ✓ Hund mit rotem Ball im Meer | ✓ Hund mit rotem Ball im Meer |
| Zweirad neben einer Sitzbank | ✗ Keine Treffer | ✗ Keine Treffer | ✓ Rotes Fahrrad im Park | ✓ Rotes Fahrrad im Park | ✓ Rotes Fahrrad im Park |
| Fahrrad unter Baeumen | ✗ Keine Treffer | ✗ Keine Treffer | ✓ Rotes Fahrrad im Park | ✗ Rotes Fahrrad am Strand (Grafik) | ✗ Rotes Fahrrad am Strand (Grafik) |
| robot vacuum cleaner and a black cat | ✗ Keine Treffer | ✗ Keine Treffer | ✓ Weisser Saugroboter und schwarze Katze | ✓ Weisser Saugroboter und schwarze Katze | ✓ Weisser Saugroboter und schwarze Katze |
| RECHNUNG 12345 | ✗ Keine Treffer | ✓ Fahrradwerkstatt Rechnung 12345 | ✓ Fahrradwerkstatt Rechnung 12345 | ✓ Fahrradwerkstatt Rechnung 12345 | ✓ Fahrradwerkstatt Rechnung 12345 |
| 12346 | ✗ Keine Treffer | ✓ Kaffeehaus Rechnung 12346 | ✓ Kaffeehaus Rechnung 12346 | ✓ Kaffeehaus Rechnung 12346 | ✓ Kaffeehaus Rechnung 12346 |
| 17:30 | ✗ Keine Treffer | ✗ Keine Treffer | ✓ Strandtermin 17:30 Uhr | ✓ Strandtermin 17:30 Uhr | ✓ Strandtermin 17:30 Uhr |
| gelbes Kanu auf dem See | ✗ Keine Treffer | ✗ Keine Treffer | ✓ Picknick am See mit gelbem Kanu | ✓ Picknick am See mit gelbem Kanu | ✓ Picknick am See mit gelbem Kanu |
| roter Kreis links vom blauen Quadrat | ✗ Keine Treffer | ✗ Keine Treffer | ✓ Roter Kreis links von blauem Quadrat | ✗ Roter Kreis rechts von blauem Quadrat | ✗ Roter Kreis rechts von blauem Quadrat |
| roter Kreis rechts vom blauen Quadrat | ✗ Keine Treffer | ✗ Keine Treffer | ✗ Roter Kreis links von blauem Quadrat | ✓ Roter Kreis rechts von blauem Quadrat | ✓ Roter Kreis rechts von blauem Quadrat |
| gelbes Dreieck ueber gruenem Stern | ✗ Keine Treffer | ✗ Keine Treffer | ✗ Gelbes Dreieck unter gruenem Stern | ✓ Gelbes Dreieck ueber gruenem Stern | ✗ Gelbes Dreieck unter gruenem Stern |
| gelbes Dreieck unter gruenem Stern | ✗ Keine Treffer | ✗ Keine Treffer | ✓ Gelbes Dreieck unter gruenem Stern | ✗ Gelbes Dreieck ueber gruenem Stern | ✓ Gelbes Dreieck unter gruenem Stern |
| blaues Fahrrad am Strand | ✗ Keine Treffer | ✓ Blaues Fahrrad am Strand (Grafik) | ✓ Blaues Fahrrad am Strand (Grafik) | ✓ Blaues Fahrrad am Strand (Grafik) | ✓ Blaues Fahrrad am Strand (Grafik) |
| RECHNUNG 12349 | ✗ Keine Treffer | ✗ Keine Treffer | ✗ Kaffeehaus Rechnung 12346 | ✗ Kaffeehaus Rechnung 12346 | ✗ Kaffeehaus Rechnung 12346 |

Die Fehler sind teilweise komplementär: Die Metadaten finden „Fahrrad unter Baeumen“ besser, der Bild-Encoder die Anordnung „roter Kreis rechts“ oder „gelbes Dreieck ueber“. Bei der gleich gewichteten Rangfusion entstehen dabei auch Ranggleichstände; diese werden unverändert nach der vorher festgelegten Datensatzreihenfolge aufgelöst. Eine bessere Fusion könnte helfen, müsste aber mit separaten Trainings-/Validierungsdaten geprüft werden. Die Testantworten wurden hierfür nicht zum Optimieren benutzt.

## Nicht vorhandene Inhalte

| Verfahren | Unpassende nichtleere Ergebnislisten |
|---|---:|
| Normale Nextcloud-Dateisuche | 0/4 |
| Aktuelle Media-AI-Volltextsuche | 1/4 |
| Embedding der Media-AI-Metadaten | 4/4 |
| Direktes Bild-Embedding | 4/4 |
| Metadaten- und Bild-Embedding (RRF) | 4/4 |

Alle drei dichten Verfahren geben ohne Schwelle immer Ergebnisse aus. Die Schwelle wurde absichtlich nicht nachträglich anhand dieser vier Anfragen angepasst. Insbesondere nahe Rechnungsnummern und Farben brauchen eine verlässliche Regel für genaue Begriffe oder ein separat geprüftes Relevanzfilterverfahren. Keine Treffer ist bei diesen vier Anfragen die richtige Antwort.

## Unverändert vorab festgelegte Zusatzdiagnosen

| Variante | Hit@1 für Inhalte | Hit@5 für Inhalte |
|---|---:|---:|
| combined_mean_cosine | 50/52 | 52/52 |
| metadata_runtime_prefix | 48/52 | 52/52 |
| image_runtime_prefix | 46/52 | 52/52 |
| lexical_or_proposal | 31/52 | 38/52 |

Die OR-Volltextvariante ist ein Änderungsvorschlag, nicht eure aktuelle Suche. Alternative Präfixe und Score-Mischung sind Sensitivitätsprüfungen, kein nachträglich ausgewählter Testsieger.

## Gemessene Laufzeit und Speicher

Pipeline-Analyse aller 20 Bilder: 1297.0 Sekunden Wandzeit; alle 20 fertig. Serverseitige Auslastung und Reasoning beeinflussen diese Laufzeit.

- Lokaler Rechner: Intel Core i5-12400, KVM mit 10 sichtbaren vCPUs, 30 GiB RAM. Embeddings auf CPU mit vier Threads; kein lokaler GPU-Test.
- Normale Nextcloud-Suche, lokaler kompletter HTTP-Aufruf: 212.91 ms Median / 254.16 ms P95.
- Media-AI-Suchdienst, ausschließlich Datenbankaufruf: 0.36 ms Median / 1.14 ms P95.
- Metadaten-Modell, Suchtext nach Aufwärmen: 43.94 ms Median / 52.93 ms P95.
- Multimodales Modell, Suchtext: 44.37 ms Median / 57.28 ms P95.
- Ein Bild direkt embedden (einmalige Indexierung): 1156.80 ms Median / 1490.25 ms P95.
- Modell laden: Text 160.2 ms, multimodal 199.5 ms; Gewichte lagen bereits lokal, kein belastbarer Kaltstart nach Neustart.
- Peak-RSS des Text-Benchmarkprozesses inklusive Ergebnis-/Scanarrays: 539.8 MiB; separater multimodaler Encoderprozess: 916.3 MiB. Keine reine Modell-RAM-Messung.
- 30.000 wiederholte Vektoren, exakter Vergleich plus Top-5-Auswahl: ein Index 2.71 ms Median / 3.40 ms P95, zwei Indizes mit Kosinusmischung 5.46 ms Median / 6.29 ms P95, beide getrennt mit Rangfusion 7.03 ms Median / 8.25 ms P95. Ausschließlich Rechenzeit; keine 30.000 verschiedenen Fotos und keine End-to-End-Netzwerk-/Android-Messung.
- Speicher für 30.000 Bilder × 768 Dimensionen: ein Float32-Index **92,16 MB** (87,89 MiB), zwei **184,32 MB** (175,78 MiB). Float16 halbiert das auf 46,08 bzw. 92,16 MB; Dateizuordnung, Suchindex und Modelle kommen zusätzlich dazu.
- Float16-Speicherung der Metadaten-Vektoren änderte bei 1/62 Anfragen die Reihenfolge/Inhalte der ersten fünf Treffer. Das ist keine allgemeine Qualitätsgarantie.
- Minimale Kosinusübereinstimmung der Suchvektoren aus Text- und Text/Bild-Export: 0.99999899. Gleiche Modellfamilie allein ist keine allgemeine Kompatibilitätsgarantie; diese konkreten Exporte wurden hier verglichen.

Die obigen Zeiten messen unterschiedliche Grenzen: HTTP, SQL und lokale Modellinferenz dürfen nicht als direkt vergleichbare End-to-End-Zeiten ausgelegt werden. Der große Qwen-Server wurde nur zur einmaligen Metadatenerzeugung gebraucht. Die Gallery-App ist durch diesen Test nicht verändert und Offline-Suche am Handy wurde nicht getestet.

## Artefakte und Wiederholung

[Interaktiver Vergleich](compare.html) zeigt für jede Anfrage die ersten fünf Bilder aller Verfahren, die erwarteten Bilder und die wirklich erzeugten Metadaten. `results.json` enthält alle Ränge, Scores, Metriken und Zeiten. `fixtures.json` enthält den vorab festgelegten Bildbestand und die Solltreffer. `provenance.json`, `protocol.json`, Modellrevisionen, `.npy`-Vektoren, Pipeline-Konfiguration und vollständige Logs erlauben Prüfung und Wiederholung.

Zum erneuten reinen Retrieval-Vergleich `rerun.py` mit der Python-Umgebung aus dem übergeordneten Benchmark ausführen. Das nutzt die bereits erzeugten, eingefrorenen Metadaten und erstellt eigene kurzlebige Nextcloud-/MariaDB-Container. Die probabilistische Qwen-Erkennung wird nicht still erneut erzeugt. Ein erneuter vollständiger Pipeline-Lauf ist separat mit `run_inference.py` möglich, bei vorhandener State-Datenbank aber aus dem Pipeline-Cache.

## Verifikation

Ein zweiter vollständiger Retrieval-Lauf mit neuen Nextcloud-/MariaDB-Testinstanzen und denselben eingefrorenen Metadaten lieferte exakt dieselben Trefferlisten für alle 62 Anfragen, auch bei den separat ausgewiesenen Zusatzdiagnosen. Die Qwen-Erkennung wurde einmal ausgeführt. Bildprüfsummen, Solltreffer und unveränderter Quellstand wurden geprüft. Filter, Einzelanfragen und Kategorieauswertung des interaktiven Berichts wurden im Browser funktional geprüft. Beide Durchläufe haben ihre kurzlebigen Testcontainer samt anonymen Volumes wieder entfernt.
