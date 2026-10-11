import json,statistics,subprocess
from pathlib import Path
from PIL import Image
ROOT=Path(__file__).resolve().parent
d=json.loads((ROOT/'results.json').read_text());s=d['summary'];p=d['performance'];docs={x['key']:x for x in d['documents']}
names={'normal_nextcloud':'Normale Nextcloud-Dateisuche','current_media_ai':'Aktuelle Media-AI-Volltextsuche','metadata_embedding':'Embedding der Media-AI-Metadaten','image_embedding':'Direktes Bild-Embedding','combined_embedding':'Metadaten- und Bild-Embedding (RRF)'}
def ms(xs):return f'{statistics.median(xs):.2f} ms Median / {sorted(xs)[min(len(xs)-1,int(.95*len(xs)))]:.2f} ms P95'
def table(group):
 out=['| Verfahren | Richtig auf Platz 1 | Richtig unter ersten 5 | MRR |','|---|---:|---:|---:|']
 for key,label in names.items():
  m=s[key][group];out.append(f"| {label} | {m['hit1']}/{m['n']} ({m['hit1_pct']:.1f} %) | {m['hit5']}/{m['n']} ({m['hit5_pct']:.1f} %) | {m['mrr']:.3f} |")
 return '\n'.join(out)
lines=['# Media AI: Vergleich von fünf Suchverfahren','',
 '## Befund','',
 'Die zusätzliche semantische Suche bringt in diesem kleinen Test einen großen Gewinn gegenüber der aktuellen Volltextsuche. Die vorhandene Media-AI-Pipeline liefert dafür bereits brauchbare Daten: Metadaten-Embedding 50/52 richtige erste Treffer, aktuelle Volltextsuche 19/52. Direktes Bild-Embedding erreicht 49/52. Die vorab gewählte Rangfusion erreicht ebenfalls 49/52; eine feste 50/50-Kosinusmischung erreicht 50/52 und schlägt die Metadatenvariante auch nicht. Die Kombination ist damit hier nicht nachweisbar besser. Ein einzelner Treffer Unterschied ist bei dieser kleinen, künstlichen Stichprobe keine belastbare generelle Rangordnung.','',
 'Naheliegender erster Schritt für eure bestehende Pipeline: Beschreibung, Tags, Objekte und OCR zusätzlich embedden und Dateinamen-/exakte Textsuche weiter anbieten. Bildvektoren bleiben eine interessante Erweiterung, besonders für Inhalte, die die Beschreibung auslässt; ihr zusätzlicher Qualitätsgewinn ist mit diesem Versuch jedoch nicht belegt. Sie sind auch als eigenständige Alternative brauchbar und erfordern für ihre Erzeugung keine Qwen-Beschreibung.','',
 'Alle drei Embedding-Verfahren beantworten die vier absichtlich nicht vorhandenen Inhalte mit unpassenden Treffern, weil kein kalibrierter Relevanzfilter benutzt wurde. Auch die bestehende Volltextsuche liefert bei „Abfahrt 23:59“ einen falschen Treffer: die kurzen Zahlentokens fallen weg. Eine Kombination allein löst diese Grenzen nicht.','',
 '## Aufbau und tatsächliche Testgrenzen','',
 '20 künstliche Bilder: sechs mit dem eingebauten imagegen-Werkzeug erzeugte Fotos, acht deterministisch erzeugte Dokument-/Screenshotbilder und sechs Grafiken. Keine privaten Medien. 62 vorab festgelegte Suchanfragen: 52 Inhaltsanfragen, sechs Dateinamen-Kontrollen und vier nicht vorhandene Inhalte. Verwandte Anfragen an dieselben Bilder sind keine unabhängigen Stichproben. Dies ist ein kleiner diagnostischer Versuch, keine repräsentative Messung einer echten Fotobibliothek.','',
 'Die aktuelle Pipeline hat alle Bilder tatsächlich mit `qwen/qwen3.8-27b` (Q4_K_M) am vorhandenen Modellserver analysiert: unveränderter Worker, Standardprompts, Vision, OCR, Bildvorbereitung und LM-Studio-SDK-Transport. Eigene lokale Quelle, eigene Datenbank, keine Veröffentlichung an produktives Nextcloud. Die hier verwendeten Einstellungen entsprechen den Repository-Defaults; individuelle abweichende Einstellungen der produktiven Installation wurden nicht ausgelesen.','',
 '1. **Normale Nextcloud-Suche:** echter OCS-Provider `files` einer isolierten Nextcloud 32.0.5 mit SQLite über HTTP. Keine zusätzliche OCR-/Volltext-App, keine manuellen Tags. Der Provider sucht Dateinamen mit `LIKE`. Drei Dateinamen enthalten Inhaltsbegriffe, die übrigen überwiegend Kamera-/Scanbezeichnungen.',
 '2. **Aktuelle Media-AI-Suche:** tatsächliche unveränderte PHP-Methoden `Metadata::validate/upsert/search`, isoliertes MariaDB 11.8.9 mit InnoDB-FULLTEXT, Binärkollation, Standard-Stopwörtern und Mindestwortlänge 3. Alle Suchwörter werden mit `+wort*` verknüpft; die Reihenfolge folgt der Datensatz-ID. Der Test ruft den Suchdienst direkt auf; Provider-HTTP, Berechtigungs-/ETag-Prüfungen und Android-Oberfläche wurden hierfür nicht gemessen.',
 '3. **Nur Metadaten-Embedding:** Beschreibung, Tags, Objekte und OCR werden zu einem Text zusammengefügt. Keine Dateinamen, keine Bildpixel, keine lexikalische Rettung.',
 '4. **Nur Bild-Embedding:** tatsächliche Bildpixel, ohne Pipeline-Metadaten, OCR-Text oder Dateinamen. Die Suche selbst bleibt eine Textanfrage; der multimodale Encoder vergleicht sie mit den Bildvektoren.',
 '5. **Beide Embeddings:** zwei getrennte Vektoren pro Bild. Gleich gewichtete Rangfusion `1/(60+Textrang) + 1/(60+Bildrang)`. Vorab festgelegt, nicht anhand der Antworten optimiert. Eine feste 50/50-Mischung der Kosinuswerte ist separat als Diagnose enthalten.','',
 'EmbeddingGemma 2: optimierter Text-Encoder 270M und Text-/Bild-Encoder 440M, vollständige **768 Dimensionen**, normalisierte Float32-Vektoren und exakter Kosinusvergleich. Textgewichte int4-QAT, Bild-Encoder int8-QAT; 140 Bild-Tokens, das Maximum dieses LiteRT-Exports. Das sind nicht die unquantisierten Originalgewichte und nicht automatisch das Maximum jedes Google-Exports. Eingabegrenzen 128–512 Text-Tokens. Modellrevisionen, Originaldateien, Quellstand, Bildprüfsummen und vorab festgelegtes Verfahren liegen daneben.','',
 'Offizielle Modellquellen: [Text/Bild-LiteRT-Modell](https://huggingface.co/litert-community/embeddinggemma-2-text-vision-440m-litert-lm), [Google-Basismodell und Suchpräfixe](https://huggingface.co/google/embeddinggemma-2), [LiteRT-Dokumentation](https://developers.google.com/edge/litert-lm/embedding_models). Primär wurden die Präfixe der Google-Modellkarte benutzt. Die abweichenden Präfixe der LiteRT-Anleitung werden separat berichtet.','',
 '## Ergebnis für Bildinhalte – 52 beantwortbare Anfragen','',table('content'),'',
 'Hit@1 bedeutet: Mindestens eines der vorab markierten passenden Bilder steht ganz oben. Hit@5 bedeutet: Mindestens eines steht unter den ersten fünf. Das ist keine Genauigkeit über alle Bilder und keine Garantie, dass die übrigen Treffer passen. MRR bewertet den Rang des ersten passenden Bilds. Bei allgemeinen Einzelwörtern gibt es Interpretationsspielraum; „Fahrrad“ wurde auf Bilder mit sichtbarem Fahrrad bezogen.','',
 '## Einschließlich Dateinamen – 58 beantwortbare Anfragen','',table('all_answerable'),'',
 'Die sechs Dateinamen-Anfragen sind eine bewusste Kontrolle. Die Embedding-Varianten erhalten keine Dateinamen und sollen diese Fähigkeit im Test nicht vortäuschen. Eine reale Suche sollte Dateiname und genaue OCR-Begriffe zusätzlich unterstützen.','',
 '## Nach Suchart','', '| Suchart | Anzahl | Nextcloud | Media AI aktuell | Metadaten-Embedding | Bild-Embedding | Beide |','|---|---:|---:|---:|---:|---:|---:|']
for g in ['Begriffe','Umschreibungen','Englisch','OCR exakt','Visuelle Details','Dateiname']:
 vals=[f"{s[k][g]['hit1']}/{s[k][g]['n']}" for k in names]
 lines.append('| '+g+' | '+str(s['normal_nextcloud'][g]['n'])+' | '+' | '.join(vals)+' |')
lines+=['','## Konkrete Beispiele','', '| Anfrage | Nextcloud | Media AI aktuell | Metadaten-Embedding | Bild-Embedding | Beide |','|---|---|---|---|---|---|']
for text in ['Vierbeiner spielt im Wasser','Zweirad neben einer Sitzbank','Fahrrad unter Baeumen','robot vacuum cleaner and a black cat','RECHNUNG 12345','12346','17:30','gelbes Kanu auf dem See','roter Kreis links vom blauen Quadrat','roter Kreis rechts vom blauen Quadrat','gelbes Dreieck ueber gruenem Stern','gelbes Dreieck unter gruenem Stern','blaues Fahrrad am Strand','RECHNUNG 12349']:
 q=next(q for q in d['queries'] if q['text']==text);cells=[]
 for k in names:
  r=d['methods'][k][q['id']];first=r[0] if r else None
  cells.append(('✓ ' if first in q['relevant'] else '✗ ') + (docs[first]['label'] if first else 'Keine Treffer'))
 lines.append('| '+text+' | '+' | '.join(cells)+' |')
lines+=['', 'Die Fehler sind teilweise komplementär: Die Metadaten finden „Fahrrad unter Baeumen“ besser, der Bild-Encoder die Anordnung „roter Kreis rechts“ oder „gelbes Dreieck ueber“. Bei der gleich gewichteten Rangfusion entstehen dabei auch Ranggleichstände; diese werden unverändert nach der vorher festgelegten Datensatzreihenfolge aufgelöst. Eine bessere Fusion könnte helfen, müsste aber mit separaten Trainings-/Validierungsdaten geprüft werden. Die Testantworten wurden hierfür nicht zum Optimieren benutzt.', '', '## Nicht vorhandene Inhalte','', '| Verfahren | Unpassende nichtleere Ergebnislisten |','|---|---:|']
for k,label in names.items():lines.append(f"| {label} | {d['absent'][k]['false_nonempty']}/4 |")
lines+=['', 'Alle drei dichten Verfahren geben ohne Schwelle immer Ergebnisse aus. Die Schwelle wurde absichtlich nicht nachträglich anhand dieser vier Anfragen angepasst. Insbesondere nahe Rechnungsnummern und Farben brauchen eine verlässliche Regel für genaue Begriffe oder ein separat geprüftes Relevanzfilterverfahren. Keine Treffer ist bei diesen vier Anfragen die richtige Antwort.','',
 '## Unverändert vorab festgelegte Zusatzdiagnosen','', '| Variante | Hit@1 für Inhalte | Hit@5 für Inhalte |','|---|---:|---:|']
for k in d['diagnostics']:
 m=s[k]['content'];lines.append(f"| {k} | {m['hit1']}/{m['n']} | {m['hit5']}/{m['n']} |")
lines+=['','Die OR-Volltextvariante ist ein Änderungsvorschlag, nicht eure aktuelle Suche. Alternative Präfixe und Score-Mischung sind Sensitivitätsprüfungen, kein nachträglich ausgewählter Testsieger.','',
 '## Gemessene Laufzeit und Speicher','',
 '- Lokaler Rechner: Intel Core i5-12400, KVM mit 10 sichtbaren vCPUs, 30 GiB RAM. Embeddings auf CPU mit vier Threads; kein lokaler GPU-Test.',
 '- Normale Nextcloud-Suche, lokaler kompletter HTTP-Aufruf: '+ms(p['normal_nextcloud_http_ms'])+'.',
 '- Media-AI-Suchdienst, ausschließlich Datenbankaufruf: '+ms(p['current_media_ai_db_ms'])+'.',
 '- Metadaten-Modell, Suchtext nach Aufwärmen: '+ms(p['metadata_timings_ms']['query'])+'.',
 '- Multimodales Modell, Suchtext: '+ms(p['image']['timings_ms']['query'])+'.',
 '- Ein Bild direkt embedden (einmalige Indexierung): '+ms(p['image']['timings_ms']['image'])+'.',
 f"- Modell laden: Text {p['metadata_model_load_ms']:.1f} ms, multimodal {p['image']['load_ms']:.1f} ms; Gewichte lagen bereits lokal, kein belastbarer Kaltstart nach Neustart.",
 f"- Peak-RSS des Text-Benchmarkprozesses inklusive Ergebnis-/Scanarrays: {p['metadata_peak_rss_mib']:.1f} MiB; separater multimodaler Encoderprozess: {p['image']['peak_rss_mib']:.1f} MiB. Keine reine Modell-RAM-Messung.",
 '- 30.000 wiederholte Vektoren, exakter Vergleich plus Top-5-Auswahl: ein Index '+ms(p['30k_repeated_vectors_scan']['one_index'])+', zwei Indizes mit Kosinusmischung '+ms(p['30k_repeated_vectors_scan']['two_indexes'])+', beide getrennt mit Rangfusion '+ms(p['30k_repeated_vectors_scan']['two_indexes_rrf'])+'. Ausschließlich Rechenzeit; keine 30.000 verschiedenen Fotos und keine End-to-End-Netzwerk-/Android-Messung.',
 '- Speicher für 30.000 Bilder × 768 Dimensionen: ein Float32-Index **92,16 MB** (87,89 MiB), zwei **184,32 MB** (175,78 MiB). Float16 halbiert das auf 46,08 bzw. 92,16 MB; Dateizuordnung, Suchindex und Modelle kommen zusätzlich dazu.',
 f"- Float16-Speicherung der Metadaten-Vektoren änderte bei {p['float16_metadata_top5_changed_queries']}/62 Anfragen die Reihenfolge/Inhalte der ersten fünf Treffer. Das ist keine allgemeine Qualitätsgarantie.",
 f"- Minimale Kosinusübereinstimmung der Suchvektoren aus Text- und Text/Bild-Export: {p['text_only_vs_multimodal_query_min_cosine']:.8f}. Gleiche Modellfamilie allein ist keine allgemeine Kompatibilitätsgarantie; diese konkreten Exporte wurden hier verglichen.",
 '', 'Die obigen Zeiten messen unterschiedliche Grenzen: HTTP, SQL und lokale Modellinferenz dürfen nicht als direkt vergleichbare End-to-End-Zeiten ausgelegt werden. Der große Qwen-Server wurde nur zur einmaligen Metadatenerzeugung gebraucht. Die Gallery-App ist durch diesen Test nicht verändert und Offline-Suche am Handy wurde nicht getestet.','',
 '## Artefakte und Wiederholung','',
 '[Interaktiver Vergleich](compare.html) zeigt für jede Anfrage die ersten fünf Bilder aller Verfahren, die erwarteten Bilder und die wirklich erzeugten Metadaten. `results.json` enthält alle Ränge, Scores, Metriken und Zeiten. `fixtures.json` enthält den vorab festgelegten Bildbestand und die Solltreffer. `provenance.json`, `protocol.json`, Modellrevisionen, `.npy`-Vektoren, Pipeline-Konfiguration und vollständige Logs erlauben Prüfung und Wiederholung.','',
 'Zum erneuten reinen Retrieval-Vergleich `rerun.py` mit der Python-Umgebung aus dem übergeordneten Benchmark ausführen. Das nutzt die bereits erzeugten, eingefrorenen Metadaten und erstellt eigene kurzlebige Nextcloud-/MariaDB-Container. Die probabilistische Qwen-Erkennung wird nicht still erneut erzeugt. Ein erneuter vollständiger Pipeline-Lauf ist separat mit `run_inference.py` möglich, bei vorhandener State-Datenbank aber aus dem Pipeline-Cache.','']
inference=json.loads((ROOT/'inference-results.json').read_text());lines.insert(lines.index('## Gemessene Laufzeit und Speicher')+2,f"Pipeline-Analyse aller 20 Bilder: {inference['wall_seconds']:.1f} Sekunden Wandzeit; alle 20 fertig. Serverseitige Auslastung und Reasoning beeinflussen diese Laufzeit.\n")
if (ROOT/'verification.json').exists():
 verification=json.loads((ROOT/'verification.json').read_text())
 if verification['dataset_sha256']==d['dataset_sha256'] and verification['retrieval_repeat_identical']:
  lines+=['## Verifikation','', 'Ein zweiter vollständiger Retrieval-Lauf mit neuen Nextcloud-/MariaDB-Testinstanzen und denselben eingefrorenen Metadaten lieferte exakt dieselben Trefferlisten für alle 62 Anfragen, auch bei den separat ausgewiesenen Zusatzdiagnosen. Die Qwen-Erkennung wurde einmal ausgeführt. Bildprüfsummen, Solltreffer und unveränderter Quellstand wurden geprüft. Filter, Einzelanfragen und Kategorieauswertung des interaktiven Berichts wurden im Browser funktional geprüft. Beide Durchläufe haben ihre kurzlebigen Testcontainer samt anonymen Volumes wieder entfernt.','']
(ROOT/'REPORT.md').write_text('\n'.join(lines))
thumbs=ROOT/'thumbs';thumbs.mkdir(exist_ok=True)
for x in d['documents']:
 im=Image.open(ROOT/'images'/x['path']).convert('RGB');im.thumbnail((350,220));im.save(thumbs/(x['key']+'.jpg'),quality=82)
compact={**d,'performance_compact':{'Suchtext CPU Textmodell':ms(p['metadata_timings_ms']['query']),'Bild einmalig embedden':ms(p['image']['timings_ms']['image']),'30000 Vektoren nur Scan':ms(p['30k_repeated_vectors_scan']['one_index']),'30000 Bilder, ein / zwei Float32-Indizes':'92,16 / 184,32 MB'}}
compact.pop('performance');compact.pop('scores');compact.pop('diagnostics');compact.pop('protocol')
for x in compact['documents']:x.pop('metadata',None)
html=(ROOT/'report-template.html').read_text().replace('__BENCHMARK_DATA__',json.dumps(compact,ensure_ascii=False).replace('</',r'<\/'))
(ROOT/'compare.html').write_text(html)
print(ROOT/'REPORT.md')
