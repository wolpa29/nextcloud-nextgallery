# Hybridvergleich: Bild oder Bild + Beschreibung

Nur synthetische Medien, keine Änderungen an der Pipeline. Hauptvergleich mit identischer Textsuche und identischer Rangfusion.

- `REPORT.md`: Ergebnisse und Grenzen.
- `compare.html`: filterbare erste fünf Treffer, Solltreffer grün; Vorschauen in `thumbs`.
- `protocol.json`: Eingaben, SHA-256, feste Verfahren und Diagnosen.
- `results.json`: alle Rankings, Kategorien und Messwerte.
- `verify.py` / `verification.json`: unabhängig nachgerechnete Fusion und Kennzahlen.

Wiederholen aus dem übergeordneten Projektordner:

```bash
media-ai-search-benchmark-20261009/.venv/bin/python media-ai-hybrid-comparison-20261010/benchmark.py
media-ai-search-benchmark-20261009/.venv/bin/python media-ai-hybrid-comparison-20261010/verify.py
```

Die vorhandenen Gemma-Vektoren und echten SQL-Ergebnisse aus dem isolierten MariaDB-Test werden geprüft und wiederverwendet; keine neue Modellinferenz und kein neuer Datenbanklauf. Kein Qwen-Test. Dateien des bisherigen Tests und produktive Dateien bleiben unverändert.

Lokale Vorschau während der Sitzung: http://127.0.0.1:8769/compare.html

## Aktualisierte Hauptwertung

`recall-report.md` und `recall-results.json` bewerten, ob sämtliche markierten passenden Bilder in einer großzügigen Trefferliste enthalten sind. Wiederholung: dieselbe Python-Umgebung mit `recall-evaluation.py`. Top1 ist für die Nutzerpriorität nur eine Zusatzkennzahl.
