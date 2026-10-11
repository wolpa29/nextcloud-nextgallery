# Isolierter Media-AI-Suchtest

Ergebnisse: [REPORT.md](REPORT.md). Interaktiver Vergleich: `compare.html` lokal im Browser öffnen.

Dieser Ordner enthält ausschließlich künstliche Textmetadaten, eine eigene Python-Umgebung,
ein heruntergeladenes Embedding-Modell und Testresultate. Die produktive Pipeline und private
Medien wurden nicht verändert oder gelesen. Die temporäre Testdatenbank wird nach dem Versuch entfernt.

## Wiederholen

Mit Docker und Python 3.13:

```sh
python3 -m venv .venv
.venv/bin/pip install -r requirements.lock.txt
.venv/bin/python rerun.py
```

`rerun.py` verwendet die vorhandene, eingefrorene `dataset.json`, die Kopie des tatsächlichen
PHP-Suchcodes und die festgehaltene Modellrevision. Es startet eine separate MariaDB mit
zufälligem, nur lokal erreichbarem Port und entfernt den Container danach.
Kein Zugriff auf eine produktive Nextcloud-Installation ist erforderlich.

`build_dataset.py` dokumentiert die Konstruktion der synthetischen Testdaten. Die Relevanzlabels
wurden vor dem ersten Modelllauf festgelegt. Keine Nachbearbeitung der Daten aufgrund der Treffer.

Die Laufzeitmessung mit 30.000 Vektoren vervielfacht die 56 gespeicherten Dokumentvektoren.
Sie ist ein Rechentest und kein Qualitätsbenchmark einer großen Galerie.

Der interaktive Bericht funktioniert ohne Netzwerk und zeigt alle 54 Suchanfragen,
die Solltreffer und die ersten fünf Kandidaten pro Verfahren.
