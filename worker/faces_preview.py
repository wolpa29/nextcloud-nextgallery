"""Face preview crop utility for NextGallery.

Produces normalized, oriented face crops safely from JPEG, PNG, WebP, and HEIC.
Can be invoked as CLI or imported as a module.
"""
from __future__ import annotations

import argparse
import io
import math
import os
from pathlib import Path
import sys

from PIL import Image, ImageOps

try:
    import pillow_heif
    pillow_heif.register_heif_opener()
except ImportError:
    pass

MAX_IMAGE_PIXELS = 80_000_000
MAX_OUTPUT_SIZE = 1024


def crop_face(
    image_source: bytes | str | Path,
    bbox: list[float] | tuple[float, ...],
    size: int = 256,
    padding: float = 0.15,
) -> bytes:
    """Crop face using normalized oriented bbox [x, y, w, h] (0..1 floats).

    Returns JPEG bytes of the cropped, resized face thumbnail.
    """
    if len(bbox) != 4:
        raise ValueError('Invalid bounding box format, expected [x, y, w, h]')
    try:
        x, y, w, h = [float(v) for v in bbox]
    except (TypeError, ValueError) as err:
        raise ValueError('Bounding box coordinates must be numeric') from err

    if not all(math.isfinite(v) for v in (x, y, w, h)):
        raise ValueError('Bounding box contains non-finite coordinates')

    if not (0.0 <= x <= 1.0 and 0.0 <= y <= 1.0):
        raise ValueError('Bounding box origin (x, y) must be within [0, 1]')

    if not (0.0 < w <= 1.0 and 0.0 < h <= 1.0):
        raise ValueError('Bounding box dimensions (w, h) must be in range (0, 1]')

    if x + w > 1.01 or y + h > 1.01:
        raise ValueError('Bounding box extends significantly beyond image boundaries')

    raw_im = None
    trans_im = None
    try:
        if isinstance(image_source, (str, Path)):
            raw_im = Image.open(image_source)
        elif isinstance(image_source, (bytes, bytearray)):
            raw_im = Image.open(io.BytesIO(image_source))
        else:
            raise TypeError('Unsupported image source')

        # Check decompression bomb guard
        if raw_im.width * raw_im.height > MAX_IMAGE_PIXELS:
            raise ValueError('Source image exceeds pixel guard')

        # Orient image according to EXIF
        trans_im = ImageOps.exif_transpose(raw_im)
        im = trans_im if trans_im is not None else raw_im
        if im.mode not in ('RGB', 'L'):
            im = im.convert('RGB')

        orig_w, orig_h = im.size

        # Normalized coordinates to oriented pixel coordinates
        px = x * orig_w
        py = y * orig_h
        pw = w * orig_w
        ph = h * orig_h

        # Add padding
        pad_x = pw * padding
        pad_y = ph * padding

        left = max(0, int(px - pad_x))
        top = max(0, int(py - pad_y))
        right = min(orig_w, int(px + pw + pad_x))
        bottom = min(orig_h, int(py + ph + pad_y))

        if right <= left or bottom <= top:
            raise ValueError('Bounding box falls outside image bounds')

        cropped = im.crop((left, top, right, bottom))

        # Resize keeping aspect ratio or fit into size x size
        out_size = min(max(32, size), MAX_OUTPUT_SIZE)
        cropped.thumbnail((out_size, out_size), Image.Resampling.LANCZOS)

        out = io.BytesIO()
        cropped.save(out, format='JPEG', quality=85, optimize=True)
        return out.getvalue()
    finally:
        if trans_im is not None and trans_im is not raw_im:
            try:
                trans_im.close()
            except Exception:
                pass
        if raw_im is not None:
            try:
                raw_im.close()
            except Exception:
                pass


def main():
    parser = argparse.ArgumentParser(description='Crop normalized face bounding box')
    parser.add_argument('--image', required=True, help='Path to source image')
    parser.add_argument('--bbox', required=True, help='Normalized bbox as x,y,w,h (0..1 floats)')
    parser.add_argument('--output', default='-', help='Output file path or - for stdout')
    parser.add_argument('--size', type=int, default=256, help='Target edge size')
    parser.add_argument('--padding', type=float, default=0.15, help='Bounding box padding ratio')
    args = parser.parse_args()

    coords = [float(v.strip()) for v in args.bbox.split(',')]
    result = crop_face(args.image, coords, size=args.size, padding=args.padding)

    if args.output == '-':
        sys.stdout.buffer.write(result)
    else:
        Path(args.output).write_bytes(result)


if __name__ == '__main__':
    main()
