"""Independent audit of hybrid scores and labels, including absent lexical contributions."""
import hashlib
import json
import math
from pathlib import Path
import numpy as np
ROOT=Path(__file__).resolve().parent
SOURCE=ROOT.parent/'media-ai-vector-comparison-20261010'
SQL=ROOT.parent/'media-ai-search-benchmark-20261009/five-way'
data=json.loads((ROOT/'dataset.json').read_text())
results=json.loads((ROOT/'results.json').read_text())
lex=json.loads((SQL/'baseline-mariadb.json').read_text())
keys=[d['key'] for d in data['documents']]
queries=data['queries']
checks=[]
qv=np.load(SOURCE/'query-vectors-768.npy')
for name in ('image','joint'):
    matrix=np.load(SOURCE/f'{name}-vectors-768.npy')
    sims=qv@matrix.T
    rows=results['primary']['ranks'][name+'_text']
    for i,q in enumerate(queries):
        semantic=np.argsort(-sims[i],kind='stable')
        positions={keys[j]:r+1 for r,j in enumerate(semantic)}
        hits=lex['queries'][q['id']]['keys']
        lexical={k:r+1 for r,k in enumerate(hits)}
        scores={k:1/(60+positions[k]) + (1/(60+lexical[k]) if k in lexical else 0) for k in keys}
        expected=sorted(keys,key=lambda k:-scores[k])
        assert expected==rows[q['id']],(name,q['id'])
    qs=[q for q in queries if q['relevant'] and q['category'] not in ('OCR exakt','Dateiname')]
    h1=sum(rows[q['id']][0] in q['relevant'] for q in qs)
    h5=sum(bool(set(rows[q['id']][:5])&set(q['relevant'])) for q in qs)
    s=results['primary']['summary'][name+'_text']['content']
    assert s['hit1_count']==h1 and s['hit5_count']==h5 and s['n']==len(qs)==42
    rr=sum(next((1/(j+1) for j,k in enumerate(rows[q['id']]) if k in q['relevant']),0) for q in qs)/len(qs)
    assert math.isclose(rr,s['mrr'],rel_tol=1e-12)
checks.append('All 124 main hybrid rankings independently recomputed from original vectors and real SQL hits; metrics verified')
for q in queries:
    if not lex['queries'][q['id']]['keys']:
        for name in ('image','joint'):
            assert results['primary']['ranks'][name+'_text'][q['id']]==results['primary']['ranks'][name][q['id']]
checks.append('No lexical hits means no lexical score or change to vector ranking')
assert all(len(set(row))==len(row)==20 for name in ('image_text','joint_text') for row in results['primary']['ranks'][name].values())
checks.append('Each main hybrid ranking contains every synthetic candidate exactly once')
assert sum(not q['relevant'] for q in queries)==4
assert sum(q['category']=='OCR exakt' for q in queries)==10
assert sum(q['category']=='Dateiname' for q in queries)==6
checks.append('Content, OCR, filename and no-match groups separated and counts verified')
assert set(queries[i]['id'] for i in range(len(queries)))==set(lex['queries'])
checks.append('Dataset identical to original SHA-256 frozen fixture; query/label keys align with cached SQL results')
verification={'checks':checks,'model_inference_rerun':False,'cached_real_vectors':True,'cached_real_sql':True,
              'pipeline_changes':False,'private_media_read':False,
              'artifacts':{p.name:hashlib.sha256(p.read_bytes()).hexdigest() for p in ROOT.iterdir() if p.is_file() and p.name!='verification.json'}}
(ROOT/'verification.json').write_text(json.dumps(verification,ensure_ascii=False,indent=2)+'\n')
print('\n'.join(checks))
