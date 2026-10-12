"""Custom model adapter template / Vorlage fuer ein eigenes Embedding-Modell.

Implement load_backend() using your model's documented runtime. The backend must
provide dimensions (128..4096), identity (weights + runtime + preprocessing +
precision + task prompts), embed_text(text), and embed_image(image_bytes).
Image and text outputs must inhabit the same vector space. This template refuses
to start until a real backend is implemented; it never generates fake vectors.
"""
import hashlib
from pathlib import Path


def load_backend(args):
    # Load trusted, pinned model weights from args.model_path on args.device.
    # Install the model's dependencies in the dedicated runtime beforehand.
    raise NotImplementedError('Implement load_backend() for your chosen image/text embedding model first.')


def create_engine(args):
    backend=load_backend(args)
    identity=backend.identity
    if not isinstance(identity,str) or not identity.strip():
        raise ValueError('Declare a reproducible backend identity before serving vectors')
    adapter=hashlib.sha256(Path(__file__).read_bytes()).hexdigest()
    space=hashlib.sha256((args.model+'|'+identity+'|'+adapter).encode()).hexdigest()

    class Engine:
        info={'model':args.model,'family':'custom','dimensions':backend.dimensions,
              'space':space,'modalities':['text','image'],'video':'sampled_frames'}
        def embed(self,text=None,image=None):
            values=backend.embed_image(image) if image is not None else backend.embed_text(text)
            return [float(value) for value in values]
    return Engine()
