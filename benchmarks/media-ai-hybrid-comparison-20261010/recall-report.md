# Galerie-Suche: möglichst kein passendes Bild übersehen

Nutzerpriorität: lieber zusätzliche Vorschläge als ein fehlendes gesuchtes Bild. Deshalb ist vollständige Trefferabdeckung (Recall) jetzt wichtiger als der erste Treffer oder eine besonders kurze, saubere Liste.

Verglichen werden A: reine Vektorsuche mit gemeinsamem Bild/Beschreibungs-Vektor und B: Bild-Vektorsuche kombiniert mit der normalen Textsuche in Beschreibung, Tags, Objekten und OCR. Die echten Gemma-Vektoren und SQL-Ergebnisse bleiben unverändert.

## Hauptwertung: alle markierten Treffer je Inhaltsfrage

| Vorschläge pro Frage | A: alle passenden Bilder enthalten | B: alle passenden Bilder enthalten |
|---|---:|---:|
| 1 | 36/42 | 35/42 |
| 3 | 42/42 | 40/42 |
| 5 | 42/42 | 42/42 |
| 10 | 42/42 | 42/42 |

„Alle passenden Bilder“ bezeichnet hier alle vorab markierten Solltreffer. Bei „Fahrrad“ müssen alle drei passenden Fahrradbilder enthalten sein; ein einziger richtiger Fahrradtreffer genügt nicht. Vier der 42 Inhaltsfragen haben mehrere Solltreffer.

## Mehrfachtreffer ausdrücklich geprüft

| Frage | Solltreffer | A: Ränge der Solltreffer | B: Ränge der Solltreffer |
|---|---:|---|---|
| Fahrrad | 3 | 1, 2, 3 | 1, 2, 5 |
| Rechnung | 2 | 1, 2 | 1, 2 |
| roter Kreis | 2 | 1, 2 | 1, 2 |
| gelbes Dreieck | 2 | 1, 2 | 1, 2 |

## Konsequenz für die Galerie

- Bei fünf Vorschlägen decken beide Verfahren sämtliche markierten passenden Bilder für alle 42 Inhaltsfragen ab. Für die bevorzugte großzügige Trefferauswahl ergibt sich hier kein Unterschied; A erreicht dieselbe Abdeckung bereits unter den ersten drei.
- Große Kandidatenmenge abrufen, nach Ähnlichkeit sortieren und weitere Vorschläge nachladen. Ein großzügiges Abruflimit darf nicht als „garantiert vollständig“ gelten.
- Eine hohe feste Ähnlichkeitsschwelle könnte das gesuchte Bild wegfiltern. Für die normale Inhaltssuche zuerst möglichst viele passende Bilder finden; weniger ähnliche Vorschläge können anschließend weiter unten angezeigt werden.
- Exakte OCR-Werte und Dateinamen sind zusätzliche Suchwege. Fehlende lexikalische Treffer dürfen semantische Kandidaten nicht automatisch ausschließen. Dateinamen sind nicht Teil der hier geprüften Embedding- oder Metadaten-Textfelder.
- Hauptkennzahlen für weitere Tests: Anteil der Fragen mit allen passenden Bildern sowie Recall@10/@20/@50; zusätzlich passende Bilder, die in der gesamten Kandidatenmenge fehlen. MRR/Top1 und der Anteil unpassender Vorschläge sind ergänzende Kennzahlen.

## Grenzen

- 20 künstliche Bilder, 42 Inhaltsfragen und nur vier Fragen mit mehreren markierten passenden Bildern. Kein großer neuer Testbestand, keine neue Inferenz, keine Prüfung von Qwen, Videos oder einer produktiven Galerie.
- Die vorher festgelegten Labels bleiben unverändert. Eine zusätzliche, großzügigere Interpretation von „passend“ wurde hier nicht nachträglich zur Verbesserung der Messwerte eingesetzt.
- Alle 20 Bilder zurückzugeben würde auf diesem winzigen Bestand triviale Vollständigkeit ergeben. Deshalb werden insbesondere drei und fünf Vorschläge betrachtet; die Resultate lassen sich nicht auf eine Galerie mit Tausenden Bildern übertragen.
- Eine garantierte vollständige Trefferliste kann ein Embedding-Modell auch mit großzügiger Anzeige nicht versprechen. Vier Abfragen nach nicht vorhandenen Inhalten liefern bei beiden Verfahren trotzdem ähnliche Vorschläge. Das entspricht breiter Kandidatensuche, nicht einem bestätigten inhaltlichen Treffer.
