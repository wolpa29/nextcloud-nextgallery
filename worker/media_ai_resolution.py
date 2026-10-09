"""Fit the complete image into a measured encoder area and patch grid."""
import math


def image_box(width, height, edge, pixels, grid=32):
    if type(pixels) is not int or pixels < grid * grid or edge < grid:
        raise ValueError('Invalid encoder pixel budget')
    best = None
    # Enumerating grid columns maximizes real image scale, not padding area.
    for w in range(grid, min(edge, math.ceil(width / grid) * grid) + 1, grid):
        h = min(edge // grid * grid, pixels // (w * grid) * grid,
                math.ceil(height / grid) * grid)
        if h < grid:
            continue
        scale = min(1, w / width, h / height)
        candidate = (scale, -w * h, w, h)
        if best is None or candidate > best:
            best = candidate
    if best is None:
        raise ValueError('Image cannot fit encoder limits')
    scale = best[0]
    size = (min(width, max(1, round(width * scale))), min(height, max(1, round(height * scale))))
    canvas = (math.ceil(size[0] / grid) * grid, math.ceil(size[1] / grid) * grid)
    return size, canvas


def fit_image(image, edge, pixels, report=None):
    from PIL import Image
    source = image.size
    size, canvas = image_box(*source, edge, pixels)
    if size != source:
        image = image.resize(size, Image.Resampling.LANCZOS)
    if canvas != size:
        padded = Image.new('RGB', canvas, 'white')
        padded.paste(image, (0, 0))
        image = padded
    if report is not None:
        report.update(content_width=size[0], content_height=size[1],
                      padding_right=canvas[0]-size[0], padding_bottom=canvas[1]-size[1],
                      model_max_pixels=pixels, encoder_grid=32, resized=size != source)
    return image
