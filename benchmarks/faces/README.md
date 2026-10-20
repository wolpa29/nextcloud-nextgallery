# People smoke fixtures

`public-sources.json` records the original image links, published licenses, attribution and SHA-256 digests used for the small People evaluation. One portrait is published under CC BY 3.0; retain its listed attribution when sharing it. The remaining source pages identify their official US government portraits as public domain. These are source-page license statements, not a general legal assurance.

Download and verify the exact files:

```sh
python3 scripts/fetch-face-public-fixtures.py
```

Run with the explicitly installed face runtime and model directory:

```sh
NEXTGALLERY_FACES_MODELS=/path/to/faces-local/models \
PYTHONPATH=worker:pipeline/tests \
/path/to/faces-local/runtime/bin/python -m unittest test_faces_smoke -v
```

Four formal portraits, two images per person, are sufficient for a pipeline smoke check. They do not measure family-library accuracy, children growing up, occlusion or individual pet recognition. No personal library is used.

The neighbor-search mechanism can be checked separately with synthetic vectors:

```sh
OPENBLAS_NUM_THREADS=1 OMP_NUM_THREADS=1 \
/path/to/faces-local/runtime/bin/python benchmarks/faces/index-benchmark.py --n 100000
```

This measures known-neighbor recall, runtime and peak process memory, without photos. It is not a recognition-quality score.
