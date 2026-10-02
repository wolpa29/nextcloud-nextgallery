"""Run a real GPU pilot with generated images only; never accesses Nextcloud."""
from __future__ import annotations
import argparse
import hashlib
import json
import os
from pathlib import Path
import shutil
import sys
import time

sys.path.insert(0, str(Path(__file__).resolve().parents[1]))
import media_pipeline as p
from PIL import Image, ImageDraw, ImageFont


def fixtures(root):
    root.mkdir(parents=True, exist_ok=False)
    font = ImageFont.truetype('/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf', 42)
    small = ImageFont.truetype('/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf', 30)
    receipt = Image.new('RGB', (1000, 900), 'white')
    draw = ImageDraw.Draw(receipt)
    lines = ['NEXTCLOUD TEST 2026', 'RECHNUNG 12345', 'Fahrradwerkstatt', 'Datum: 02.10.2026', 'Betrag: 42,50 EUR']
    for i, line in enumerate(lines): draw.text((60, 60 + i * 130), line, fill='black', font=font)
    receipt.save(root/'01_receipt.png')
    screenshot = Image.new('RGB', (1000, 900), '#f0f2f5')
    draw = ImageDraw.Draw(screenshot)
    draw.rectangle((0, 0, 1000, 100), fill='#087e8b')
    draw.text((35, 20), 'Chat - Testansicht', fill='white', font=font)
    draw.rounded_rectangle((50, 160, 930, 380), radius=20, fill='white')
    draw.text((80, 195), 'Treffen am Strand um 17:30 Uhr.', fill='black', font=small)
    draw.text((80, 265), 'Bitte bring das rote Fahrrad mit.', fill='black', font=small)
    screenshot.save(root/'02_chat_screenshot.png')
    beach = Image.new('RGB', (1280, 720), '#80cbf5')
    draw = ImageDraw.Draw(beach)
    draw.rectangle((0, 350, 1280, 520), fill='#1679aa')
    draw.rectangle((0, 520, 1280, 720), fill='#f7d798')
    draw.ellipse((980, 55, 1110, 185), fill='#ffe253')
    for x in (380, 700): draw.ellipse((x-90, 485, x+90, 665), outline='black', width=10)
    draw.line([(380,575),(495,405),(565,575),(380,575),(635,405),(700,575)], fill='#cf242c', width=12)
    draw.line([(495,405),(635,405)], fill='#cf242c', width=12)
    draw.line([(495,405),(480,380),(535,380)], fill='black', width=8)
    draw.line([(635,405),(620,370),(675,350)], fill='black', width=8)
    beach.save(root/'03_bicycle_beach.jpg', quality=95)
    shutil.copyfile(root/'01_receipt.png', root/'04_identical_receipt.png')


class MeasuredSource(p.DirectorySource):
    reads = 0
    def read(self, item, maximum):
        self.reads += 1
        return super().read(item, maximum)


def main():
    os.umask(0o077)
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--output', required=True, help='New, separate pilot directory')
    parser.add_argument('--url', default='http://10.1.10.78:1234/v1')
    parser.add_argument('--model', default='qwen/qwen3.8-27b')
    args = parser.parse_args()
    output = Path(args.output).resolve()
    output.mkdir(parents=True, exist_ok=False)
    source_dir = output/'generated-images'
    fixtures(source_dir)
    config = json.loads((Path(__file__).resolve().parents[1]/'config.example.json').read_text())
    config['database'] = str(output/'state'/'media.sqlite3')
    config['source'] = {'kind': 'directory', 'directory': str(source_dir)}
    config['nextcloud'] = {'enabled': False}
    for stage in ('vision', 'ocr'):
        config[stage].update(url=args.url, model=args.model, model_revision='live-pilot-Q4_K_M-20261002', timeout_seconds=300)
    config['vision']['allow_reasoning_json'] = True
    (output/'config.json').write_text(json.dumps(config, indent=2)+'\n')
    before = {x.name: hashlib.sha256(x.read_bytes()).hexdigest() for x in source_dir.iterdir()}
    records = []
    original_request = p.request
    def timed_request(url, method='GET', body=None, *rest, **kwargs):
        stage = None
        if url.endswith('/chat/completions'):
            stage = 'vision' if 'response_format' in json.loads(body) else 'ocr'
            print(f'{stage}: inference started', flush=True)
        started = time.monotonic()
        wire, headers = original_request(url, method, body, *rest, **kwargs)
        if stage:
            response = json.loads(wire)
            record = {'stage': stage, 'seconds': round(time.monotonic()-started, 3),
                      'usage': response.get('usage'), 'finish_reason': response['choices'][0].get('finish_reason')}
            records.append(record)
            print(json.dumps(record), flush=True)
            (output/'requests.json').write_text(json.dumps(records, indent=2)+'\n')
        return wire, headers
    p.request = timed_request
    source = MeasuredSource(config['source'])
    models = {s: p.Model(config[s], s) for s in ('vision', 'ocr')}
    store = p.Store(config['database'])
    report = {'model': args.model, 'url': args.url, 'synthetic_images_only': True}
    try:
        report['first_run'] = p.run(config, store, source, models, limit=4, max_seconds=1800)
        report['inference_calls_first_run'] = len(records)
        report['image_reads_first_run'] = source.reads
        initial_reads = source.reads
        report['second_run'] = p.run(config, store, source, models, limit=4, max_seconds=1800)
        report['inference_calls_second_run'] = len(records)-report['inference_calls_first_run']
        report['image_reads_second_run'] = source.reads-initial_reads
        rows = store.db.execute("SELECT path,metadata FROM files WHERE active=1 AND status='done' ORDER BY path").fetchall()
        report['results'] = [{**dict(r), 'metadata': json.loads(r['metadata'])} for r in rows]
        report['search_fahrrad'] = [r['path'] for r in store.search('Fahrrad')]
        report['search_rechnung'] = [r['path'] for r in store.search('RECHNUNG 12345')]
        after = {x.name: hashlib.sha256(x.read_bytes()).hexdigest() for x in source_dir.iterdir()}
        report['originals_unchanged'] = before == after
        receipt = next((r['metadata']['ocr']['text'] for r in report['results'] if r['path']=='01_receipt.png'), '')
        screenshot = next((r['metadata']['ocr']['text'] for r in report['results'] if r['path']=='02_chat_screenshot.png'), '')
        report['checks'] = {
            'four_files_done': report['first_run']['processed'] == 4,
            'three_unique_images_six_inferences': report['inference_calls_first_run'] == 6,
            'duplicate_reused': report['first_run'].get('cached_stage_results') == 2,
            'second_run_zero_inferences': report['inference_calls_second_run'] == 0,
            'second_run_zero_image_reads': report['image_reads_second_run'] == 0,
            'receipt_ocr_expected_tokens': all(x in receipt for x in ['12345','42,50','2026']),
            'screenshot_ocr_expected_tokens': all(x in screenshot for x in ['17:30','Fahrrad']),
            'local_ocr_search_returns_both_receipts': len(report['search_rechnung']) == 2,
            'originals_unchanged': report['originals_unchanged'],
        }
        report['passed'] = all(report['checks'].values())
        print(json.dumps({k:v for k,v in report.items() if k!='results'}, ensure_ascii=False, indent=2), flush=True)
        return 0 if report['passed'] else 1
    finally:
        report['requests'] = records
        (output/'report.json').write_text(json.dumps(report, ensure_ascii=False, indent=2)+'\n')
        store.db.close()


if __name__ == '__main__': raise SystemExit(main())
