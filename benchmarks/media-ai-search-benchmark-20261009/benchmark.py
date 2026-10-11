"""CPU retrieval benchmark. Dataset/qrels fixed before encoding; no tuning."""
import collections, hashlib, importlib.metadata, json, os, platform, resource
import sqlite3, subprocess, time, unicodedata
from pathlib import Path
import numpy as np
import regex
import litert_lm

ROOT=Path(__file__).resolve().parent
SOURCE=ROOT/'source-snapshot'
data=json.loads((ROOT/'dataset.json').read_text())
docs=data['documents'];queries=data['queries'];keys=[d['key'] for d in docs]
baseline=json.loads((ROOT/'baseline-mariadb.json').read_text())
dataset_hash=hashlib.sha256((ROOT/'dataset.json').read_bytes()).hexdigest()

def normalize(s):
    return ''.join(c for c in unicodedata.normalize('NFD',s) if not unicodedata.combining(c)).lower().replace('ß','ss')

def scene(d):
    return d['description']+'\nSuchbegriffe: '+', '.join(d['tags'])+'\nObjekte: '+', '.join(d['objects'])

def complete(d):
    return scene(d)+ ('\nErkannter Text:\n'+d['ocr'] if d['ocr'] else '')

methods={'nextcloud_fulltext': {q['id']:baseline['queries'][q['id']]['keys'] for q in queries}}
methods['lexical_or_proposal']={q['id']:baseline['queries'][q['id']]['relaxed_keys'] for q in queries}
methods['android_substring']={}
methods['worker_fts5']={}
sql=sqlite3.connect(':memory:')
sql.execute("CREATE VIRTUAL TABLE search USING fts5(id UNINDEXED,path,description,tags,ocr,tokenize='unicode61 remove_diacritics 2')")
for d in docs:
    sql.execute('INSERT INTO search VALUES(?,?,?,?,?)',(d['key'],d['path'],d['description'],' '.join(d['tags']+d['objects']),d['ocr']))
for q in queries:
    words=normalize(q['text']).split()[:16]
    methods['android_substring'][q['id']]=[d['key'] for d in docs if all(w in normalize(complete(d)+' '+d['path']+' '+d['kind']) for w in words)]
    terms=regex.findall(r'\w+',q['text'])[:32]
    expr=' AND '.join('"'+w+'"' for w in terms)
    methods['worker_fts5'][q['id']]=[r[0] for r in sql.execute('SELECT id FROM search WHERE search MATCH ? ORDER BY bm25(search) LIMIT 50',(expr,))] if expr else []

model_path=ROOT/'model/embeddinggemma-2-text-270m.litertlm'
cache=ROOT/'model/cache';cache.mkdir(exist_ok=True)
options=litert_lm.EmbeddingOptions(output_size=768,normalize=True)
timings=collections.defaultdict(list)
embedding_details={}
start=time.perf_counter()
with litert_lm.EmbeddingEngine(str(model_path),backend=litert_lm.Backend.CPU(thread_count=4),
        min_input_length=128,max_input_length=512,cache_dir=str(cache)) as model:
    load_ms=(time.perf_counter()-start)*1000
    def encode(text, label):
        start=time.perf_counter()
        v=np.asarray(model.compute_embedding(text,options=options).embedding,dtype=np.float32)
        timings[label].append((time.perf_counter()-start)*1000)
        assert v.shape==(768,) and np.isfinite(v).all() and abs(np.linalg.norm(v)-1)<1e-4
        return v

    # Warm-up is separate from query timings.
    encode('task: search result | query: Test','warmup')
    qvec=np.stack([encode('task: search result | query: '+q['text'],'query_official') for q in queries])
    dvec=np.stack([encode('title: none | text: '+complete(d),'document_complete') for d in docs])
    descvec=np.stack([encode('title: none | text: '+d['description'],'document_description') for d in docs])
    scenevec=np.stack([encode('title: none | text: '+scene(d),'document_scene') for d in docs])
    ocrvec={d['key']:encode('title: none | text: '+d['ocr'],'document_ocr') for d in docs if d['ocr']}
    # The current Google LiteRT guide uses different prefixes than the base model card.
    # Report this predeclared robustness variant, rather than silently choosing a winner.
    lq=np.stack([encode('task: search query | text: '+q['text'],'query_litert') for q in queries])
    ld=np.stack([encode('task: search result | text: '+complete(d),'document_litert') for d in docs])

    all_scores={
        'embedding_768':qvec@dvec.T,
        'embedding_description_only':qvec@descvec.T,
        'embedding_split_ocr':qvec@scenevec.T,
        'embedding_litert_prefix':lq@ld.T,
    }
    for j,d in enumerate(docs):
        if d['key'] in ocrvec:
            all_scores['embedding_split_ocr'][:,j]=np.maximum(all_scores['embedding_split_ocr'][:,j],qvec@ocrvec[d['key']])
    for name,scores in all_scores.items():
        methods[name]={}
        embedding_details[name]={}
        for i,q in enumerate(queries):
            order=np.argsort(-scores[i],kind='stable')
            methods[name][q['id']]=[keys[j] for j in order]
            embedding_details[name][q['id']]=[dict(key=keys[j],score=float(scores[i,j])) for j in order]

    # Fixed RRF, k=60, no result/threshold tuning against the relevance labels.
    methods['hybrid_rrf']={}
    for q in queries:
        scores=collections.defaultdict(float)
        for name in ['nextcloud_fulltext','embedding_768']:
            for rank,key in enumerate(methods[name][q['id']][:20],1):scores[key]+=1/(60+rank)
        methods['hybrid_rrf'][q['id']]=sorted(scores,key=lambda k:(-scores[k],keys.index(k)))

    # Matrix scan only: expand existing vectors to 30,000; not a 30k quality dataset.
    large=np.tile(dvec,(int(np.ceil(30000/len(docs))),1))[:30000].copy()
    scan=[]
    for repeat in range(23):
        v=qvec[repeat%len(qvec)]
        begin=time.perf_counter();s=large@v;best=np.argpartition(-s,5)[:5];best=best[np.argsort(-s[best])]
        elapsed=(time.perf_counter()-begin)*1000
        if repeat>=3:scan.append(elapsed)
    large_bytes=large.nbytes
    np.save(ROOT/'document-vectors-768.npy',dvec)
    np.save(ROOT/'query-vectors-768.npy',qvec)
    np.save(ROOT/'document-description-vectors-768.npy',descvec)
    np.save(ROOT/'document-scene-vectors-768.npy',scenevec)
    np.save(ROOT/'document-litert-vectors-768.npy',ld)
    np.save(ROOT/'query-litert-vectors-768.npy',lq)
    np.savez(ROOT/'document-ocr-vectors-768.npz',**ocrvec)

def metrics(qs,name):
    answered=[q for q in qs if q['relevant']]
    ranks=[];recalls=[];ndcg=[]
    for q in answered:
        result=methods[name][q['id']];rel=set(q['relevant'])
        rank=next((i for i,k in enumerate(result,1) if k in rel),None);ranks.append(rank)
        recalls.append(len(set(result[:5])&rel)/len(rel))
        dcg=sum(1/np.log2(i+2) for i,k in enumerate(result[:5]) if k in rel)
        ideal=sum(1/np.log2(i+2) for i in range(min(5,len(rel))))
        ndcg.append(dcg/ideal)
    n=len(answered)
    return dict(n=n,hit1=sum(r==1 for r in ranks),hit5=sum(r is not None and r<=5 for r in ranks),
                mrr10=float(sum(1/r for r in ranks if r is not None and r<=10)/n) if n else None,
                recall5=float(np.mean(recalls)) if n else None,ndcg5=float(np.mean(ndcg)) if n else None)

groups=sorted({q['group'] for q in queries if q['relevant']})
summary={name:{'all':metrics(queries,name),**{g:metrics([q for q in queries if q['group']==g],name) for g in groups}} for name in methods}
query_results=[]
for q in queries:
    row={**q,'results':{name:methods[name][q['id']][:10] for name in methods},
         'embedding_scores':{name:embedding_details[name][q['id']][:10] for name in embedding_details}}
    query_results.append(row)

def stats(x):return dict(n=len(x),median_ms=float(np.median(x)),p95_ms=float(np.percentile(x,95)),min_ms=float(min(x)),max_ms=float(max(x)))
sources=['lib/Service/Metadata.php','lib/Migration/Version000100Date20261002140000.php']
source_hashes={p:hashlib.sha256((SOURCE/'nextcloud-app/media_ai'/p).read_bytes()).hexdigest() for p in sources}
report=dict(
    schema=1,dataset_sha256=dataset_hash,documents=len(docs),queries=len(queries),source_hashes=source_hashes,
    model=dict(repo='litert-community/embeddinggemma-2-text-270m-litert-lm',revision=json.loads((ROOT/'model-api.json').read_text())['sha'],
               sha256=hashlib.sha256(model_path.read_bytes()).hexdigest(),bytes=model_path.stat().st_size,
               dimension=768,quantization='official LiteRT export: int4 QAT text model',backend='CPU',threads=4,
               query_prefix='task: search result | query: ',document_prefix='title: none | text: '),
    system=dict(platform=platform.platform(),cpu=subprocess.check_output(['bash','-c',"lscpu | sed -n 's/^Model name:[[:space:]]*//p'"],text=True).strip(),
                logical_cpus=os.cpu_count(),python=platform.python_version(),peak_process_rss_mib=resource.getrusage(resource.RUSAGE_SELF).ru_maxrss/1024,
                libraries={p:importlib.metadata.version(p) for p in ['litert-lm','litert-lm-api','numpy','pymysql','regex']}),
    database=dict(version=baseline['version'],variables=baseline['variables'],collation='utf8mb4_bin',isolated=True),
    timing=dict(model_load_ms=load_ms,**{label:stats(values) for label,values in timings.items()},
                vector_scan_30000=stats(scan),vector_scan_bytes=large_bytes,
                nextcloud_sql=stats([baseline['queries'][q['id']]['ms'] for q in queries])),
    metrics=summary,results=query_results,
    limitations=[
        'Small handcrafted synthetic corpus, not representative of a production gallery.',
        'Text embeddings of stored descriptions/tags/OCR only; no direct image embeddings or image recognition tested.',
        'Exact Nextcloud Metadata class against a real disposable MariaDB; access/ETag/UI/provider pagination not benchmarked.',
        'Android substring and worker FTS5 use reconstructed query behavior; Android file/date sort order is not reproduced.',
        'Google base-model and LiteRT documentation specify different prefixes; both variants are reported.',
        'Embedding and hybrid return ranked candidates without a calibrated rejection threshold; all absent queries get false candidates.',
        '30k vectors are repetitions for measuring matrix-scan cost only; quality is evaluated on 56 independent documents.',
        'Local Linux VM CPU measurements; not Nextcloud-host, Android, or physical-phone measurements.',
        'Dataset/qrels written and frozen before embeddings; no thresholds or fusion parameters fitted to labels.'
    ])
assert dataset_hash==hashlib.sha256((ROOT/'dataset.json').read_bytes()).hexdigest()
(ROOT/'results.json').write_text(json.dumps(report,ensure_ascii=False,indent=2)+'\n')
print(json.dumps({'metrics':{n:s['all'] for n,s in summary.items()},'timing':report['timing']},ensure_ascii=False,indent=2))
