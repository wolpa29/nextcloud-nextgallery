"""Five preregistered methods; exact normalized 768d scan, no answer-based tuning."""
import collections,hashlib,json,resource,time
from pathlib import Path
import numpy as np
import litert_lm as lm
ROOT=Path(__file__).resolve().parent
data=json.loads((ROOT/'dataset.json').read_text());docs=data['documents'];queries=data['queries'];keys=[d['key'] for d in docs]
normal=json.loads((ROOT/'normal-nextcloud.json').read_text());lexical=json.loads((ROOT/'baseline-mariadb.json').read_text())
def metadata(d):return d['description']+'\nSuchbegriffe: '+', '.join(d['tags'])+'\nObjekte: '+', '.join(d['objects'])+ ('\nErkannter Text:\n'+d['ocr'] if d['ocr'] else '')
cache=ROOT/'text-cache';cache.mkdir(exist_ok=True);timings=collections.defaultdict(list)
options=lm.EmbeddingOptions(normalize=True,output_size=768)
start=time.perf_counter()
with lm.EmbeddingEngine(str(ROOT.parent/'model/embeddinggemma-2-text-270m.litertlm'),backend=lm.Backend.CPU(thread_count=4),cache_dir=str(cache),min_input_length=128,max_input_length=512) as engine:
    load_ms=(time.perf_counter()-start)*1000
    def enc(s,label):
        st=time.perf_counter();v=np.asarray(engine.compute_embedding(s,options=options).embedding,dtype=np.float32);timings[label].append((time.perf_counter()-st)*1000)
        assert v.shape==(768,) and np.isfinite(v).all() and abs(np.linalg.norm(v)-1)<1e-4
        return v
    enc('task: search result | query: Test','warmup')
    qv=np.stack([enc('task: search result | query: '+q['text'],'query') for q in queries])
    dv=np.stack([enc('title: none | text: '+metadata(d),'document') for d in docs])
    qr=np.stack([enc('task: search query | text: '+q['text'],'query_runtime') for q in queries])
    dr=np.stack([enc('task: search result | text: '+metadata(d),'document_runtime') for d in docs])
np.save(ROOT/'metadata-vectors-768.npy',dv);np.save(ROOT/'metadata-query-vectors-768.npy',qv)
iv=np.load(ROOT/'image-vectors-768.npy');iq=np.load(ROOT/'image-query-vectors-768.npy');iqr=np.load(ROOT/'image-query-runtime-vectors-768.npy')
assert iv.shape==dv.shape==(20,768)
text_scores=qv@dv.T;image_scores=iq@iv.T
def ordered(scores):return [[keys[i] for i in np.argsort(-row,kind='stable')] for row in scores]
text_ranks=ordered(text_scores);image_ranks=ordered(image_scores)
fusion=np.empty(text_scores.shape,dtype=np.float32)
for i in range(len(queries)):
    tr={k:j+1 for j,k in enumerate(text_ranks[i])};ir={k:j+1 for j,k in enumerate(image_ranks[i])}
    fusion[i]=[1/(60+tr[k])+1/(60+ir[k]) for k in keys]
mean_scores=(text_scores+image_scores)*.5
methods={
 'normal_nextcloud':{q['id']:normal['queries'][q['id']]['keys'] for q in queries},
 'current_media_ai':{q['id']:lexical['queries'][q['id']]['keys'] for q in queries},
 'metadata_embedding':dict(zip([q['id'] for q in queries],text_ranks)),
 'image_embedding':dict(zip([q['id'] for q in queries],image_ranks)),
 'combined_embedding':dict(zip([q['id'] for q in queries],ordered(fusion))),
}
diagnostics={
 'combined_mean_cosine':dict(zip([q['id'] for q in queries],ordered(mean_scores))),
 'metadata_runtime_prefix':dict(zip([q['id'] for q in queries],ordered(qr@dr.T))),
 'image_runtime_prefix':dict(zip([q['id'] for q in queries],ordered(iqr@iv.T))),
 'lexical_or_proposal':{q['id']:lexical['queries'][q['id']]['relaxed_keys'] for q in queries},
}
def metrics(ranks,qs):
    qs=[q for q in qs if q['relevant']];n=len(qs);h1=h5=0;rr=0.;rec=0.
    for q in qs:
        r=ranks[q['id']];wanted=set(q['relevant']);hits=[i+1 for i,k in enumerate(r) if k in wanted]
        h1+=bool(hits and hits[0]==1);h5+=bool(hits and hits[0]<=5);rr+=1/hits[0] if hits else 0;rec+=len(set(r[:5])&wanted)/len(wanted)
    return {'n':n,'hit1':h1,'hit5':h5,'hit1_pct':100*h1/n if n else 0,'hit5_pct':100*h5/n if n else 0,'mrr':rr/n if n else 0,'recall5':rec/n if n else 0}
groups={'all_answerable':[q for q in queries if q['relevant']],'content':[q for q in queries if q['relevant'] and q['category']!='Dateiname']}
groups.update({c:[q for q in queries if q['category']==c] for c in dict.fromkeys(q['category'] for q in queries) if c!='Nicht vorhanden'})
summary={name:{g:metrics(ranks,qs) for g,qs in groups.items()} for name,ranks in {**methods,**diagnostics}.items()}
absent=[q for q in queries if not q['relevant']]
absent_results={name:{'n':len(absent),'false_nonempty':sum(bool(r[q['id']]) for q in absent)} for name,r in methods.items()}
scores={'metadata_embedding':text_scores.tolist(),'image_embedding':image_scores.tolist(),'combined_embedding':fusion.tolist(),'combined_mean_cosine':mean_scores.tolist()}
# Scan timing ONLY: repeated vectors to 30k, not 30k distinct-image retrieval quality.
scale=collections.defaultdict(list)
for name,matrix in [('one_index',dv),('two_indexes',np.concatenate([dv,iv],axis=1))]:
    big=np.tile(matrix,(1500,1));v=qv[0] if name=='one_index' else np.concatenate([qv[0],iq[0]])
    for j in range(33):
        st=time.perf_counter();s=big@v;np.argpartition(s,-5)[-5:];elapsed=(time.perf_counter()-st)*1000
        if j>=3:scale[name].append(elapsed)
    scale[name+'_bytes']=big.nbytes
bigtext=np.tile(dv,(1500,1));bigimage=np.tile(iv,(1500,1))
for j in range(33):
    st=time.perf_counter();ts=bigtext@qv[0];ims=bigimage@iq[0]
    tr=np.empty(30000,np.int32);ir=np.empty(30000,np.int32)
    tr[np.argsort(-ts,kind='stable')]=np.arange(1,30001);ir[np.argsort(-ims,kind='stable')]=np.arange(1,30001)
    fused=1/(60+tr)+1/(60+ir);np.argpartition(fused,-5)[-5:];elapsed=(time.perf_counter()-st)*1000
    if j>=3:scale['two_indexes_rrf'].append(elapsed)
float16_ranks=ordered(qv@dv.astype(np.float16).astype(np.float32).T)
float16_changed=sum(a[:5]!=b[:5] for a,b in zip(text_ranks,float16_ranks))
result={'dataset_sha256':hashlib.sha256((ROOT/'dataset.json').read_bytes()).hexdigest(),'protocol':json.loads((ROOT/'protocol.json').read_text()),'documents':docs,'queries':queries,'methods':methods,'diagnostics':diagnostics,'scores':scores,'summary':summary,'absent':absent_results,'performance':{'metadata_model_load_ms':load_ms,'metadata_peak_rss_mib':resource.getrusage(resource.RUSAGE_SELF).ru_maxrss/1024,'metadata_timings_ms':dict(timings),'image':json.loads((ROOT/'image-timings.json').read_text()),'normal_nextcloud_http_ms':[v['ms'] for v in normal['queries'].values()],'current_media_ai_db_ms':[v['ms'] for v in lexical['queries'].values()],'30k_repeated_vectors_scan':dict(scale),'text_only_vs_multimodal_query_min_cosine':float(np.min(np.sum(qv*iq,axis=1))),'float16_metadata_top5_changed_queries':float16_changed,'float16_metadata_top5_unchanged':float16_changed==0}}
(ROOT/'results.json').write_text(json.dumps(result,ensure_ascii=False,indent=2)+'\n')
(ROOT/'summary.json').write_text(json.dumps({'summary':summary,'absent':absent_results,'performance':result['performance']},ensure_ascii=False,indent=2)+'\n')
for name in methods:print(name,summary[name]['content'],flush=True)
