"""Hybrid retrieval comparison using verified real Gemma embeddings and cached SQL results.
Only synthetic fixtures; never invokes inference or reads private media.
"""
import os
os.environ.setdefault('OPENBLAS_NUM_THREADS', '1')
import collections
import hashlib
import html
import importlib.util
import json
import math
import shutil
from pathlib import Path
import numpy as np

ROOT = Path(__file__).resolve().parent
SOURCE = ROOT.parent / 'media-ai-vector-comparison-20261010'
SQL = ROOT.parent / 'media-ai-search-benchmark-20261009/five-way'

def sha(path):
    return hashlib.sha256(path.read_bytes()).hexdigest()

def normalize(v):
    assert np.isfinite(v).all()
    norms = np.linalg.norm(v, axis=1, keepdims=True)
    assert (norms > 1e-8).all()
    return v / norms

def metrics(ranks, queries):
    sums = collections.defaultdict(float)
    for q in queries:
        relevant = set(q['relevant'])
        hits = [i + 1 for i, k in enumerate(ranks[q['id']]) if k in relevant]
        sums['hit1_count'] += bool(hits and hits[0] == 1)
        sums['hit5_count'] += bool(hits and hits[0] <= 5)
        sums['mrr'] += 1 / hits[0] if hits else 0
        sums['recall5'] += len(set(ranks[q['id']][:5]) & relevant) / len(relevant)
        dcg = sum(1 / math.log2(i + 1) for i in hits if i <= 5)
        ideal = sum(1 / math.log2(i + 2) for i in range(min(5, len(relevant))))
        sums['ndcg5'] += dcg / ideal
    n = len(queries)
    return {'n': n, 'hit1_count': int(sums['hit1_count']), 'hit5_count': int(sums['hit5_count']),
            **{k: sums[k] / n if n else 0 for k in ('mrr', 'recall5', 'ndcg5')}}

def combine(vector, lexical, keys, weight=1, policy='rrf', depth=20):
    v = {k: i + 1 for i, k in enumerate(vector[:depth])}
    l = {k: i + 1 for i, k in enumerate(lexical)}
    candidates = [k for k in keys if k in v or k in l]
    if policy == 'text_first':
        # Exact/AND matching dominates; semantic score sorts matches, then supplies fallback.
        return sorted(candidates, key=lambda k: (k not in l, v.get(k, 999)))
    score = lambda k: ((1 / (60 + v[k])) if k in v else 0) + ((weight / (60 + l[k])) if k in l else 0)
    return sorted(candidates, key=lambda k: -score(k))

def run():
    data = json.loads((SOURCE / 'dataset.json').read_text())
    manifest = json.loads((SOURCE / 'verification.json').read_text())['artifacts']
    inputs = ['dataset.json', 'image-vectors-768.npy', 'joint-vectors-768.npy',
              'query-vectors-768.npy', 'query_model_card-vectors-768.npy']
    for name in inputs:
        assert sha(SOURCE / name) == manifest[name], name
    assert sha(SOURCE / 'dataset.json') == sha(SQL / 'dataset.json')
    # The actual MariaDB branch has identical predicates/order/limits; app table identity changed.
    current = (ROOT.parent / 'source-snapshot/current-Metadata.php').read_text()
    previous = (ROOT.parent / 'source-snapshot/previous-Metadata.php').read_text()
    def mysql_branch(s):
        part = s[s.index("        $query = implode(' ', array_map"):]
        return part.replace('`*PREFIX*media_ai_meta`', '*PREFIX*nextgallery_ai_meta')
    assert mysql_branch(current) == mysql_branch(previous)
    lex = json.loads((SQL / 'baseline-mariadb.json').read_text())
    keys = [d['key'] for d in data['documents']]
    ids = [q['id'] for q in data['queries']]
    assert set(ids) == set(lex['queries'])
    for row in lex['queries'].values():
        for field in ('keys', 'relaxed_keys'):
            assert len(row[field]) == len(set(row[field])) and set(row[field]) <= set(keys)
    protocol = {
        'new_comparison_parameters_fixed_before_evaluation': True,
        'reuse_real_inference': True, 'new_model_calls': 0,
        'dataset_sha256': sha(SOURCE / 'dataset.json'),
        'vector_sha256': {name: sha(SOURCE / name) for name in inputs if name.endswith('.npy')},
        'lexical_results_sha256': sha(SQL / 'baseline-mariadb.json'),
        'model': json.loads((SOURCE / 'protocol.json').read_text())['model'],
        'joint_input': 'One Gemma call: image + existing description/tags/objects, no OCR',
        'image_input': 'One Gemma call: same image, no description/OCR',
        'lexical_primary': 'Actual cached MariaDB FULLTEXT AND-prefix matching over description, tags, objects, OCR; ordered by record id as existing app',
        'lexical_same_both_variants': True,
        'lexical_not_filename_search': True,
        'current_mysql_branch_verified': 'Identical query predicates and order after table rename; not a new DB run',
        'main_combination': 'Equal-weight reciprocal rank fusion k=60; lexical nonmatches receive zero; complete 20-document semantic ranking',
        'primary_queries': '42 content questions; 10 OCR, 6 filename, 4 absent controls separate',
        'diagnostics': ['Same fusion with OR/relevance text search', 'Lexical-first, vector orders text matches then fallback', 'Text weights 0.5, 1, 2; no tuning', '256 dimensions', 'Alternative Google model-card query prefix', 'Vector candidates top5, top10, top20'],
        'no_answer_based_threshold': True,
        'limitations': 'Reuses a previously inspected small fixed corpus; a follow-up comparison, not an unseen held-out benchmark. Qwen is not measured.',
    }
    (ROOT / 'protocol.json').write_text(json.dumps(protocol, ensure_ascii=False, indent=2) + '\n')
    (ROOT / 'dataset.json').write_bytes((SOURCE / 'dataset.json').read_bytes())
    vectors = {name: np.load(SOURCE / (name + '-vectors-768.npy')) for name in ('image','joint','query','query_model_card')}
    for name, matrix in vectors.items():
        assert matrix.shape == ((62 if name.startswith('query') else 20), 768)
        assert np.isfinite(matrix).all() and np.allclose(np.linalg.norm(matrix, axis=1), 1, atol=1e-5)
    groups = {'content': [q for q in data['queries'] if q['relevant'] and q['category'] not in ('OCR exakt', 'Dateiname')],
              'all_answerable': [q for q in data['queries'] if q['relevant']]}
    groups.update({c: [q for q in data['queries'] if q['category'] == c] for c in dict.fromkeys(q['category'] for q in data['queries']) if c != 'Nicht vorhanden'})
    def evaluate(query='query', dim=768, policy='rrf', weight=1, field='keys', depth=20):
        qv = normalize(vectors[query][:, :dim])
        v = {name: normalize(vectors[name][:, :dim]) for name in ('image','joint')}
        scores = {name: qv @ matrix.T for name, matrix in v.items()}
        ordered = {name: [[keys[i] for i in np.argsort(-row, kind='stable')] for row in matrix] for name, matrix in scores.items()}
        methods = {name: dict(zip(ids, rows)) for name, rows in ordered.items()}
        methods['text'] = {qid: lex['queries'][qid][field] for qid in ids}
        for name in ('image','joint'):
            methods[name + '_text'] = {qid: combine(methods[name][qid], methods['text'][qid], keys, weight, policy, depth) for qid in ids}
        summary = {name: {group: metrics(ranks, qs) for group, qs in groups.items()} for name, ranks in methods.items()}
        pairs = collections.Counter()
        for q in groups['content']:
            a = methods['image_text'][q['id']][0] in q['relevant']
            b = methods['joint_text'][q['id']][0] in q['relevant']
            pairs['both_correct' if a and b else 'image_only' if a else 'joint_only' if b else 'both_wrong'] += 1
        absent = {method: {q['id']: bool(ranks[q['id']]) for q in data['queries'] if not q['relevant']} for method,ranks in methods.items()}
        return {'summary': summary, 'ranks': methods, 'paired_content_hit1': dict(pairs), 'absent_nonempty': absent,
                'cosine': {name: score.tolist() for name,score in scores.items()}}
    result = {'primary': evaluate(), 'diagnostics': {}}
    for name, args in [
        ('or_relevance', {'field':'relaxed_keys'}), ('text_first', {'policy':'text_first'}),
        ('text_weight_half', {'weight':0.5}), ('text_weight_double', {'weight':2}),
        ('dimension_256', {'dim':256}), ('model_card_query', {'query':'query_model_card'}),
        ('top5_candidates', {'depth':5}), ('top10_candidates', {'depth':10}),
    ]:
        result['diagnostics'][name] = evaluate(**args)
    original = json.loads((SOURCE / 'results.json').read_text())['primary']
    for name in ('image','joint'):
        assert result['primary']['ranks'][name] == original['ranks'][name]
    (ROOT / 'results.json').write_text(json.dumps(result, ensure_ascii=False, indent=2) + '\n')
    return data, protocol, result

def report(data, protocol, result):
    s = result['primary']['summary']
    rows = ['# Bild-Embedding + Textsuche oder gemeinsames Bild/Text-Embedding + Textsuche?', '',
            '20 ausschließlich künstliche Bilder; echte gespeicherte Gemma-Vektoren und echte gespeicherte MariaDB-Suchergebnisse. Vergleich vom 10.10.2026.', '',
            '## Hauptvergleich', '', '| Verfahren | Inhalt: Top 1 (42) | Inhalt: Top 5 (42) | Alle: Top 1 (58) | MRR Inhalt |',
            '|---|---:|---:|---:|---:|']
    labels = {'text':'Textsuche allein', 'image':'Bild-Embedding allein', 'joint':'Bild + Beschreibung gemeinsam allein',
              'image_text':'Bild-Embedding + dieselbe Textsuche', 'joint_text':'Gemeinsames Bild/Text-Embedding + dieselbe Textsuche'}
    for name,label in labels.items():
        a=s[name]['content']; b=s[name]['all_answerable']
        rows.append(f"| {label} | {a['hit1_count']}/42 | {a['hit5_count']}/42 | {b['hit1_count']}/58 | {a['mrr']:.3f} |")
    rows += ['', 'Beide Hybridvarianten verwenden identische Texttreffer (Beschreibung, Tags, Objekte, OCR) und identische Rangfusion, k=60, Gewicht 1:1. Der gemeinsame Vektor enthält Beschreibung, Tags und Objekte; OCR bleibt in der Textsuche. Die aktuelle Textsuche verlangt alle Suchwörter und sortiert nach Datensatz-ID. Dateinamen sind in dieser Textsuche nicht enthalten.', '',
             '## Kategorien', '', '| Kategorie | Bild + Textsuche: Top 1 | Gemeinsam + Textsuche: Top 1 |', '|---|---:|---:|']
    for category in dict.fromkeys(q['category'] for q in data['queries']):
        if category=='Nicht vorhanden': continue
        a=s['image_text'][category]; b=s['joint_text'][category]
        rows.append(f"| {category} | {a['hit1_count']}/{a['n']} | {b['hit1_count']}/{b['n']} |")
    rows += ['', '## Abhängigkeit von der Zusammenführung', '', '| Gleiche Variante für beide | Bild + Text: Top 1 Inhalt | Gemeinsam + Text: Top 1 Inhalt | Bild + Text: Top 1 alle | Gemeinsam + Text: Top 1 alle |', '|---|---:|---:|---:|---:|']
    for name,r in {'primary':result['primary'], **result['diagnostics']}.items():
        a=r['summary']['image_text']; b=r['summary']['joint_text']
        rows.append(f"| {name} | {a['content']['hit1_count']}/42 | {b['content']['hit1_count']}/42 | {a['all_answerable']['hit1_count']}/58 | {b['all_answerable']['hit1_count']}/58 |")
    rows += ['', 'Diese Diagnosen wurden vor dem neuen Vergleich festgelegt. Keine Variante wurde anhand ihrer richtigen Antworten zum Gewinner optimiert.', '',
             '## Abweichende erste Treffer', '']
    for q in data['queries']:
        if not q['relevant']: continue
        a=result['primary']['ranks']['image_text'][q['id']][0]; b=result['primary']['ranks']['joint_text'][q['id']][0]
        if (a in q['relevant']) != (b in q['relevant']):
            rows.append(f"- **{q['text']}** ({q['category']}): Bild + Text → `{a}`; gemeinsam + Text → `{b}`. Soll: {', '.join(q['relevant'])}.")
    timings=json.loads((SOURCE/'inference.json').read_text())['timings_ms']
    rows += ['', '## Kosten und Grenzen', '',
             '- Beide Varianten speichern einen 768-dimensionalen Vektor je Bild. Die Vektorsuche und der Rohvektorspeicher sind gleich groß.',
             f"- Vorher tatsächlich gemessene Embedding-Zeit auf CPU (Median; 20 Bilder): nur Bild {np.median(timings['image']):.1f} ms, gemeinsam {np.median(timings['joint']):.1f} ms. Kein neuer End-to-End- oder Server-Latenztest.",
             '- Wer Textsuche in KI-Beschreibungen nutzen will, muss diese Beschreibungen weiterhin erzeugen. Ein Bild-Vektor spart diese Beschreibungspipeline nur, wenn man bei der Textsuche auf die Beschreibung verzichtet. OCR kann unabhängig davon benötigt werden.',
             '- Alle vier Anfragen nach nicht vorhandenen Inhalten liefern bei beiden Hybridvarianten trotzdem Treffer, weil ungefilterte Vektorsuche immer ähnliche Bilder liefert. Die Textsuche allein bleibt bei allen vier leer. Es wurde keine Schwelle auf diesen vier Beispielen angepasst.',
             '- Wiederverwendeter kleiner Testbestand, schon aus früheren Vergleichen bekannt: kein unabhängiger großer Nachweis. 20 Bilder, 42 Inhaltsfragen, 10 OCR-Fragen, 6 Dateinamenfragen, 4 Leer-Kontrollen. Der Dateiname-Test bewertet hier keine separate Dateinamen-Suche.',
             '- Nur EmbeddingGemma 2 gemessen. Dieses Ergebnis beweist keine Überlegenheit bei Qwen3-VL-Embedding.',
             '- Die SQL-Suchergebnisse wurden aus dem früheren echten isolierten MariaDB-Test übernommen. Die MariaDB-Suchlogik im aktuellen NextGallery-Code wurde gegen den damaligen Code auf identische Prädikate, Sortierung und Grenzen geprüft; keine neue Datenbankabfrage und kein Zugriff auf Produktionsdaten.',
             '- Bildorientierung, Vektoren, Suchanfragen und Antworten bleiben unverändert. Die SHA-256-Prüfsummen stehen in protocol.json; keine neue Inferenz, keine privaten Medien, keine Änderung an der Pipeline.',
             '', '## Entscheidung', '',
             'Im Hauptvergleich: Bild-Vektor plus Textsuche 39/42, gemeinsamer Vektor plus dieselbe Textsuche 40/42 richtige erste Inhaltstreffer. Beide erreichen 42/42 in den ersten fünf. Ein knapper Unterschied, kein belastbarer allgemeiner Qualitätsnachweis. Gemeinsam gewinnt zwei Inhaltsfragen und verliert eine; bei gelbem Dreieck über beziehungsweise unter einem Stern kehrt sich die Stärke der beiden Varianten um. Die OR-Textsuche ergibt bei beiden nur 33/42 und zeigt, dass die Zusammenführung mehr ausmachen kann als der Eingabemodus. Empfehlung: beide konfigurierbaren Modi behalten. Ohne Beschreibungen nur Bild; vorhandene Beschreibungen können im gemeinsamen Vektor mitgenutzt werden. Exakte OCR- und Dateinamen-Suche gezielt behandeln; ungefilterte Vektortreffer sind keine sichere Aussage, dass gesuchte Inhalte vorkommen.', '']
    (ROOT/'REPORT.md').write_text('\n'.join(rows))
    shutil.copytree(SOURCE/'thumbs', ROOT/'thumbs', dirs_exist_ok=True)
    docs={d['key']:d for d in data['documents']}
    def cell(ranking,q):
        parts=[]
        for rank,key in enumerate(ranking[:5],1):
            d=docs[key]; good=key in q['relevant']
            parts.append(f'<li class="{"correct" if good else ""}"><img src="thumbs/{html.escape(key)}.jpg" alt=""><span>{rank}. {html.escape(d["label"])}{" ✓" if good else ""}</span></li>')
        return '<ol>'+''.join(parts)+'</ol>'
    tables=[]
    for q in data['queries']:
        a=result['primary']['ranks']['image_text'][q['id']]; b=result['primary']['ranks']['joint_text'][q['id']]
        disagree=bool(q['relevant']) and ((a[0] in q['relevant']) != (b[0] in q['relevant']))
        tables.append(f'<tr data-category="{html.escape(q["category"])}" data-disagree="{str(disagree).lower()}"><td><b>{html.escape(q["text"])}</b><small>{html.escape(q["category"])}</small><p>Soll: {html.escape(", ".join(q["relevant"]) or "kein Treffer")}</p></td><td>{cell(a,q)}</td><td>{cell(b,q)}</td></tr>')
    a=s['image_text']['content']; b=s['joint_text']['content']
    categories=''.join(f'<option>{html.escape(c)}</option>' for c in dict.fromkeys(q['category'] for q in data['queries']))
    page=f'''<!doctype html><html lang="de"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Bild oder gemeinsam – mit Textsuche</title><style>body{{font:15px system-ui;background:#f5f6f8;color:#15222c;margin:0}}main{{max-width:1300px;margin:auto;padding:30px}}h1{{font-size:28px}}p{{line-height:1.5}}.summary{{display:flex;gap:20px;margin:25px 0}}.card{{background:white;border:1px solid #dce1e8;border-radius:14px;padding:20px;flex:1}}.card b{{display:block;font-size:34px;margin:12px 0}}table{{width:100%;border-collapse:collapse;background:white}}th,td{{padding:14px;border-bottom:1px solid #dee2e8;text-align:left;vertical-align:top}}td:first-child{{width:24%}}td:not(:first-child){{width:38%}}ol{{list-style:none;padding:0;margin:0}}li{{display:flex;align-items:center;gap:10px;padding:6px;margin:4px 0;border-radius:6px}}li.correct{{background:#e6f4e9}}img{{width:68px;height:52px;object-fit:contain;background:#eee;border-radius:5px}}small{{display:block;color:#627180;margin-top:8px}}input,select{{padding:9px;border:1px solid #c9d1d8;border-radius:6px;margin:0 12px 20px 0}}.note{{color:#4a5c68;max-width:1100px}}a{{color:#14579a}}</style><main><h1>Reicht Bild-Embedding plus Textsuche?</h1><p class="note">20 künstliche Bilder · EmbeddingGemma 2 · dieselben KI-Beschreibungen und OCR-Texte · derselbe Textsuchzweig für beide Varianten. Die Hauptwertung umfasst 42 Fragen zum Bildinhalt.</p><div class="summary"><div class="card">Bild-Embedding + Textsuche<b>{a['hit1_count']} / 42</b>richtige erste Treffer · MRR {a['mrr']:.3f}</div><div class="card">Gemeinsames Bild/Text-Embedding + Textsuche<b>{b['hit1_count']} / 42</b>richtige erste Treffer · MRR {b['mrr']:.3f}</div></div><p class="note">Grün: vorab markierte Solltreffer. Ein gemeinsamer Vektor enthält Bild, Beschreibung, Tags und Objekte. Beide Varianten nutzen zusätzlich dieselbe Textsuche in Beschreibung, Tags, Objekten und OCR. Rangfusion 1:1, k=60. Beide benötigen nur einen Vektor je Bild. Ein kleiner Folgetest; keine Aussage über Qwen oder eine große reale Galerie. <a href="REPORT.md">Vollständiger Bericht und weitere Zusammenschaltungen</a></p><select id="category"><option>Alle Kategorien</option>{categories}</select><input id="search" placeholder="Suchfrage filtern"><label><input type="checkbox" id="diff">Nur Unterschiede bei richtigem ersten Treffer</label><table><thead><tr><th>Suchfrage</th><th>Bild-Embedding + Textsuche</th><th>Gemeinsames Embedding + Textsuche</th></tr></thead><tbody>{''.join(tables)}</tbody></table></main><script>function filter(){{const c=document.querySelector('#category').value,s=document.querySelector('#search').value.toLowerCase(),d=document.querySelector('#diff').checked;document.querySelectorAll('tbody tr').forEach(r=>r.hidden=(c!=='Alle Kategorien'&&r.dataset.category!==c)||!r.firstElementChild.textContent.toLowerCase().includes(s)||(d&&r.dataset.disagree!=='true'))}}document.querySelector('#category').onchange=filter;document.querySelector('#search').oninput=filter;document.querySelector('#diff').onchange=filter;</script></html>'''
    (ROOT/'compare.html').write_text(page)

if __name__ == '__main__':
    data, protocol, result = run()
    report(data, protocol, result)
    for name, r in {'primary':result['primary'], **result['diagnostics']}.items():
        print(name, {method: r['summary'][method]['content'] for method in ('image_text','joint_text')})
    print('PAIRED', result['primary']['paired_content_hit1'])
