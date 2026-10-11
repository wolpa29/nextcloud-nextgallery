# Eigentliche Frage: reine gemeinsame Vektorsuche oder Bild-Vektorsuche mit Textsuche?

Auswertung der bereits berechneten, unveränderten Testdaten. Keine neue Inferenz und keine Änderung an der Pipeline.

A: Nur die Vektorsuche. Ein gemeinsamer Gemma-Vektor entsteht aus Bild + vorhandener KI-Beschreibung, Tags und Objekten. OCR ist in diesem Vektor nicht als extrahierter Text enthalten.
B: Nur das Bild wird eingebettet. Seine Vektortreffer werden mit der normalen Textsuche in Beschreibung, Tags, Objekten und OCR kombiniert (Rangfusion 1:1, k=60).

| Testgruppe | A: reine gemeinsame Vektorsuche | B: Bild-Vektorsuche + Textsuche |
|---|---:|---:|
| content: richtiger erster Treffer | 40/42 | 39/42 |
| OCR exakt: richtiger erster Treffer | 10/10 | 8/10 |
| Inhalt: richtiger Treffer unter den ersten 5 | 42/42 | 42/42 |

Ergebnis: In diesem kleinen Test ist die reine gemeinsame Vektorsuche knapp besser: 40/42 statt 39/42 Inhaltsfragen. Beide haben für alle Inhaltsfragen einen Solltreffer in den ersten fünf. Bei den 10 vorhandenen OCR-Fragen erreicht A 10/10, B 8/10. Das beweist keine allgemeine Zuverlässigkeit für exakte Nummern und Beträge: B verwendet die jetzige Textsuche mit Mindestlänge 3, UND-Verknüpfung und Sortierung nach Datensatz-ID, keine optimierte Suche für exakte Werte.

Beide Verfahren liefern bei allen vier nicht vorhandenen Inhalten trotzdem ähnliche Bilder, weil keine Ausschluss- oder Relevanzschwelle angewendet wurde. A kann inhaltlich ähnlich finden, ersetzt aber keine garantierte genaue Zeichenfolgen-Suche.

Empfehlung: Gemeinsamer Vektor als semantische Inhaltssuche; Beschreibung und OCR lesbar speichern. Genaues Suchen in OCR und Dateinamen als zusätzlichen Suchweg behalten. Bild-only bleibt optional für Nutzer ohne Beschreibungspipeline.

Nur 20 künstliche Bilder, EmbeddingGemma 2, bereits bekannter Testbestand, wiederverwendete echte Vektoren und echte gespeicherte SQL-Treffer. Keine Messung für Qwen und keine große reale Galerie. Dateinamen nicht in den eingebetteten bzw. geprüften Textfeldern enthalten; diese Kontrollfragen daher getrennt.
