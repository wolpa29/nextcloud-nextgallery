"""Encode actual image pixels and text queries with the pinned multimodal model."""
import json,resource,time
from pathlib import Path
import litert_lm as lm
import numpy as np
ROOT=Path(__file__).resolve().parent;data=json.loads((ROOT/'fixtures.json').read_text())
cache=ROOT/'model/cache';cache.mkdir(exist_ok=True)
times={'image':[],'query':[],'query_runtime_prefix':[]};options=lm.EmbeddingOptions(normalize=True,output_size=768,vision_tokens_per_image=140)
start=time.perf_counter()
with lm.EmbeddingEngine(str(ROOT/'model/embeddinggemma-2-text-vision-440m.litertlm'),backend=lm.Backend.CPU(thread_count=4),vision_backend=lm.Backend.CPU(thread_count=4),cache_dir=str(cache),min_input_length=128,max_input_length=512,vision_tokens_per_image=140) as engine:
    load_ms=(time.perf_counter()-start)*1000
    def enc(content,label):
        start=time.perf_counter();r=engine.compute_embedding(content,options=options)
        v=np.asarray(r.embedding,dtype=np.float32);times[label].append((time.perf_counter()-start)*1000)
        assert v.shape==(768,) and np.isfinite(v).all() and abs(np.linalg.norm(v)-1)<1e-4
        return v
    engine.compute_embedding('task: search result | query: Test',options=options)
    images=[]
    for d in data['documents']:
        images.append(enc(lm.Content.ImageFile(str(ROOT/'images'/d['path'])),'image'))
        print(d['key'],times['image'][-1],flush=True)
    q=np.stack([enc('task: search result | query: '+q['text'],'query') for q in data['queries']])
    qr=np.stack([enc('task: search query | text: '+q['text'],'query_runtime_prefix') for q in data['queries']])
    np.save(ROOT/'image-vectors-768.npy',np.stack(images));np.save(ROOT/'image-query-vectors-768.npy',q);np.save(ROOT/'image-query-runtime-vectors-768.npy',qr)
summary={'load_ms':load_ms,'peak_rss_mib':resource.getrusage(resource.RUSAGE_SELF).ru_maxrss/1024,'timings_ms':times,'image_soft_tokens':140,'dimensions':768,'backend':'CPU, 4 threads each','model_revision':json.loads((ROOT/'model-api.json').read_text())['sha']}
(ROOT/'image-timings.json').write_text(json.dumps(summary,indent=2)+'\n')
print(json.dumps({k:v for k,v in summary.items() if k!='timings_ms'}),flush=True)
