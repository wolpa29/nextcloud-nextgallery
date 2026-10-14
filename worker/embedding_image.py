"""Isolated, memory-safe image preparation for embedding vectors.

Normal images strictly use media_pipeline.prepare_image('embedding') to guarantee
byte-identical outputs and stable vector spaces. Large images (e.g. extreme aspect
ratio or gigapixel panoramas exceeding max_pixels) are safely decoded via libjpeg
draft scaling inside an isolated subprocess with OS memory limits, preserving full
frame area and EXIF orientation without global guard relaxation.
"""
from __future__ import annotations

import argparse
import io
import json
from pathlib import Path
import re
import subprocess
import sys
import tempfile
from PIL import Image

import media_pipeline
from media_ai_budget import check_budget, network_timeout, ImageTimeout
from media_ai_errors import PipelineError
from inference_gate import InferenceCancelled

MAX_OUTPUT_BYTES = 24 * 1024 * 1024


def _sanitize_error(message: str) -> str:
    """Strip raw BytesIO pointer addresses and format honest, understandable errors."""
    cleaned = re.sub(r"<(_io\.)?BytesIO object at 0x[0-9a-fA-F]+>", "image data", str(message)[:2048])
    cleaned = re.sub(r"at 0x[0-9a-fA-F]+", "", cleaned)
    if "cannot identify image file" in cleaned.lower():
        return "Cannot identify image file: unsupported, empty or corrupted format"
    lines = [line.strip() for line in cleaned.strip().splitlines() if line.strip()]
    if lines:
        last = lines[-1]
        for prefix in ("PipelineError: ", "ValueError: ", "OSError: ", "RuntimeError: "):
            if last.startswith(prefix):
                last = last[len(prefix):]
        return last
    return "Image decoding failed"


def _extract_exif(original) -> dict[str, str]:
    """Isolate metadata extraction from pixel decode; metadata errors never fail the image."""
    exif = {}
    try:
        raw_exif = original.getexif()
        if raw_exif:
            for tag, name in [(271, "camera_make"), (272, "camera_model"), (306, "file_datetime")]:
                if tag in raw_exif:
                    exif[name] = str(raw_exif[tag])
            if 34665 in raw_exif:
                try:
                    details = raw_exif.get_ifd(34665)
                    for tag, name in [(36867, "datetime_original"), (36868, "datetime_digitized")]:
                        if tag in details:
                            exif[name] = str(details[tag])
                except Exception:
                    pass
    except Exception:
        pass
    return exif


def _decode_in_subprocess(data: bytes, max_edge: int, max_pixels: int, timeout: float = 120.0, mem_limit: int | None = None) -> tuple[bytes, str, dict]:
    """Execute memory-bounded draft decoder in an isolated child process."""
    check_budget()
    effective_timeout = network_timeout(timeout)

    with tempfile.TemporaryDirectory(prefix="nextgallery-embed-img-") as temp_dir:
        input_path = Path(temp_dir) / "input.bin"
        output_path = Path(temp_dir) / "output.png"
        meta_path = Path(temp_dir) / "meta.json"
        error_path = Path(temp_dir) / "decoder-error.txt"

        input_path.write_bytes(data)

        cmd = [
            sys.executable,
            str(Path(__file__).resolve()),
            "--input", str(input_path),
            "--output", str(output_path),
            "--meta", str(meta_path),
            "--max-edge", str(max_edge),
            "--max-pixels", str(max_pixels),
        ]
        if mem_limit is not None:
            cmd.extend(["--mem-limit", str(mem_limit)])

        try:
            with error_path.open('wb') as errors:
                proc = subprocess.run(
                    cmd,
                    stdin=subprocess.DEVNULL,
                    stdout=subprocess.DEVNULL,
                    stderr=errors,
                    timeout=effective_timeout,
                    check=False,
                )
        except subprocess.TimeoutExpired:
            check_budget()
            raise PipelineError(f"Image decoder timed out ({effective_timeout:g} s)") from None

        check_budget()

        if proc.returncode != 0 or not output_path.exists():
            error_msg = "Image decoding failed"
            if meta_path.exists():
                try:
                    payload = json.loads(meta_path.read_text(encoding="utf-8"))
                    error_msg = payload.get("error", error_msg)
                except Exception:
                    pass
            elif error_path.stat().st_size:
                with error_path.open('rb') as errors:
                    error_msg = _sanitize_error(errors.read(4096).decode("utf-8", errors="replace"))
            raise PipelineError(error_msg)

        with output_path.open('rb') as output:
            wire = media_pipeline.bounded_read(output,MAX_OUTPUT_BYTES)
        if not wire:
            raise PipelineError("Decoded image output is empty")

        exif = {}
        if meta_path.exists():
            try:
                exif = json.loads(meta_path.read_text(encoding="utf-8"))
            except Exception:
                exif = {}

        return wire, "image/png", exif


def prepare_image(data: bytes, max_edge: int, max_pixels: int) -> tuple[bytes, str, dict]:
    """Prepare image for vector embedding: normal images use media_pipeline; large JPEGs fallback safely."""
    if type(max_edge) is not int or max_edge < 1:
        raise PipelineError("Invalid model image edge limit")
    if type(max_pixels) is not int or max_pixels < 1:
        raise PipelineError("Invalid decode pixel guard limit")

    if not data:
        raise PipelineError("Image data is empty (0 bytes)")

    stripped = data[:2048].lstrip()
    if stripped.lower().startswith((b"<!doctype html", b"<!doctype", b"<html", b"<?xml", b"<head", b"<body")):
        raise PipelineError("Unexpected HTML/XML response instead of image data")

    try:
        return media_pipeline.prepare_image(data, max_edge, max_pixels, "embedding")
    except PipelineError as exc:
        msg = str(exc)
        if "Source image exceeds decode memory guard" in msg:
            return _decode_in_subprocess(data, max_edge, max_pixels)
        raise
    except (ImageTimeout,InferenceCancelled):
        raise
    except Image.DecompressionBombError:
        return _decode_in_subprocess(data,max_edge,max_pixels)
    except (OSError,ValueError,SyntaxError) as exc:
        msg = str(exc)
        # Pillow DecompressionBombError (can be raised during Image.open)
        if "decompression bomb" in msg.lower():
            return _decode_in_subprocess(data, max_edge, max_pixels)

        # Check if file is truncated
        if "truncated" in msg.lower():
            raise PipelineError("Image file is truncated or incomplete; refusing to mark corrupt file done") from None

        # Check if JPEG header exists but metadata/decode had an isolated issue
        if data.startswith(b"\xff\xd8"):
            try:
                return _decode_in_subprocess(data, max_edge, max_pixels)
            except (ImageTimeout,InferenceCancelled):
                raise
            except (PipelineError,OSError,ValueError):
                pass

        raise PipelineError(_sanitize_error(msg)) from None


def _subprocess_entry(args: list[str]) -> int:
    """Child process entrypoint: sets local memory limit and executes draft decode."""
    parser = argparse.ArgumentParser(description="Isolated image decoder subprocess")
    parser.add_argument("--input", required=True)
    parser.add_argument("--output", required=True)
    parser.add_argument("--meta", required=True)
    parser.add_argument("--max-edge", type=int, required=True)
    parser.add_argument("--max-pixels", type=int, required=True)
    parser.add_argument("--mem-limit", type=int, default=768 * 1024 * 1024)
    opts = parser.parse_args(args)

    # Set virtual memory limit in POSIX systems (no preexec_fn needed)
    input_path = Path(opts.input)
    output_path = Path(opts.output)
    meta_path = Path(opts.meta)

    try:
        import resource
        if opts.mem_limit <= 0:
            raise PipelineError('Invalid isolated image decoder memory limit')
        resource.setrlimit(resource.RLIMIT_AS,(opts.mem_limit,opts.mem_limit))
        resource.setrlimit(resource.RLIMIT_FSIZE,(MAX_OUTPUT_BYTES,MAX_OUTPUT_BYTES))
        with input_path.open('rb') as source:
            data = source.read(2048)
        if not data:
            meta_path.write_text(json.dumps({"error": "Image data is empty (0 bytes)"}))
            return 1

        stripped = data[:2048].lstrip()
        if stripped.lower().startswith((b"<!doctype html", b"<!doctype", b"<html", b"<?xml", b"<head", b"<body")):
            meta_path.write_text(json.dumps({"error": "Unexpected HTML/XML response instead of image data"}))
            return 1

        from PIL import Image, ImageOps
        try:
            import pillow_heif
            pillow_heif.register_heif_opener()
        except ImportError:
            pass

        # Relax guard exclusively inside this isolated, memory-capped subprocess
        Image.MAX_IMAGE_PIXELS = None

        with Image.open(input_path) as original:
            exif = _extract_exif(original)

            w, h = original.size
            if w < 1 or h < 1:
                meta_path.write_text(json.dumps({"error": "Invalid image dimensions"}))
                return 1

            # JPEG draft decoder: decompress directly into downscaled DCT blocks
            is_jpeg = getattr(original, "format", None) == "JPEG" or data.startswith(b"\xff\xd8")
            if is_jpeg:
                scale = min(opts.max_edge / w, opts.max_edge / h)
                target = (max(1, int(w * scale)), max(1, int(h * scale)))
                original.draft("RGB", target)

            image = ImageOps.exif_transpose(original).convert("RGB")

            image.thumbnail((opts.max_edge, opts.max_edge), Image.Resampling.LANCZOS)
            image.save(output_path, format="PNG")

            meta_path.write_text(json.dumps(exif, ensure_ascii=False))
            return 0
    except Exception as exc:
        clean = _sanitize_error(str(exc))
        meta_path.write_text(json.dumps({"error": clean}, ensure_ascii=False))
        return 1


if __name__ == "__main__":
    raise SystemExit(_subprocess_entry(sys.argv[1:]))
