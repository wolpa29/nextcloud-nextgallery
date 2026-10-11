import collections, hashlib, html, json
from pathlib import Path

root=Path(__file__).resolve().parent
r=json.loads((root/'results.json').read_text())
d=json.loads((root/'dataset.json').read_text())
docs={x['key']:x for x in d['documents']}
names={
 'nextcloud_fulltext':'Aktuelle Nextcloud-Suche',
 'lexical_or_proposal':'Volltext mit OR und Relevanz (Vorschlag)',
 'embedding_768':'EmbeddingGemma 2 · 768 Dimensionen',
 'hybrid_rrf':'Kombination aus aktueller Suche und Embeddings',
 'android_substring':'Android-Textsuche (nachgebildet)',
 'worker_fts5':'Interne Worker-Suche (nachgebildet)',
 'embedding_description_only':'Embedding nur der Beschreibung',
 'embedding_split_ocr':'Embedding mit separatem OCR-Vektor',
 'embedding_litert_prefix':'Embedding mit alternativem LiteRT-Prefix',
}
group_names={'exakt':'Exakte Begriffe','umformuliert':'Umformulierte Begriffe','natuerliche_frage':'Natürliche Fragen',
 'beziehung_verneinung':'Beziehungen und Verneinungen','ocr_exakt':'Exakter OCR-Text','ocr_semantisch':'Sinngemäße OCR-Suche','kein_treffer':'Keine passende Datei'}
main=['nextcloud_fulltext','lexical_or_proposal','embedding_768','hybrid_rrf']

lines=['# Media-AI-Suchvergleich – 09.10.2026','',
 '**Ergebnis:** Im festgelegten synthetischen Testbestand findet EmbeddingGemma 2 wesentlich mehr passende Dateien auf Platz 1. Eine bessere Volltextlogik bringt ebenfalls einen Teil des Gewinns, erreicht aber die Embeddings hier nicht.','',
 '## Vergleich','',
 '56 handgeschriebene synthetische Datensätze mit Beschreibung, Tags, Objects und teilweise OCR; 54 vorher festgelegte Anfragen. 50 Anfragen haben mindestens eine passende Datei, vier haben absichtlich keine. Die Reihenfolge der Dateien wurde mit festem Zufallsstartwert gemischt. Keine privaten Bilder oder produktiven Datenbanken wurden gelesen.','',
 '| Methode | Richtiger Treffer auf Platz 1 | Richtiger Treffer unter den ersten 5 |',
 '|---|---:|---:|']
for name in main:
 s=r['metrics'][name]['all'];lines.append(f"| {names[name]} | {s['hit1']}/{s['n']} ({s['hit1']/s['n']:.0%}) | {s['hit5']}/{s['n']} ({s['hit5']/s['n']:.0%}) |")
lines+=['', '**Die ersten fünf enthalten einen richtigen Treffer** bedeutet nicht, dass alle fünf richtig sind. Die semantische Suche liefert auch ähnliche, aber unpassende Kandidaten.','',
 '| Anfragegruppe | Aktuelle Suche: Platz 1 | OR-Volltext: Platz 1 | Embedding: Platz 1 |','|---|---:|---:|---:|']
for group in ['exakt','umformuliert','natuerliche_frage','beziehung_verneinung','ocr_exakt','ocr_semantisch']:
 values=[r['metrics'][n][group] for n in main[:3]]
 lines.append('| '+group_names[group]+' | '+' | '.join(f"{v['hit1']}/{v['n']}" for v in values)+' |')

lines+=['','## Konkrete Beispiele','', '| Anfrage | Aktuelle Suche | Embedding: Platz 1 | Bewertung |','|---|---|---|---|']
for qid in ['q01','q14','q17','q24','q47','q35','q38','q53']:
 q=next(x for x in r['results'] if x['id']==qid)
 current=q['results']['nextcloud_fulltext'];first=q['results']['embedding_768'][0]
 current_text='Kein Treffer' if not current else '; '.join(docs[x]['description'] for x in current[:2])
 verdict='Richtig' if first in q['relevant'] else 'Falsch / keine passende Datei vorhanden' if not q['relevant'] else 'Falsch'
 lines.append(f"| {q['text']} | {current_text} | {docs[first]['description']} | {verdict} |")
lines+=['','## Fehler und wichtige Grenzen','',
 '- „Hund links neben Katze“: Das Embedding setzt die vertauschte Anordnung auf Platz 1; die richtige Datei ist auf Platz 2.',
 '- „Wanderer ohne Schnee“: Winterwanderung fälschlich auf Platz 1; Sommerwanderung erst auf Platz 3.',
 '- Die vier Anfragen ohne passende Datei werden von der aktuellen UND-Suche korrekt leer beantwortet. Embedding und die getestete Hybridvariante liefern bei allen vier unpassende Kandidaten. OR-Volltext liefert bei drei der vier Anfragen unpassende Kandidaten.',
 '- Ein universeller Cosine-Grenzwert ist mit diesem Test nicht belegt. Die falsche Rechnung bei „Rechnung 9988776655“ erreicht ungefähr 0,706; der richtige Treffer bei der vorhandenen Nummer „471839“ ungefähr 0,709. Sehr ähnliche Scores ersetzen keine exakte Nummernprüfung.',
 '- Der festgelegte Hybridversuch nutzt Reciprocal Rank Fusion, k=60, je 20 Kandidaten. Er verbessert hier den Platz-1-Wert gegenüber Embedding allein nicht und löst weder Verneinungen noch fehlende Treffer.',
 '- Nur Beschreibungen einzubetten erreicht 44/50 richtige Treffer auf Platz 1; Beschreibung + Tags + Objects + OCR erreicht 48/50. Daraus folgt kein isolierter Effekt der Tags: In der ersten Variante fehlt auch OCR.',
 '- Separates OCR und der alternative dokumentierte Prefix wurden als Robustheitsvarianten getestet und sind nicht die Grundlage einer nachträglichen Anpassung des Testbestands.',
 '', '## Laufzeit auf diesem Rechner','',
 f"CPU: {r['system']['cpu']} in einer Linux-VM, Embedding-Laufzeit mit vier CPU-Threads. Keine GPU verwendet.", '',
 '| Messung | Median | 95. Perzentil |','|---|---:|---:|']
for key,label in [('query_official','Embedding einer Suchanfrage (54 Anfragen)'),('vector_scan_30000','Vergleich mit 30.000 Vektoren und Auswahl der ersten 5 (20 Wiederholungen)'),('nextcloud_sql','Aktuelle MariaDB-Suche im 56-Dateien-Testbestand')]:
 t=r['timing'][key];lines.append(f"| {label} | {t['median_ms']:.2f} ms | {t['p95_ms']:.2f} ms |")
lines+=['',
 f"Erneuter Modellstart mit vorhandenen Caches: {r['timing']['model_load_ms']:.0f} ms. Ein echter Kaltstart auf einem Handy wurde nicht gemessen.",
 '', 'Die 30.000 Vektoren wurden aus den 56 Testvektoren vervielfacht: Diese Messung prüft ausschließlich Rechenaufwand und Speicherumfang, nicht die Trefferqualität einer echten 30.000-Dateien-Galerie. Die Matrix umfasst genau 92.160.000 Bytes (92,16 MB / 87,89 MiB). SQL wurde nicht mit 30.000 Dateien verglichen. Netzwerk, Nextcloud-Dateiauflösung, Rechte-/ETag-Prüfung, App-Oberfläche und Festplattenladen der Vektoren fehlen in den Laufzeitwerten.',
 '', '## Ausführung und Nachvollziehbarkeit','',
 f"- Modell: `{r['model']['repo']}`; Revision `{r['model']['revision']}`.",
 '- Optimierter offizieller LiteRT-Export mit int4-QAT-Textgewichten, volle 768 Ausgabedimensionen, normalisierte float32-Vektoren. Kein Test des unquantisierten Google-Checkpoints.',
 f"- Laufzeit: LiteRT-LM {r['system']['libraries']['litert-lm']}; Modell SHA-256 `{r['model']['sha256']}`.",
 f"- Testdaten SHA-256 `{r['dataset_sha256']}`.",
 f"- Datenbank: {r['database']['version']}, `utf8mb4_bin`, InnoDB-FULLTEXT, Mindestwortlänge 3, Stopwörter aktiv.",
 '- Baseline ruft die unveränderten Methoden `Metadata::validate`, `upsert` und `search` auf; Nextcloud-Schnittstellen werden nur für den Datenbankadapter ersetzt. Kopie und Herkunft des Quellcodes liegen im Projekt.',
 '- Die Variante OR-Volltext ist ein zusätzlicher Vorschlag, keine vorhandene Produktfunktion. Sie wurde nach dem ersten Embedding-Lauf ergänzt, ohne die Daten oder Relevanzlabels zu ändern; danach wurden alle Varianten erneut ausgeführt.',
 '- Die Android- und Worker-Varianten bilden die aus dem Code gelesene Textnormalisierung/Abfrage nach. Die Android-Sortierung nach Dateidatum wird hier nicht nachgebildet; ihre Platz-1-Werte sind daher keine App-Messung.',
 '- Die beiden offiziellen Dokumentationen nennen unterschiedliche Suchprefixe. Primär wurden die Prefixe der Google-Modellkarte verwendet; die LiteRT-Prefixe stehen als eigene Variante im Rohbericht.',
 '', '## Einordnung','',
 'Der Test rechtfertigt einen Pilot für eine zusätzliche semantische Suche. Er beweist keine allgemeine Trefferquote von 96 Prozent für private Galerien. Getestet wurde die Suche über bereits vorhandene Textmetadaten; direkte Bild-/Video-Embeddings und die Qualität der ursprünglichen KI-Beschreibung wurden nicht geprüft.',
 '', 'Exakte Begriffe und Nummern sollten weiterhin mit der Wortsuche geprüft werden. Ähnliche Kandidaten brauchen eine passende Darstellung oder eine separat validierte Ablehnungsregel. Bei Verneinungen und räumlichen Beziehungen bleiben Grenzen sichtbar.',
 '', '## Dateien','',
 '- `compare.html`: durchsuchbarer Vergleich aller Anfragen und ihrer Kandidaten.',
 '- `dataset.json`: vollständige Eingaben und vorher festgelegte Solltreffer.',
 '- `results.json`: sämtliche Metriken, Messungen und die ersten zehn Kandidaten je Methode.',
 '- `baseline-mariadb.json`: rohe Antworten der aktuellen MariaDB-Suche und OR-Variante.',
 '- `document-vectors-768.npy` / `query-vectors-768.npy`: gemessene Embeddings.',
 '- `benchmark.py`, `baseline.php`, `build_dataset.py`, `rerun.py`, `requirements.lock.txt`: Wiederholung des Versuchs.',
 '', 'Modellquellen: [Google-Modellkarte](https://ai.google.dev/gemma/docs/embeddinggemma/model_card_2), [LiteRT-Textmodell](https://huggingface.co/litert-community/embeddinggemma-2-text-270m-litert-lm), [LiteRT-Embedding-Dokumentation](https://developers.google.com/edge/litert-lm/embedding_models).','']
(root/'REPORT.md').write_text('\n'.join(lines))

payload={'report':r,'documents':docs,'names':names,'groups':group_names}
serialized=json.dumps(payload,ensure_ascii=False).replace('<','\\u003c')
page=r'''<!doctype html>
<html lang="de"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Media AI · Suchvergleich</title>
<style>
:root{font-family:system-ui,sans-serif;color:#202b3a;background:#f3f5f7;font-size:15px}*{box-sizing:border-box}body{margin:0}main{max-width:1280px;margin:auto;padding:34px 24px}h1{font-size:32px;letter-spacing:-1px;margin:6px 0 10px}h2{font-size:20px}.eyebrow{font-size:12px;font-weight:650;letter-spacing:.08em;color:#596b7b}p{line-height:1.6;color:#506173}a{color:#245a89}.summary{display:grid;grid-template-columns:repeat(3,1fr);gap:16px;margin:24px 0}.card,.panel{background:white;border:1px solid #dce2e8;border-radius:8px;padding:20px}.card strong{display:block;font-size:32px;margin:8px 0}.card small{color:#637386}.notice{background:#fff8e9;border:1px solid #eedec0;border-radius:8px;padding:14px 18px;line-height:1.6;color:#66512c}.toolbar{display:flex;gap:10px;margin:18px 0}input,select{padding:11px 12px;border:1px solid #bbc6d1;border-radius:6px;background:white;color:inherit;font:inherit}input{flex:1}table{border-collapse:collapse;width:100%}th,td{text-align:left;border-bottom:1px solid #e2e6ec;padding:12px 10px;vertical-align:top}th{font-size:12px;color:#657386}td:first-child{max-width:460px}.case{cursor:pointer}.case:hover{background:#f1f5f9}.pill{font-size:12px;display:inline-block;padding:3px 8px;border-radius:4px;background:#eef2f6;color:#506173}.ok{background:#e5f3ed;color:#216849}.bad{background:#faeeeb;color:#913e30}.empty{background:#edf0f4;color:#5d6a78}dialog{width:min(1160px,95vw);max-height:90vh;border:1px solid #cbd4dd;border-radius:10px;padding:25px;color:inherit}dialog::backdrop{background:#17283988}.close{float:right;border:1px solid #bcc7d1;background:white;border-radius:6px;padding:8px 13px;cursor:pointer}.columns{display:grid;grid-template-columns:repeat(2,1fr);gap:18px}.column{background:#f5f7f9;padding:16px;border-radius:7px}.result{background:white;border:1px solid #dbe2e8;border-left:3px solid #dbe2e8;margin:10px 0;padding:12px;line-height:1.5}.result.correct{border-left-color:#318065}.result small{color:#657386}.result p{margin:6px 0;color:#36495c;font-size:14px}.score{font-variant-numeric:tabular-nums;color:#5d6a78;font-size:12px}details{margin-top:20px}pre{white-space:pre-wrap;font-size:12px;line-height:1.5}.hint{font-size:13px}footer{margin-top:24px;font-size:13px;color:#637386}@media(max-width:760px){main{padding:22px 12px}.summary,.columns{grid-template-columns:1fr}.toolbar{flex-direction:column}.card strong{font-size:26px}th,td{padding:10px 5px;font-size:13px}}
</style><main>
<div class="eyebrow">MEDIA AI · SYNTHETISCHER TEST · 09.10.2026</div>
<h1>Was bringt die semantische Suche?</h1>
<p>56 künstliche Metadatensätze · 54 festgelegte Anfragen · EmbeddingGemma 2 mit vollen 768 Dimensionen.<br>50 Anfragen haben passende Dateien. Vier prüfen den Fall „nicht vorhanden“. Keine privaten Medien verwendet.</p>
<div class="summary" id="summary"></div>
<div class="notice">Die Werte zählen passende Treffer auf Platz 1 im kleinen Testbestand. Sie sind keine allgemeine Trefferquote für echte Galerien. Embeddings liefern ohne Ablehnungsregel auch dann ähnliche Kandidaten, wenn keine Datei passt.</div>
<h2>Alle Suchanfragen</h2><p class="hint">Eine Zeile öffnen, um die ersten fünf Kandidaten und die gespeicherten Beschreibungen zu vergleichen. Grün markiert einen vorher festgelegten Solltreffer.</p>
<div class="toolbar"><input id="filter" placeholder="Suchanfrage finden …" aria-label="Suchanfrage finden"><select id="group" aria-label="Anfragegruppe"><option value="">Alle Gruppen</option></select></div>
<div class="panel" style="padding:0;overflow:auto"><table><thead><tr><th>Suchanfrage</th><th>Aktuell</th><th>OR-Volltext</th><th>Embedding</th></tr></thead><tbody id="rows"></tbody></table></div>
<h2>Gemessener Rechenaufwand</h2><div class="panel" id="performance"></div>
<footer>Nur Suche über Textmetadaten. Die 30.000 Vektoren sind für die Zeitmessung vervielfacht; die Qualitätsprüfung umfasst 56 Datensätze. Linux-CPU-Test, keine Messung auf einem Handy. <a href="REPORT.md">Ausführlicher Bericht</a> · <a href="results.json">Rohdaten</a></footer>
</main><dialog id="dialog"><button class="close" id="close">Schließen</button><h2 id="title"></h2><p id="expected"></p><div class="columns" id="columns"></div><details><summary>Festgelegte Solltreffer und vollständige Metadaten</summary><pre id="raw"></pre></details></dialog>
<script id="data" type="application/json">__PAYLOAD__</script><script>
const D=JSON.parse(document.getElementById('data').textContent),R=D.report;
const methods=['nextcloud_fulltext','lexical_or_proposal','embedding_768'];
const esc=s=>String(s).replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
const $=id=>document.getElementById(id);
$('summary').innerHTML=methods.map(n=>{let s=R.metrics[n].all;return `<div class="card">${esc(D.names[n])}<strong>${s.hit1} / ${s.n}</strong><small>Richtiger Treffer auf Platz 1 · ${Math.round(100*s.hit1/s.n)} %</small></div>`}).join('');
Object.entries(D.groups).forEach(([k,v])=>$('group').add(new Option(v,k)));
function status(q,n){let a=q.results[n];if(!q.relevant.length)return `<span class="pill ${a.length?'bad':'ok'}">${a.length?'Unpassende Kandidaten':'Korrekt leer'}</span>`;let rank=a.findIndex(k=>q.relevant.includes(k));return `<span class="pill ${rank===0?'ok':rank>=0?'empty':'bad'}">${rank<0?'Kein Solltreffer':`Platz ${rank+1}`}</span>`}
function render(){let f=$('filter').value.toLocaleLowerCase('de'),g=$('group').value;
$('rows').innerHTML=R.results.filter(q=>(!g||q.group===g)&&q.text.toLocaleLowerCase('de').includes(f)).map(q=>`<tr class="case" tabindex="0" data-id="${q.id}"><td>${esc(q.text)}<br><small style="color:#667687">${esc(D.groups[q.group])}</small></td>${methods.map(n=>`<td>${status(q,n)}</td>`).join('')}</tr>`).join('');
document.querySelectorAll('.case').forEach(row=>{row.onclick=()=>openCase(row.dataset.id);row.onkeydown=e=>{if(e.key==='Enter')openCase(row.dataset.id)}})}
function openCase(id){let q=R.results.find(q=>q.id===id);$('title').textContent=q.text;$('expected').textContent=q.relevant.length?'Solltreffer: '+q.relevant.map(k=>D.documents[k].description).join(' / '):'Soll: Keine passende Datei vorhanden.';
$('columns').innerHTML=[...methods,'hybrid_rrf'].map(n=>`<section class="column"><b>${esc(D.names[n])}</b>${q.results[n].slice(0,5).map((k,i)=>{let d=D.documents[k],score=q.embedding_scores[n]?.find(x=>x.key===k)?.score;return `<div class="result ${q.relevant.includes(k)?'correct':''}"><small>Platz ${i+1} · ${esc(d.path)}${q.relevant.includes(k)?' · Solltreffer':''}</small><p>${esc(d.description)}</p><small>Tags: ${esc(d.tags.join(', '))}</small>${d.ocr?`<p><small>OCR: ${esc(d.ocr)}</small></p>`:''}${score!==undefined?`<div class="score">Ähnlichkeit: ${score.toFixed(4)}</div>`:''}</div>`}).join('')||'<p>Keine Treffer.</p>'}</section>`).join('');
$('raw').textContent=JSON.stringify({query:q,expected_documents:q.relevant.map(k=>D.documents[k])},null,2);$('dialog').showModal()}
let t=R.timing;$('performance').innerHTML=`<table><tr><td>Suchanfrage einbetten (Median / P95)</td><td>${t.query_official.median_ms.toFixed(1)} / ${t.query_official.p95_ms.toFixed(1)} ms</td></tr><tr><td>30.000 Vektoren vergleichen + Top 5 (Median / P95)</td><td>${t.vector_scan_30000.median_ms.toFixed(1)} / ${t.vector_scan_30000.p95_ms.toFixed(1)} ms</td></tr><tr><td>Reine Vektormatrix, 768 Dimensionen</td><td>92,16 MB</td></tr></table><p class="hint">${esc(R.system.cpu)} · 4 CPU-Threads · bereits geladenes Modell · ohne Netzwerk und App-Oberfläche.</p>`;
$('filter').oninput=render;$('group').onchange=render;$('close').onclick=()=>$('dialog').close();render();
</script></html>'''
(root/'compare.html').write_text(page.replace('__PAYLOAD__',serialized))
print('Created REPORT.md and compare.html')
