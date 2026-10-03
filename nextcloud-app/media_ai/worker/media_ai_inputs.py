"""Ordered multi-image requests: original-resolution OCR tiles and video samples."""
from dataclasses import dataclass
import io
import json
import math
from pathlib import Path
import subprocess

from media_ai_budget import check_budget, network_timeout


@dataclass
class ImageBatch:
    images: list  # (bytes, MIME, trusted spatial/temporal label)
    instructions: str


def starts(length, edge, overlap):
    if length <= edge:
        return [0]
    count = math.ceil((length - edge) / (edge - overlap)) + 1
    return [round(i * (length - edge) / (count - 1)) for i in range(count)]


def prepare_ocr(data, config, max_pixels, prepare_image, report):
    from PIL import Image, ImageOps
    from media_pipeline import PipelineError
    edge = config.get('max_edge', 2048)
    area = config.get('model_max_pixels', 0)
    overview, mime, exif = prepare_image(data, edge, max_pixels, 'ocr', report=report, model_max_pixels=area)
    images = [(overview, mime, 'Bild 1: vollstaendiges Gesamtbild zur Orientierung.')]
    details = []
    with Image.open(io.BytesIO(data)) as original:
        image = ImageOps.exif_transpose(original).convert('RGB')
        width, height = image.size
        tile_width = tile_height = edge
        if area:
            from media_ai_resolution import image_box
            _, (tile_width, tile_height) = image_box(width, height, edge, area)
        if width > tile_width or height > tile_height:
            overlap = max(1, round(min(tile_width, tile_height) * config.get('tile_overlap_percent', 10) / 100))
            if not 0 < overlap < min(tile_width, tile_height):
                raise PipelineError('Invalid OCR tile overlap')
            xs, ys = starts(width, tile_width, overlap), starts(height, tile_height, overlap)
            count = 1 + len(xs) * len(ys)
            maximum = config.get('max_batch_images', 20)
            if count > maximum:
                raise PipelineError(f'OCR needs {count} images including the overview; batch limit is {maximum}. Increase batch/context limits or use a larger tile edge. No regions were omitted.')
            for y in ys:
                for x in xs:
                    check_budget()
                    box = (x, y, min(x + tile_width, width), min(y + tile_height, height))
                    tile = image.crop(box)
                    if area:
                        from media_ai_resolution import fit_image
                        tile = fit_image(tile, edge, area)
                    buffer = io.BytesIO(); tile.save(buffer, 'PNG')
                    wire = buffer.getvalue()
                    label = f'Bild {len(images)+1}: Originalausschnitt, Koordinaten links/oben/rechts/unten {box}, Gesamtbild {width}x{height}. Keine Verkleinerung.'
                    images.append((wire, 'image/png', label))
                    details.append({'box': list(box), 'width': tile.width, 'height': tile.height, 'resized': False})
    report.update(image_count=len(images), tiles=details, tile_overlap_percent=config.get('tile_overlap_percent', 10),
                  total_sent_bytes=sum(len(image[0]) for image in images))
    return ImageBatch(images, 'Alle Bilder gehoeren zu EINEM Original. Bild 1 ist die verkleinerte Gesamtansicht; danach folgen ueberlappende Originalausschnitte in zeilenweiser Reihenfolge. '
        'Nutze die Ausschnitte fuer kleine Schrift und die Gesamtansicht fuer Anordnung und Lesereihenfolge. Fuehre alles zu EINER Transkription im Feld text zusammen. '
        'Text in Ueberlappungen und Gesamtansicht nicht mehrfach ausgeben; tatsaechlich an verschiedenen Stellen wiederholten Text erhalten. Keine Bereichslabels im Ergebnis.'), 'image/png', exif


def command(args, timeout=120):
    from media_pipeline import PipelineError
    check_budget()
    try:
        result = subprocess.run(args, stdin=subprocess.DEVNULL, stdout=subprocess.PIPE,
                                stderr=subprocess.PIPE, timeout=network_timeout(timeout), check=True)
    except subprocess.TimeoutExpired:
        check_budget()
        raise PipelineError('Video decoder timed out') from None
    except FileNotFoundError:
        raise PipelineError('Video analysis requires ffmpeg and ffprobe on the worker') from None
    except subprocess.CalledProcessError:
        check_budget()
        raise PipelineError('Video decoder could not read this file') from None
    check_budget()
    return result.stdout


# Exclude playlist demuxers and network protocols; only the private local input.
INPUT_OPTIONS = ['-protocol_whitelist', 'file,pipe', '-format_whitelist', 'mov,matroska,webm,avi,mpegts,mpeg,flv,ogg']


def prepare_video(path, config, max_pixels, report):
    from PIL import Image
    from media_pipeline import PipelineError
    probe = json.loads(command(['ffprobe', '-v', 'error', *INPUT_OPTIONS, '-select_streams', 'v:0',
        '-show_entries', 'stream=width,height,duration,nb_frames:format=duration', '-of', 'json', str(path)]))
    streams = probe.get('streams', [])
    if not streams:
        raise PipelineError('Video has no decodable video stream')
    stream = streams[0]
    width, height = int(stream['width']), int(stream['height'])
    if width < 1 or height < 1 or width * height > max_pixels:
        raise PipelineError('Video frame exceeds decode memory guard')
    duration = float(stream.get('duration') or probe.get('format', {}).get('duration') or 0)
    if not math.isfinite(duration) or duration <= 0:
        raise PipelineError('Video has no valid finite duration')
    count = config.get('frame_count', 10)
    if type(count) is not int or not 1 <= count <= 20:
        raise PipelineError('Invalid video frame count (1-20)')
    if str(stream.get('nb_frames', '')).isdigit() and int(stream['nb_frames']) > 0:
        count = min(count, int(stream['nb_frames']))
    edge = config.get('max_edge', 2048)
    images, frames = [], []
    for index in range(count):
        timestamp = duration * (index + .5) / count
        wire = command(['ffmpeg', '-nostdin', '-v', 'error', '-threads', '1', *INPUT_OPTIONS,
            '-ss', f'{timestamp:.9f}', '-i', str(path), '-map', '0:v:0', '-frames:v', '1',
            '-vf', ('setsar=1' if config.get('model_max_pixels', 0) else f'scale=w=min(iw\\,{edge}):h=min(ih\\,{edge}):force_original_aspect_ratio=decrease,setsar=1'),
            '-threads', '1', '-f', 'image2pipe', '-c:v', 'png', 'pipe:1'])
        if not wire:
            raise PipelineError(f'No frame at sampling point {timestamp:.3f}s; no incomplete video result published')
        with Image.open(io.BytesIO(wire)) as frame:
            detail = {}
            if config.get('model_max_pixels', 0):
                from media_ai_resolution import fit_image
                frame = fit_image(frame.convert('RGB'), edge, config['model_max_pixels'], detail)
                buffer = io.BytesIO(); frame.save(buffer, 'PNG'); wire = buffer.getvalue()
            frame_width, frame_height = frame.size
        images.append((wire, 'image/png', f'Frame {index+1}/{count}, angeforderter Zeitpunkt {timestamp:.3f} s.'))
        frames.append({'sample_seconds': round(timestamp, 6), 'width': frame_width, 'height': frame_height, **detail})
    report.update(image_count=len(images), source_width=width, source_height=height, duration_seconds=duration,
                  frames=frames, sampling='equal_interval_midpoints', total_sent_bytes=sum(len(x[0]) for x in images))
    instructions = (f'Diese {count} Bilder sind zeitlich geordnete Frames aus EINEM Video von {duration:.3f} Sekunden. '
        f'Die Stichproben liegen gleichmaessig im Abstand {duration/count:.3f} Sekunden, jeweils in der Mitte eines Zeitabschnitts. '
        'Analysiere ALLE Frames gemeinsam. Fasse die sicher sichtbaren Inhalte und belegbaren Veraenderungen in einer kurzen Videobeschreibung zusammen. '
        'Setze type auf video. Sammle Suchbegriffe und Objekte aus allen Frames, insgesamt maximal 20 unterschiedliche Begriffe. '
        'Keine OCR, keine Transkription von Bildtext oder Audio. Ereignisse zwischen Frames, Ton, Ursachen und Bewegungsrichtung nicht erfinden. '
        'Liefere EIN JSON-Objekt im Vision-Schema, keine separaten Frame-Ergebnisse.')
    return ImageBatch(images, instructions), 'image/png', {}
