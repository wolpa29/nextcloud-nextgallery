"""Isolated, frozen-fixture comparison of separate and joint multimodal vectors."""
import os
os.environ.setdefault('OPENBLAS_NUM_THREADS', '1')
os.environ.setdefault('OMP_NUM_THREADS', '4')
import collections
import hashlib
import html
import json
import platform
import resource
import shutil
import time
from pathlib import Path

import litert_lm as lm
import numpy as np

ROOT = Path(__file__).resolve().parent
SOURCE = ROOT.parent / 'media-ai-search-benchmark-20261009/five-way'
MODEL = SOURCE / 'model/embeddinggemma-2-text-vision-440m.litertlm'
METHODS = {
    'separate_rrf': '2 Vektoren · Rangfusion',
    'joint': '1 Vektor · Bild + Beschreibung gemeinsam',
    'text': 'Kontrolle · nur Beschreibung',
    'image': 'Kontrolle · nur Bild',
    'separate_mean': 'Diagnose · 2 Vektoren, mittlere Ähnlichkeit',
    'separate_max': 'Diagnose · 2 Vektoren, beste Ähnlichkeit',
    'averaged_vector': 'Diagnose · 1 gemittelter Vektor',
    'joint_text_first': 'Diagnose · 1 gemeinsamer Vektor, Text zuerst',
}


def sha(path):
    with path.open('rb') as f:
        return hashlib.file_digest(f, 'sha256').hexdigest()


def scene(doc):
    return (doc['description'].strip() + '\nSuchbegriffe: ' + ', '.join(doc['tags'])
            + '\nObjekte: ' + ', '.join(doc['objects']))


def normalized(a):
    a = np.asarray(a, dtype=np.float32)
    norms = np.linalg.norm(a, axis=-1, keepdims=True)
    assert np.isfinite(a).all() and np.all(norms > 1e-8)
    return a / norms


def freeze():
    data = json.loads((SOURCE / 'dataset.json').read_text())
    provenance = json.loads((SOURCE / 'provenance.json').read_text())
    for name, expected in provenance['images'].items():
        assert sha(SOURCE / 'images' / name) == expected, name
    assert sha(MODEL) == provenance['model_sha256']['five-way/model/embeddinggemma-2-text-vision-440m.litertlm']
    assert sha(SOURCE / 'fixtures.json') == provenance['fixtures_sha256']
    fixtures = json.loads((SOURCE / 'fixtures.json').read_text())
    assert data['queries'] == fixtures['queries']
    assert {d['key'] for d in data['documents']} == {d['key'] for d in fixtures['documents']}
    for q in data['queries']:
        assert set(q['relevant']) <= {d['key'] for d in data['documents']}
    (ROOT / 'dataset.json').write_text(json.dumps(data, ensure_ascii=False, indent=2) + '\n')
    protocol = {
        'frozen_before_inference': True,
        'source_dataset_sha256': sha(SOURCE / 'dataset.json'),
        'source_fixtures_sha256': sha(SOURCE / 'fixtures.json'),
        'model': provenance['models']['image'], 'model_sha256': sha(MODEL),
        'runtime': 'litert-lm==0.18.0', 'backend': 'CPU, 4 threads per encoder',
        'dimensions': 768, 'vision_tokens_per_image': 140, 'max_input_length': 2048,
        'overflow': 'ERROR; no truncation/chunk averaging',
        'text_content': 'Existing pipeline description + tags + objects; excludes OCR and filename',
        'query_prefix': 'task: search query | text: ',
        'document_prefix': 'task: search result | text: ',
        'primary_two_vectors': 'Equal reciprocal rank fusion, k=60, complete corpus ranking',
        'primary_one_vector': 'One compute_embedding([ImageFile, document_text]) call, not averaging',
        'diagnostics': list(METHODS.keys())[2:],
        'primary_queries': '42 answerable content queries excluding OCR exact and filenames',
        'other_queries': 'OCR exact, filenames and four no-match controls reported separately',
        'lexical_search': 'Excluded equally from both sides to isolate vector architecture',
        'metrics': 'Hit@1, Hit@5, MRR, Recall@5, nDCG@5; paired disagreements',
        'tuning': 'None; frozen labels, equal weights; diagnostics do not replace primary comparison',
        'scale_timing': '30k repeated copies of fixture vectors; time/storage only, no quality claim',
        'time_context': '2026-10-10 Europe/Vienna',
    }
    (ROOT / 'protocol.json').write_text(json.dumps(protocol, ensure_ascii=False, indent=2) + '\n')
    (ROOT / 'thumbs').mkdir(exist_ok=True)
    for doc in data['documents']:
        shutil.copyfile(SOURCE / 'thumbs' / (doc['key'] + '.jpg'), ROOT / 'thumbs' / (doc['key'] + '.jpg'))
    shutil.copyfile(SOURCE / 'contact-sheet.jpg', ROOT / 'contact-sheet.jpg')
    return data, protocol


def inference(data):
    timing = collections.defaultdict(list)
    options = lm.EmbeddingOptions(normalize=True, output_size=768,
        vision_tokens_per_image=140, input_overflow_strategy=lm.InputOverflowStrategy.ERROR)
    matrices = {m: [] for m in ['text', 'image', 'joint', 'joint_text_first']}
    cache = ROOT / 'cache'
    cache.mkdir(exist_ok=True)
    start = time.perf_counter()
    with lm.EmbeddingEngine(str(MODEL), backend=lm.Backend.CPU(thread_count=4),
            vision_backend=lm.Backend.CPU(thread_count=4), cache_dir=str(cache),
            min_input_length=128, max_input_length=2048, vision_tokens_per_image=140) as engine:
        load_ms = (time.perf_counter() - start) * 1000
        def enc(content, label):
            st = time.perf_counter()
            v = np.asarray(engine.compute_embedding(content, options=options).embedding, dtype=np.float32)
            timing[label].append((time.perf_counter() - st) * 1000)
            assert v.shape == (768,) and np.isfinite(v).all()
            assert abs(float(np.linalg.norm(v)) - 1) < 1e-4
            return v
        first = data['documents'][0]
        text = 'task: search result | text: ' + scene(first)
        image = lm.Content.ImageFile(str(SOURCE / 'images' / first['path']))
        for content in [text, image, [image, text]]:
            enc(content, 'warmup')
        for n, doc in enumerate(data['documents']):
            text = 'task: search result | text: ' + scene(doc)
            image = lm.Content.ImageFile(str(SOURCE / 'images' / doc['path']))
            inputs = {'text': text, 'image': image, 'joint': [image, text], 'joint_text_first': [text, image]}
            order = list(inputs)
            order = order[n % 4:] + order[:n % 4]
            for label in order:
                matrices[label].append((n, enc(inputs[label], label)))
            print(f"Indexed {n + 1}/{len(data['documents'])}: {doc['key']}", flush=True)
        qv = np.stack([enc('task: search query | text: ' + q['text'].strip(), 'query') for q in data['queries']])
        # Prefix differences between Google's runtime and model-card docs are a sensitivity check.
        q_alt = np.stack([enc('task: search result | query: ' + q['text'].strip(), 'query_model_card') for q in data['queries']])
    out = {label: np.stack([v for _, v in sorted(rows)]) for label, rows in matrices.items()}
    out['query'] = qv
    out['query_model_card'] = q_alt
    for label, matrix in out.items():
        np.save(ROOT / (label + '-vectors-768.npy'), matrix)
    performance = {'load_ms': load_ms, 'peak_rss_mib': resource.getrusage(resource.RUSAGE_SELF).ru_maxrss / 1024,
                   'timings_ms': dict(timing), 'platform': platform.platform(), 'cpu': 'Intel Core i5-12400 virtual machine'}
    (ROOT / 'inference.json').write_text(json.dumps(performance, indent=2) + '\n')
    return out, performance


def scores_for(vectors, query='query', dim=768):
    v = {k: normalized(a[:, :dim]) for k, a in vectors.items()}
    q = v[query]
    text = q @ v['text'].T
    image = q @ v['image'].T
    def rank(a):
        order = np.argsort(-a, axis=1, kind='stable')
        ranks = np.empty_like(order)
        np.put_along_axis(ranks, order, np.broadcast_to(np.arange(1, a.shape[1] + 1), a.shape), axis=1)
        return ranks
    return {'text': text, 'image': image, 'joint': q @ v['joint'].T,
            'joint_text_first': q @ v['joint_text_first'].T,
            'separate_rrf': 1 / (60 + rank(text)) + 1 / (60 + rank(image)),
            'separate_mean': (text + image) / 2, 'separate_max': np.maximum(text, image),
            'averaged_vector': q @ normalized(v['text'] + v['image']).T}


def metrics(ranks, queries):
    n = len(queries)
    sums = collections.defaultdict(float)
    for q in queries:
        wanted = set(q['relevant'])
        found = [i + 1 for i, key in enumerate(ranks[q['id']]) if key in wanted]
        first = found[0] if found else 999
        sums['hit1'] += first == 1
        sums['hit5'] += first <= 5
        sums['mrr'] += 1 / first if found else 0
        sums['recall5'] += len(set(ranks[q['id']][:5]) & wanted) / len(wanted)
        dcg = sum(1 / np.log2(i + 1) for i in found if i <= 5)
        ideal = sum(1 / np.log2(i + 2) for i in range(min(5, len(wanted))))
        sums['ndcg5'] += dcg / ideal
    return {'n': n, 'hit1_count': int(sums['hit1']), 'hit5_count': int(sums['hit5']),
            **{k: val / n if n else 0 for k, val in sums.items()}}


def evaluate(data, vectors, query='query', dim=768):
    keys = [d['key'] for d in data['documents']]
    scores = scores_for(vectors, query, dim)
    ranks = {method: {q['id']: [keys[i] for i in np.argsort(-row, kind='stable')]
                     for q, row in zip(data['queries'], matrix)} for method, matrix in scores.items()}
    groups = {'primary_content': [q for q in data['queries'] if q['relevant'] and q['category'] not in ('OCR exakt', 'Dateiname')],
              'all_answerable': [q for q in data['queries'] if q['relevant']]}
    groups.update({c: [q for q in data['queries'] if q['category'] == c] for c in dict.fromkeys(q['category'] for q in data['queries']) if c != 'Nicht vorhanden'})
    summary = {method: {g: metrics(ranks[method], qs) for g, qs in groups.items()} for method in METHODS}
    paired = collections.Counter()
    for q in groups['primary_content']:
        a = ranks['separate_rrf'][q['id']][0] in q['relevant']
        b = ranks['joint'][q['id']][0] in q['relevant']
        paired['both_correct' if a and b else 'two_only' if a else 'joint_only' if b else 'both_wrong'] += 1
    return {'summary': summary, 'ranks': ranks, 'paired_hit1': dict(paired),
            'scores': {k: a.tolist() for k, a in scores.items()}}


def scale(vectors):
    q = vectors['query'][0]
    big = {k: np.tile(vectors[k], (1500, 1)) for k in ['text', 'image', 'joint']}
    times = collections.defaultdict(list)
    for repeat in range(33):
        for method in ['joint', 'separate_rrf', 'separate_mean']:
            st = time.perf_counter()
            if method == 'joint':
                scores = big['joint'] @ q
            else:
                ts = big['text'] @ q
                ims = big['image'] @ q
                if method == 'separate_mean':
                    scores = (ts + ims) / 2
                else:
                    tr = np.empty(30000, np.int32)
                    ir = np.empty(30000, np.int32)
                    tr[np.argsort(-ts, kind='stable')] = np.arange(1, 30001)
                    ir[np.argsort(-ims, kind='stable')] = np.arange(1, 30001)
                    scores = 1 / (60 + tr) + 1 / (60 + ir)
            np.argpartition(scores, -5)[-5:]
            elapsed = (time.perf_counter() - st) * 1000
            if repeat >= 3:
                times[method].append(elapsed)
    return {'n': 30000, 'distinct_documents': 20, 'times_ms': dict(times),
            'one_vector_bytes': big['joint'].nbytes, 'two_vector_bytes': big['text'].nbytes + big['image'].nbytes,
            'note': 'Exact in-memory numpy scan and ranking; repeated synthetic vectors, no vector DB or network.'}


def report(data, result):
    s = result['primary']['summary']
    lines = ['# Ein gemeinsamer oder zwei getrennte Vektoren?', '',
             '**Ergebnis:** Der gemeinsame Vektor erreicht 40/42 erste Treffer, zwei getrennte Vektoren mit Rangfusion 39/42. Zwei Vektoren mit gemittelter Ähnlichkeit erreichen ebenfalls 40/42. Es gibt in diesem Bestand keinen Qualitätsvorteil für zwei Vektoren. Ein gemeinsamer Vektor ist deshalb der einfachere Einstieg mit halbem Rohvektorspeicher; der kleine Test belegt keine generelle Überlegenheit.', '',
             'Test vom 10.10.2026. 20 ausschließlich künstliche Bilder, unveränderte echte Pipeline-Metadaten, 62 zuvor festgelegte Suchanfragen.', '',
             'Hauptvergleich: 42 inhaltliche Anfragen. Exakte OCR (10), Dateinamen (6) und nicht vorhandene Inhalte (4) sind separate Kontrollen.', '',
             '## Trefferqualität', '', '| Verfahren | Richtiger erster Treffer | Treffer in Top 5 | MRR | nDCG@5 |', '|---|---:|---:|---:|---:|']
    for method, name in METHODS.items():
        m = s[method]['primary_content']
        lines.append(f"| {name} | {m['hit1_count']}/{m['n']} ({m['hit1']:.1%}) | {m['hit5_count']}/{m['n']} | {m['mrr']:.3f} | {m['ndcg5']:.3f} |")
    lines += ['', '## Kategorien (richtiger erster Treffer)', '', '| Kategorie | Zwei Vektoren, RRF | Ein gemeinsamer Vektor |', '|---|---:|---:|']
    for group in ['Begriffe', 'Umschreibungen', 'Englisch', 'Visuelle Details', 'OCR exakt', 'Dateiname']:
        a, b = s['separate_rrf'][group], s['joint'][group]
        lines.append(f"| {group} | {a['hit1_count']}/{a['n']} | {b['hit1_count']}/{b['n']} |")
    lines += ['', '## Gemeinsame und unterschiedliche Treffer', '', str(result['primary']['paired_hit1']), '',
              '| Suchanfrage | Solltreffer | Zwei Vektoren: Platz 1 | Gemeinsamer Vektor: Platz 1 |', '|---|---|---|---|']
    rows = []
    docs = {d['key']: d for d in data['documents']}
    for q in data['queries']:
        a = result['primary']['ranks']['separate_rrf'][q['id']][0]
        b = result['primary']['ranks']['joint'][q['id']][0]
        if a != b:
            lines.append(f"| {q['text']} | {', '.join(q['relevant']) or 'Kein Treffer'} | {a} | {b} |")
        cells = []
        for method in ['separate_rrf', 'joint']:
            rank = result['primary']['ranks'][method][q['id']]
            cards = ''.join(f'<div class="card {"correct" if k in q["relevant"] else ""}"><img src="thumbs/{k}.jpg" alt="{html.escape(docs[k]["label"])}"><span>{i+1}. {html.escape(docs[k]["label"])}</span></div>' for i, k in enumerate(rank[:5]))
            cells.append('<td><div class="cards">' + cards + '</div></td>')
        rows.append(f'<tr data-category="{html.escape(q["category"])}"><td><strong>{html.escape(q["text"])}</strong><br>{html.escape(q["category"])}<br><small>Soll: {html.escape(", ".join(q["relevant"]) or "Kein Treffer")}</small></td>' + ''.join(cells) + '</tr>')
    p = result['performance']
    t = p['inference']['timings_ms']
    lines += ['', '## Laufzeit und Speicher', '',
        f"- Rechner: CPU, vier Threads; Modellladezeit {p['inference']['load_ms']:.0f} ms; maximaler Prozessspeicher {p['inference']['peak_rss_mib']:.0f} MiB.",
        f"- Indexierung für zwei Vektoren: {sum(t['text']) + sum(t['image']):.0f} ms gesamt / 20 Bilder; Median je Bild {np.median(np.array(t['text']) + np.array(t['image'])):.0f} ms.",
        f"- Indexierung gemeinsamer Vektor: {sum(t['joint']):.0f} ms gesamt / 20 Bilder; Median je Bild {np.median(t['joint']):.0f} ms.",
        f"- Suchtext einbetten: Median {np.median(t['query']):.1f} ms, identisch für beide Verfahren."]
    for method, values in p['scale']['times_ms'].items():
        lines.append(f"- 30.000 wiederholte Vektoren, {method}: Median {np.median(values):.2f} ms, p95 {np.percentile(values,95):.2f} ms.")
    lines += [f"- Rohvektoren, 768 Dimensionen/float32, 30.000 Bilder: ein Vektor {p['scale']['one_vector_bytes']/1024**2:.1f} MiB, zwei Vektoren {p['scale']['two_vector_bytes']/1024**2:.1f} MiB. Datenbank-/Index-Overhead ausgeschlossen.", '',
              '## Sensitivität', '', '| Konfiguration | Zwei Vektoren, RRF | Ein gemeinsamer Vektor |', '|---|---:|---:|']
    for config in ['primary', 'model_card_query_prefix', 'dimensions_256']:
        ss = result[config]['summary']
        a, b = ss['separate_rrf']['primary_content'], ss['joint']['primary_content']
        lines.append(f"| {config} | {a['hit1_count']}/{a['n']} | {b['hit1_count']}/{b['n']} |")
    joint = vectors_loaded('joint')
    text = vectors_loaded('text')
    image = vectors_loaded('image')
    mean = normalized(text + image)
    lines += ['', '## Durchführung und Grenzen', '',
        '- Dasselbe gepinnte multimodale Modell erzeugt Bilder, Beschreibungen, gemeinsame Eingaben und Suchtexte. Keine Modellmischung.',
        '- Hauptfall: LiteRT-Dokumentationspräfixe, 140 Bildtokens (Maximum des vorhandenen gepinnten Modellpakets), 768 Dimensionen, 2048 Kontextlimit. Überlauf löst einen Fehler aus; kein Abschneiden und kein automatisches Abschnittsmitteln. Der erste Startversuch mit 280 Bildtokens wurde vor der Inferenz vom Runtime abgewiesen und in startup-capacity-error.log aufbewahrt; anschließend wurden beide Verfahren mit denselben 140 Bildtokens getestet.',
        '- Zwei Vektoren: Beschreibung inkl. Tags/Objekte separat vom Bild; feste gleichgewichtete Rangfusion k=60. Ein Vektor: Bild und derselbe Beschreibungstext gemeinsam in einem Modellaufruf.',
        '- Alternativen (Mittelwert-/Maximal-Ähnlichkeit, gemittelter Vektor, Eingabereihenfolge) sind vorher festgelegte Diagnosen, keine nachträglich optimierten Hauptverfahren.',
        f"- Kontrollprüfung gemeinsamer Vektor: Median-Kosinus gegen Bild {np.median(np.sum(joint*image,axis=1)):.4f}, gegen Text {np.median(np.sum(joint*text,axis=1)):.4f}, gegen gemittelten Vektor {np.median(np.sum(joint*mean,axis=1)):.4f}. Unterschiedliche Repräsentationen, kein umbenannter Durchschnitt.",
        '- OCR und Dateinamen werden für beide Varianten gleichermaßen nicht in den Beschreibungstext eingebettet. Ihre exakte Suche gehört in der späteren hybriden Suche in den Textindex. Einige Inhalte können schon in der vorhandenen KI-Beschreibung stehen.',
        '- Ohne Ablehnungsschwelle liefert Vektorsuche auch bei allen vier nicht vorhandenen Anfragen Treffer. Dieser Test misst deshalb keine zuverlässige Erkennung von »nichts gefunden«.',
        '- Nur 20 unterschiedliche Bilder, darunter sechs künstliche Fotos und 14 Grafiken/Dokumente. Mehrere Fragen beziehen sich auf dasselbe Bild; die 42 Fragen sind keine 42 unabhängigen Szenen.',
        '- Bestehende Testfragen waren in einem früheren Experiment bereits ausgewertet. Hier erfolgte keine neue Auswahl/Optimierung, es handelt sich aber nicht um einen neuen blinden Holdout.',
        '- Ein kleiner diagnostischer Test ist kein allgemeiner Qualitätsnachweis für eine private Fotobibliothek. Der 30k-Lauf wiederholt Vektoren und belegt ausschließlich Rechenzeit/Rohspeicher auf diesem Rechner.',
        '- Kein produktiver Serverzugriff, keine privaten Medien, keine Änderung an Pipeline, Queue oder App. Keine Gesichtserkennung getestet.', '',
        '## Quellen und Wiederholung', '',
        '- [Google-Modellkarte](https://ai.google.dev/gemma/docs/embeddinggemma/model_card_2)',
        '- [LiteRT-Embedding-API und Präfixe](https://developers.google.com/edge/litert-lm/embedding_models)',
        '- Modellrevision, Bild-/Datensatzprüfsummen und vorab festgelegtes Verfahren: `protocol.json`.',
        '- Erzeugte Vektoren, alle Rankings, Metriken und Messzeiten: `*-vectors-768.npy`, `results.json`, `inference.json`.',
        '- Wiederholung: `../media-ai-search-benchmark-20261009/.venv/bin/python benchmark.py` (neue Inferenz).',
    ]
    (ROOT / 'REPORT.md').write_text('\n'.join(lines) + '\n')
    cats = ''.join(f'<option>{html.escape(c)}</option>' for c in dict.fromkeys(q['category'] for q in data['queries']))
    a,b = s['separate_rrf']['primary_content'], s['joint']['primary_content']
    page = f'''<!doctype html><html lang="de"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Ein oder zwei Vektoren</title>
    <style>body{{font:15px system-ui;background:#f5f7fa;color:#17283c;margin:0;padding:24px}}main{{max-width:1400px;margin:auto}}h1{{font-size:30px}}.summary{{display:flex;gap:20px;flex-wrap:wrap}}.stat{{background:white;border:1px solid #dbe2ea;padding:18px;border-radius:12px;flex:1}}.stat b{{font-size:30px;display:block}}table{{border-collapse:collapse;width:100%;margin-top:20px;background:white}}th,td{{padding:14px;border-bottom:1px solid #dde4ed;text-align:left;vertical-align:top}}th{{background:#e8eef5}}.cards{{display:flex;gap:8px;flex-wrap:wrap}}.card{{width:105px;border:2px solid transparent;border-radius:8px;padding:3px}}.correct{{border-color:#2b8f61;background:#edf8f1}}img{{width:100%;height:85px;object-fit:contain;background:#f5f7fa}}span{{font-size:11px;display:block}}select,input{{padding:10px;border:1px solid #bbc7d5;border-radius:6px;margin:12px 10px 0 0}}.note{{color:#526176;line-height:1.6}}small{{display:block;margin-top:6px}}</style>
    <main><h1>Ein gemeinsamer oder zwei getrennte Vektoren?</h1><p class="note">20 künstliche Bilder · echte gespeicherte Pipeline-Beschreibungen · EmbeddingGemma 2 · 10. Oktober 2026</p><div class="summary"><div class="stat">Zwei Vektoren · Rangfusion<b>{a['hit1_count']} / {a['n']}</b>richtige erste Treffer · MRR {a['mrr']:.3f}</div><div class="stat">Ein gemeinsamer Vektor<b>{b['hit1_count']} / {b['n']}</b>richtige erste Treffer · MRR {b['mrr']:.3f}</div></div><p class="note">Hauptwertung: Inhaltssuche ohne exakte OCR und Dateinamen. Grün markiert die vorab festgelegten Solltreffer. Ein gemeinsamer Vektor entsteht aus Bild und Text in einem Modellaufruf. Der kleine Bestand erlaubt eine erste Entscheidung, keinen allgemeinen Qualitätsnachweis. Vektorsuche ohne Schwelle liefert auch für nicht vorhandene Inhalte Treffer.</p><p class="note"><strong>Weitere Kontrolle:</strong> Zwei Vektoren mit gemittelter Ähnlichkeit erreichen ebenfalls 40/42. Damit zeigt der kleine Test keinen Qualitätsvorteil für zwei getrennte Vektoren. Ein gemeinsamer Vektor benötigt halb so viel Rohvektorspeicher. <a href="REPORT.md">Vollständiger Bericht mit allen Verfahren und Messzeiten</a></p><select id="category"><option>Alle Kategorien</option>{cats}</select><input id="search" placeholder="Suchanfrage filtern"><table><thead><tr><th>Suchanfrage</th><th>2 Vektoren: Top 5</th><th>1 gemeinsamer Vektor: Top 5</th></tr></thead><tbody>{''.join(rows)}</tbody></table></main><script>function filter(){{const c=document.querySelector('#category').value,s=document.querySelector('#search').value.toLowerCase();document.querySelectorAll('tbody tr').forEach(r=>r.hidden=(c!=='Alle Kategorien'&&r.dataset.category!==c)||!r.firstElementChild.textContent.toLowerCase().includes(s))}}document.querySelector('#category').onchange=filter;document.querySelector('#search').oninput=filter;</script></html>'''
    (ROOT / 'compare.html').write_text(page)


def vectors_loaded(label):
    return np.load(ROOT / (label + '-vectors-768.npy'))


if __name__ == '__main__':
    data, protocol = freeze()
    vectors, perf = inference(data)
    result = {'protocol': protocol, 'primary': evaluate(data, vectors),
              'model_card_query_prefix': evaluate(data, vectors, 'query_model_card'),
              'dimensions_256': evaluate(data, vectors, dim=256),
              'performance': {'inference': perf, 'scale': scale(vectors)}}
    (ROOT / 'results.json').write_text(json.dumps(result, ensure_ascii=False, indent=2) + '\n')
    report(data, result)
    for method in METHODS:
        print(method, result['primary']['summary'][method]['primary_content'], flush=True)
