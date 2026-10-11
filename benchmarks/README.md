# Synthetic retrieval comparisons

Frozen comparisons from 9–10 October 2026, including saved vectors, labels, rankings, reports, original evaluation scripts and synthetic pictures. No private media, model weights, runtime environments or credentials are included.

- `media-ai-vector-comparison-20261010/REPORT.md`: joint image + description vs two independently ranked vectors.
- `media-ai-hybrid-comparison-20261010/REPORT.md`: image embeddings + lexical search vs joint embeddings + identical lexical search.
- `media-ai-hybrid-comparison-20261010/recall-report.md`: broad candidate lists, matching the preference for recall.
- `media-ai-hybrid-comparison-20261010/direct-versus-hybrid.md`: embedding-only vs hybrid.
- `media-ai-search-benchmark-20261009/five-way/REPORT.md`: prior frozen baselines, including real isolated MariaDB text-search results.

These historical experiments use a small synthetic corpus and the then-current MariaDB FULLTEXT baseline. They are not a production latency or Qwen benchmark, and the baseline is not identical to the current gallery OR/weighted-text search.

Recompute hybrid scores and independently verify the saved rankings without a model download:

```sh
python3 -m venv .venv
.venv/bin/pip install numpy
.venv/bin/python media-ai-hybrid-comparison-20261010/benchmark.py
.venv/bin/python media-ai-hybrid-comparison-20261010/verify.py
.venv/bin/python media-ai-hybrid-comparison-20261010/recall-evaluation.py
```

Run these from `benchmarks/`. The two metadata source snapshots preserve the historical baseline predicate check. Individual `compare.html` files can be opened next to their synthetic `thumbs` directories. Full inference scripts additionally require the pinned model file in `media-ai-search-benchmark-20261009/five-way/model/` and the runtime versions in the corresponding requirements lock file; model SHA256 and Hugging Face revision are recorded in `provenance.json`/`protocol.json`. Model weights are intentionally downloaded separately under their own license. Absolute workstation paths in historical provenance are descriptive evidence, not current runtime paths.
