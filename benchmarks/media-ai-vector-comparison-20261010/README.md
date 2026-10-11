# Vergleich von einem und zwei Vektoren

Eigenständiger synthetischer Test vom 10.10.2026.

- `REPORT.md`: Ergebnis, Kategorien, Kosten und Grenzen.
- `compare.html`: filterbare Top-5-Treffer mit synthetischen Vorschaubildern.
- `protocol.json`: vor der Inferenz festgelegtes Verfahren und Prüfsummen.
- `results.json`: alle Rankings und Messwerte, einschließlich Diagnosen.
- `verification.json`: überprüfte Endzustände und Artefaktprüfsummen.

Der Test verwendet ausschließlich den nachweislich künstlichen eingefrorenen Bestand und das bereits vorhandene gepinnte Modell aus `../media-ai-search-benchmark-20261009/five-way`. Originale, alte Ergebnisse, produktive Pipeline und Server werden nicht verändert. Es wurden keine Modelle neu heruntergeladen.

## Wiederholung

Aus diesem Ordner:

```bash
../media-ai-search-benchmark-20261009/.venv/bin/python benchmark.py
```

Dies berechnet die Vektoren erneut und ersetzt nur die Resultate in diesem neuen Testordner. Die Abhängigkeiten der vorhandenen isolierten Umgebung stehen in `requirements.lock.txt`.

Die lokale Vorschau ist während der aktuellen Sitzung unter `http://127.0.0.1:8767/compare.html` geöffnet; `compare.html` funktioniert auch direkt mit dem danebenliegenden `thumbs`-Ordner.
