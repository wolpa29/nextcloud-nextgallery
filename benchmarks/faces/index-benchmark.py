import argparse,time,resource,json,sys
from pathlib import Path
sys.path.insert(0,str(Path(__file__).resolve().parents[2]/'worker'))
import numpy as np
import media_ai_faces as f
p=argparse.ArgumentParser();p.add_argument('--n',type=int,required=True);a=p.parse_args()
rng=np.random.default_rng(20261020);vectors=rng.standard_normal((a.n,128),dtype=np.float32);vectors/=np.linalg.norm(vectors,axis=1,keepdims=True)
idx=f.FaceClusterIndex(.55);t=time.monotonic();idx.populate([(str(i),v.tolist()) for i,v in enumerate(vectors)]);build=time.monotonic()-t
correct=0;count=500;t=time.monotonic()
for i in rng.integers(0,a.n,size=count):
 noise=rng.standard_normal(128,dtype=np.float32);noise-=np.dot(noise,vectors[i])*vectors[i];noise/=np.linalg.norm(noise);query=.8*vectors[i]+.6*noise
 idx.checkpoint();group=idx.match_or_create(query.tolist());idx.rollback();correct+=group==str(i)
print(json.dumps({'n':a.n,'queries':count,'recall':correct/count,'build_seconds':round(build,3),'query_seconds':round(time.monotonic()-t,3),'peak_rss_mib':round(resource.getrusage(resource.RUSAGE_SELF).ru_maxrss/1024,1),'kind':'synthetic-neighbor-index; not face-accuracy'}))
