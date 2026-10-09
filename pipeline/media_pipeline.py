"""Portable entry point for the controller bundled with the Nextcloud app."""
import importlib.util
from pathlib import Path
import sys

source = Path(__file__).resolve().parents[1] / 'worker' / 'media_pipeline.py'
sys.path.insert(0, str(source.parent))
spec = importlib.util.spec_from_file_location('_media_ai_pipeline', source)
module = importlib.util.module_from_spec(spec)
sys.modules[spec.name] = module
spec.loader.exec_module(module)
if __name__ == '__main__':
    raise SystemExit(module.main())
sys.modules[__name__] = module
