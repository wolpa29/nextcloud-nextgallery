"""Ordered multi-image requests: original-resolution OCR tiles and video samples."""
from dataclasses import dataclass
from array import array
from bisect import bisect_left
import io
import json
import math
from pathlib import Path
import subprocess
import tempfile

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


def command(args, timeout=120, output=None):
    from media_pipeline import PipelineError
    check_budget()
    try:
        result = subprocess.run(args, stdin=subprocess.DEVNULL, stdout=output if output is not None else subprocess.PIPE,
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
VIDEO_SAMPLING_REVISION = 'video-endpoints-nearest-v2'


def video_timeline(path, max_pixels):
    """Decode frame timestamps, without retaining frames or a large JSON tree."""
    from media_pipeline import PipelineError
    timestamps = array('d')
    last_duration = None
    with tempfile.TemporaryFile() as output:
        command(['ffprobe', '-v', 'error', '-threads', '1', *INPUT_OPTIONS,
            '-select_streams', 'v:0', '-show_frames', '-show_entries',
            'frame=best_effort_timestamp_time,width,height,duration_time,pkt_duration_time:frame_side_data=',
            '-of', 'compact=p=1:nk=0', str(path)], output=output)
        output.seek(0)
        for line in output:
            check_budget()
            fields = line.decode('utf-8').strip().split('|')
            if fields[0] != 'frame':
                continue
            values = dict(field.split('=', 1) for field in fields[1:] if '=' in field)
            try:
                timestamp = float(values['best_effort_timestamp_time'])
                width, height = int(values['width']), int(values['height'])
            except (ValueError, KeyError):
                raise PipelineError('Video frame has no usable timestamp or dimensions') from None
            if not math.isfinite(timestamp) or (timestamps and timestamp < timestamps[-1]):
                raise PipelineError('Video frame timestamps are not finite and ordered')
            if width < 1 or height < 1 or width * height > max_pixels:
                raise PipelineError('Video frame exceeds decode memory guard')
            timestamps.append(timestamp)
            try:
                last_duration = float(values.get('duration_time') or values.get('pkt_duration_time') or '')
            except ValueError:
                last_duration = None
            if last_duration is not None and (not math.isfinite(last_duration) or last_duration <= 0):
                last_duration = None
    if not timestamps:
        raise PipelineError('Video has no decodable timestamped frames')
    return timestamps, last_duration


def video_samples(timestamps, count):
    """Nearest distinct frames to endpoint-inclusive, evenly spaced targets."""
    count = min(count, len(timestamps))
    first, last = timestamps[0], timestamps[-1]
    selected = []
    for slot in range(count):
        target = (first + last) / 2 if count == 1 else first + (last - first) * slot / (count - 1)
        if count > 1 and slot == 0:
            index = 0
        elif count > 1 and slot == count - 1:
            index = len(timestamps) - 1
        else:
            # Reserve enough later frames so no sample is repeated, including
            # videos with a long gap followed by a burst of dense frames.
            lower = selected[-1][0] + 1 if selected else 0
            upper = len(timestamps) - (count - slot)
            right = bisect_left(timestamps, target, lower, upper + 1)
            candidates = [i for i in (right - 1, right) if lower <= i <= upper]
            index = min(candidates, key=lambda i: (abs(timestamps[i] - target), i))
        selected.append((index, target))
    return selected


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
    count = config.get('frame_count', 10)
    if type(count) is not int or not 1 <= count <= 20:
        raise PipelineError('Invalid video frame count (1-20)')
    timestamps, last_duration = video_timeline(path, max_pixels)
    samples = video_samples(timestamps, count)
    # Container duration/count can be absent, rounded or include trailing audio.
    # Sampling is based exclusively on actual decoded frame timestamps.
    try:
        duration = float(stream.get('duration') or probe.get('format', {}).get('duration') or 0)
    except (TypeError, ValueError):
        duration = 0
    if not math.isfinite(duration) or duration <= 0:
        duration = timestamps[-1] - timestamps[0] + (last_duration or 0)
    count = len(samples)
    edge = config.get('max_edge', 2048)
    images, frames = [], []
    selection = '+'.join(f'eq(n\\,{index})' for index, _ in samples)
    scaling = ('setsar=1' if config.get('model_max_pixels', 0) else
        f'scale=w=min(iw\\,{edge}):h=min(ih\\,{edge}):force_original_aspect_ratio=decrease,setsar=1')
    with tempfile.TemporaryDirectory(prefix='media-ai-frames-') as temporary:
        # Select by decoded index in a single pass. Seeking to a rounded time
        # could return the next frame or skip the very last one again.
        command(['ffmpeg', '-nostdin', '-v', 'error', '-threads', '1', *INPUT_OPTIONS,
            '-i', str(path), '-map', '0:v:0', '-frames:v', str(count),
            '-vf', f'select={selection},{scaling}', '-fps_mode', 'passthrough',
            '-threads', '1', '-c:v', 'png', '-f', 'image2', str(Path(temporary) / '%02d.png')])
        for slot, (index, target) in enumerate(samples):
            check_budget()
            frame_path = Path(temporary) / f'{slot+1:02d}.png'
            if not frame_path.is_file():
                raise PipelineError(f'Could not decode selected video frame {index}; no incomplete video result published')
            wire = frame_path.read_bytes()
            with Image.open(io.BytesIO(wire)) as frame:
                detail = {}
                if frame.width * frame.height > max_pixels:
                    raise PipelineError('Video frame exceeds decode memory guard')
                if config.get('model_max_pixels', 0):
                    from media_ai_resolution import fit_image
                    frame = fit_image(frame.convert('RGB'), edge, config['model_max_pixels'], detail)
                    buffer = io.BytesIO(); frame.save(buffer, 'PNG'); wire = buffer.getvalue()
                frame_width, frame_height = frame.size
            timestamp = timestamps[index] - timestamps[0]
            frame_duration = timestamps[index+1] - timestamps[index] if index+1 < len(timestamps) else last_duration
            images.append((wire, 'image/png', f'Frame {slot+1}/{count}, Quellframe {index+1}/{len(timestamps)}, tatsaechlicher Zeitpunkt {timestamp:.6f} s ab erstem Frame.'))
            frames.append({'source_frame_index': index, 'sample_seconds': round(timestamp, 6),
                'requested_seconds': round(target - timestamps[0], 6), 'source_pts_seconds': timestamps[index],
                'frame_duration_seconds': round(frame_duration, 6) if frame_duration is not None else None,
                'width': frame_width, 'height': frame_height, **detail})
    report.update(image_count=len(images), source_width=width, source_height=height, duration_seconds=duration,
                  source_frame_count=len(timestamps), source_start_seconds=timestamps[0],
                  last_frame_seconds=round(timestamps[-1] - timestamps[0], 6), timestamp_source='best_effort_timestamp_time',
                  frames=frames, sampling=VIDEO_SAMPLING_REVISION, total_sent_bytes=sum(len(x[0]) for x in images))
    instructions = (f'Diese {count} Bilder sind zeitlich geordnete Frames aus EINEM Video von {duration:.3f} Sekunden. '
        'Bei mehreren Stichproben sind der erste und letzte Quellframe enthalten. Dazwischen wurden die naechsten unterschiedlichen Frames zu gleichmaessig verteilten Zeitpunkten ausgewaehlt. '
        'Die Beschriftungen nennen die tatsaechlichen Frame-Zeitpunkte ab dem ersten Quellframe; bei variabler Bildrate sind die Abstaende nicht exakt gleich. '
        'Analysiere ALLE Frames gemeinsam. Fasse die sicher sichtbaren Inhalte und belegbaren Veraenderungen in einer kurzen Videobeschreibung zusammen. '
        'Setze type auf video. Sammle Suchbegriffe und Objekte aus allen Frames, insgesamt maximal 20 unterschiedliche Begriffe. '
        'Keine OCR, keine Transkription von Bildtext oder Audio. Ereignisse zwischen Frames, Ton, Ursachen und Bewegungsrichtung nicht erfinden. '
        'Liefere EIN JSON-Objekt im Vision-Schema, keine separaten Frame-Ergebnisse.')
    return ImageBatch(images, instructions), 'image/png', {}
