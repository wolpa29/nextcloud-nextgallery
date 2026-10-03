import base64
import hashlib
import io
import json
import os
from pathlib import Path
import tempfile
import threading
import unittest
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from unittest.mock import patch

import media_pipeline as p

VISION = {'type': 'foto', 'description': 'Ein rotes Fahrrad am Strand.',
          'tags': ['Fahrrad', 'Strand'], 'objects': ['Fahrrad'], 'uncertain_details': []}


def image_bytes(color='red'):
    from PIL import Image
    out = io.BytesIO()
    image = Image.new('RGB', (100, 60), color)
    exif = Image.Exif()
    exif[271] = 'Testkamera'
    image.save(out, 'JPEG', exif=exif)
    return out.getvalue()


class Source:
    scope = 'test'
    def __init__(self):
        self.items = [p.Item('1', 'Camera/a.jpg', 'a', 'v1', 100)]
        self.data = image_bytes()
        self.reads = 0
        self.stable = True
        self.fail_inventory = False
    def inventory(self):
        if self.fail_inventory:
            raise p.PipelineError('incomplete listing')
        return self.items
    def read(self, item, maximum):
        self.reads += 1
        return self.data
    def unchanged(self, item):
        return self.stable


class Model:
    def __init__(self, stage):
        self.stage = stage
        self.config = {'max_edge': 128, 'model': 'fake-' + stage}
        self.revision = stage + '-1'
        self.calls = 0
        self.offline = self.fail = False
    def health(self):
        if self.offline:
            raise p.PipelineError('Connection unavailable: mock')
    def analyze(self, data, mime):
        self.calls += 1
        if self.fail:
            raise p.PipelineError('OCR failed')
        return dict(VISION) if self.stage == 'vision' else {'text': 'Rechnung 123', 'source': 'ocr_model'}


class Publisher:
    config = {'endpoint': 'mock'}
    def __init__(self):
        self.offline = False
        self.sent = []
    def capture(self, item, source):
        return {'file_id': 12, 'etag': 'nc-v1'}
    def send(self, row):
        if self.offline:
            raise p.PipelineError('Connection unavailable: metadata API')
        self.sent.append(json.loads(row['metadata']))


class PipelineTests(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        self.store = p.Store(Path(self.tmp.name) / 'state.sqlite')
        self.source = Source()
        self.models = {s: Model(s) for s in ('vision', 'ocr')}
        self.config = {}
    def tearDown(self):
        self.store.db.close()
        self.tmp.cleanup()
    def run_pipeline(self, **kwargs):
        return p.run(self.config, self.store, self.source, self.models, **kwargs)
    def test_initial_run_indexes_ocr_and_description(self):
        self.assertEqual(self.run_pipeline()['processed'], 1)
        self.assertEqual(len(self.store.search('Rechnung')), 1)
        self.assertEqual(len(self.store.search('Fahrrad Strand')), 1)
        row = self.store.db.execute('SELECT * FROM files').fetchone()
        self.assertEqual(row['sha256'], hashlib.sha256(self.source.data).hexdigest())
        self.assertEqual(json.loads(row['exif'])['camera_make'], 'Testkamera')
    def test_repetition_is_error_not_cached_published_or_searchable_and_can_retry(self):
        publisher = Publisher()
        original = self.models['ocr'].analyze
        self.models['ocr'].analyze = lambda data, mime: {'text': '[unlesbar]\n' * 100, 'source': 'ocr_model'}
        result = self.run_pipeline(publisher=publisher)
        self.assertEqual(result['errors'], 1)
        self.assertEqual(result['processed'], 0)
        self.assertEqual(self.store.stats(), {'error': 1})
        self.assertEqual(publisher.sent, [])
        self.assertEqual(self.store.db.execute("SELECT COUNT(*) FROM results WHERE stage='ocr'").fetchone()[0], 0)
        self.assertEqual(self.store.search('Fahrrad'), [])
        self.models['ocr'].analyze = original
        self.store.db.execute("UPDATE files SET retry_at=0 WHERE status='error'")
        self.store.db.commit()
        self.assertEqual(self.run_pipeline(publisher=publisher)['processed'], 1)
        self.assertEqual(len(publisher.sent), 1)
        self.assertEqual(self.models['vision'].calls, 1)
    def test_invalid_legacy_cached_loop_is_removed_before_manual_retry(self):
        hash_ = hashlib.sha256(self.source.data).hexdigest()
        self.store.cache(hash_, 'ocr', self.models['ocr'].revision, {'text': '[unlesbar]\n' * 100})
        self.assertEqual(self.run_pipeline()['errors'], 1)
        self.assertIsNone(self.store.cached(hash_, 'ocr', self.models['ocr'].revision))
        self.store.db.execute("UPDATE files SET retry_at=0")
        self.store.db.commit()
        self.assertEqual(self.run_pipeline()['processed'], 1)
    def test_repetition_detector_handles_inline_loops_and_allows_receipts(self):
        for text in ('[unlesbar] ' * 100, 'Hier steht immer derselbe Satz. ' * 50):
            with self.assertRaises(p.PipelineError): p.assert_no_repetition(text, 'OCR')
        receipt = '\n'.join(f'Artikel {n}\n0,00 EUR' for n in range(60))
        p.assert_no_repetition(receipt, 'OCR')
        p.assert_no_repetition('[unlesbar]\nDatum 1.6\n[unlesbar]', 'OCR')
    def test_repetition_detector_rejects_runaway_label_counters(self):
        for text in ('\n'.join(f'JUSB{n}' for n in range(1, 1000)),
                     'Address\n' + ', '.join(f'{n}F' for n in range(11, 101))):
            with self.assertRaisesRegex(p.PipelineError, 'nummerierte'):
                p.assert_no_repetition(text, 'OCR')
    def test_repetition_detector_preserves_numbered_instructions_and_real_labels(self):
        p.assert_no_repetition('\n'.join(f'{n}. Schritt: verschiedene Aufgabe {n*n}' for n in range(1, 101)), 'OCR')
        p.assert_no_repetition('JUSB1\nJUSB2\nJUSB3\nJUSB4\nJFP1\nJFP2\n11F, 12F, 13F, 14F', 'OCR')
    def test_unchanged_file_skips_download_and_inference(self):
        self.run_pipeline()
        self.run_pipeline()
        self.assertEqual(self.source.reads, 1)
        self.assertEqual([m.calls for m in self.models.values()], [1, 1])
    def test_changed_content_reanalyzed(self):
        self.run_pipeline()
        self.source.items[0].etag = 'v2'
        self.source.data = image_bytes('blue')
        self.run_pipeline()
        self.assertEqual([m.calls for m in self.models.values()], [2, 2])
    def test_rename_with_stable_id_updates_path_only(self):
        self.run_pipeline()
        self.source.items[0].path = 'Camera/new.jpg'
        self.run_pipeline()
        self.assertEqual(self.source.reads, 1)
        self.assertEqual(self.store.search('Fahrrad')[0]['path'], 'Camera/new.jpg')
    def test_deleted_file_is_removed_from_search_not_history(self):
        self.run_pipeline()
        self.source.items = []
        self.run_pipeline()
        self.assertEqual(self.store.search('Fahrrad'), [])
        self.assertEqual(self.store.db.execute('SELECT count(*) FROM files').fetchone()[0], 1)
    def test_duplicate_content_reuses_both_stages(self):
        self.source.items.append(p.Item('2', 'Other/duplicate.jpg', 'b', 'other', 100))
        result = self.run_pipeline()
        self.assertEqual(result['processed'], 2)
        self.assertEqual(result['cached_stage_results'], 2)
        self.assertEqual([m.calls for m in self.models.values()], [1, 1])
    def test_partial_analysis_resumes_successful_stage(self):
        self.models['ocr'].fail = True
        self.assertEqual(self.run_pipeline()['errors'], 1)
        self.assertEqual(self.store.search('Fahrrad'), [])
        self.store.db.execute('UPDATE files SET retry_at=0'); self.store.db.commit()
        self.models['ocr'].fail = False
        self.assertEqual(self.run_pipeline()['processed'], 1)
        self.assertEqual([m.calls for m in self.models.values()], [1, 2])
    def test_offline_gpu_does_not_read_source(self):
        self.models['vision'].offline = True
        self.source.fail_inventory = True
        self.assertEqual(self.run_pipeline()['state'], 'deferred')
        self.assertEqual(self.source.reads, 0)
    def test_partial_listing_never_retires_existing_files(self):
        self.run_pipeline()
        self.source.fail_inventory = True
        with self.assertRaises(p.PipelineError): self.run_pipeline()
        self.assertEqual(self.store.stats(), {'done': 1})
    def test_source_modified_mid_analysis_is_not_indexed(self):
        self.source.stable = False
        self.assertEqual(self.run_pipeline()['errors'], 1)
        self.assertEqual(self.store.search('Fahrrad'), [])
    def test_model_revision_reanalyzes_only_changed_stage(self):
        self.run_pipeline()
        self.models['vision'].revision = 'vision-2'
        self.run_pipeline()
        self.assertEqual([m.calls for m in self.models.values()], [2, 1])
    def test_lease_blocks_other_controller_and_releases_after_error(self):
        other = p.Store(Path(self.tmp.name) / 'state.sqlite')
        try:
            with self.store.lease():
                with self.assertRaises(p.PipelineError):
                    with other.lease(): pass
            with other.lease(): pass
        finally: other.db.close()
    def test_publish_can_resume_without_gpu(self):
        publisher = Publisher(); publisher.offline = True
        self.run_pipeline(publisher=publisher)
        self.assertEqual(self.store.stats(), {'done': 1})
        publisher.offline = False
        self.models['vision'].offline = True
        result = self.run_pipeline(publisher=publisher)
        self.assertEqual(result['state'], 'deferred')
        self.assertEqual(result['published'], 1)
        self.assertEqual(len(publisher.sent), 1)
    def test_publishing_is_idempotent(self):
        publisher = Publisher()
        self.run_pipeline(publisher=publisher)
        self.run_pipeline(publisher=publisher)
        self.assertEqual(len(publisher.sent), 1)
    def test_limit_bounds_first_pilot_batch(self):
        self.source.items.append(p.Item('2', 'b.jpg', 'b', 'v1', 100))
        self.assertEqual(self.run_pipeline(limit=1)['processed'], 1)
        self.assertEqual(self.store.stats(), {'done': 1, 'pending': 1})
    def test_originals_stay_byte_identical_after_preprocessing(self):
        original = self.source.data
        for stage in ('vision', 'ocr'):
            wire, mime, exif = p.prepare_image(original, 32, 1_000_000, stage)
            from PIL import Image
            with Image.open(io.BytesIO(wire)) as img:
                self.assertLessEqual(max(img.size), 32)
            self.assertEqual(exif['camera_make'], 'Testkamera')
        self.assertEqual(original, self.source.data)
    def test_full_frame_landscape_portrait_panorama_and_small_image(self):
        from PIL import Image, ImageDraw
        for size in ((4000,3000),(3000,4000),(6000,600),(50,30)):
            original = Image.new('RGB',size,'white'); draw = ImageDraw.Draw(original)
            w,h=size; side=min(w,h)//5
            colors=[(255,0,0),(0,0,255),(0,128,0),(255,255,0)]
            for (x,y),color in zip(((0,0),(w-side,0),(0,h-side),(w-side,h-side)),colors):
                draw.rectangle((x,y,x+side-1,y+side-1),fill=color)
            buffer=io.BytesIO();original.save(buffer,'PNG');data=buffer.getvalue();before=hashlib.sha256(data).hexdigest()
            for stage in ('vision','ocr'):
                report={};wire,mime,exif=p.prepare_image(data,2048,80_000_000,stage,report=report)
                with Image.open(io.BytesIO(wire)) as sent:
                    sent.load();sw,sh=sent.size
                    self.assertLessEqual(max(sent.size),2048)
                    self.assertLessEqual(sw,w);self.assertLessEqual(sh,h)
                    self.assertAlmostEqual(sw/sh,w/h,delta=(w/h)/min(sw,sh))
                    for pos,color in zip(((0,0),(sw-1,0),(0,sh-1),(sw-1,sh-1)),colors):
                        self.assertLess(max(abs(a-b) for a,b in zip(sent.getpixel(pos),color)),15)
                    self.assertEqual(report['sent_pixels'],sw*sh)
                    self.assertEqual((report['sent_width'],report['sent_height']),sent.size)
                    if size==(4000,3000):self.assertEqual(sent.size,(2048,1536))
                    if size==(50,30):self.assertEqual(sent.size,size);self.assertFalse(report['resized'])
                self.assertTrue(report['full_frame']);self.assertEqual(report['sha256'],hashlib.sha256(wire).hexdigest())
                self.assertEqual(report['sent_bytes'],len(wire));self.assertEqual(report['mime'],mime)
            self.assertEqual(hashlib.sha256(data).hexdigest(),before)
    def test_exif_rotation_precedes_full_frame_resizing(self):
        from PIL import Image
        im=Image.new('RGB',(80,40),'red');exif=Image.Exif();exif[274]=6
        buffer=io.BytesIO();im.save(buffer,'JPEG',exif=exif)
        report={};wire,_,_=p.prepare_image(buffer.getvalue(),32,1_000_000,'ocr',report=report)
        with Image.open(io.BytesIO(wire)) as sent:self.assertEqual(sent.size,(16,32));self.assertNotIn(274,sent.getexif())
        self.assertEqual((report['source_width'],report['source_height']),(80,40))
        self.assertEqual((report['oriented_width'],report['oriented_height']),(40,80))
    def test_decode_guard_is_separate_from_model_resizing(self):
        import warnings
        with warnings.catch_warnings():
            warnings.simplefilter('ignore')
            with self.assertRaisesRegex(p.PipelineError,'decode memory guard'):
                p.prepare_image(self.source.data,32,5000,'ocr')
        with self.assertRaisesRegex(p.PipelineError,'edge limit'):
            p.prepare_image(self.source.data,0,1_000_000,'ocr')
    def test_preparation_dimensions_are_in_provenance_and_file_log(self):
        events=[];self.run_pipeline(observe=lambda event,**fields:events.append((event,fields)))
        metadata=json.loads(self.store.db.execute('SELECT metadata FROM files').fetchone()[0])
        images=metadata['provenance']['images']
        self.assertEqual(images['ocr']['sent_width'],100);self.assertEqual(images['vision']['sent_height'],60)
        finished=next(fields for event,fields in events if event=='file_finished')
        self.assertEqual(finished['images'],images)
        for event,fields in events:
            if event=='stage_finished':self.assertTrue(fields['image']['full_frame'])
    def test_source_limits_reject_image(self):
        self.config['max_download_bytes'] = 50
        self.assertEqual(self.run_pipeline()['errors'], 1)
        self.assertEqual(self.source.reads, 0)
    def test_bad_vision_schema_rejected(self):
        for value in ({}, dict(VISION, type=[]), dict(VISION, tags=[3])):
            with self.assertRaises(p.PipelineError): p.validate_vision(value)
    def test_local_symlinks_skipped_and_identity_change_detected(self):
        root = Path(self.tmp.name) / 'images'; root.mkdir()
        file = root / 'a.jpg'; file.write_bytes(self.source.data)
        (root / 'link.jpg').symlink_to(file)
        source = p.DirectorySource({'directory': str(root)})
        items = source.inventory()
        self.assertEqual(len(items), 1)
        self.assertEqual(source.read(items[0], 1_000_000), self.source.data)
        file.unlink(); file.write_bytes(image_bytes('blue'))
        self.assertFalse(source.unchanged(items[0]))
    def test_local_hardlinks_are_distinct_visible_files(self):
        root = Path(self.tmp.name) / 'images'; root.mkdir()
        first = root / 'a.jpg'; first.write_bytes(self.source.data)
        os.link(first, root / 'b.jpg')
        source = p.DirectorySource({'directory': str(root)})
        items = source.inventory()
        self.assertEqual(len(items), 2)
        self.assertNotEqual(items[0].key, items[1].key)


class HTTPTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.calls = []
        cls.finish_reason = 'stop'
        cls.model_content = json.dumps(VISION)
        cls.dav_xml = b'<d:multistatus xmlns:d="DAV:"/>'
        cls.ocs_status = 200
        cls.message_extra = {}
        class Handler(BaseHTTPRequestHandler):
            def log_message(self, *args): pass
            def reply(self, body, code=200, headers=None):
                self.send_response(code)
                for k, v in (headers or {}).items(): self.send_header(k, v)
                self.send_header('Content-Length', str(len(body))); self.end_headers()
                if self.command != 'HEAD': self.wfile.write(body)
            def do_GET(self):
                cls.calls.append((self.command, self.path, dict(self.headers), None))
                if self.path == '/redirect': return self.reply(b'', 302, {'Location': '/models'})
                if self.path == '/v1/models': return self.reply(json.dumps({'data': [{'id': 'test-model'}]}).encode())
                return self.reply(json.dumps({'ocs': {'meta': {'statuscode': 200}, 'data': {'file_id': 7, 'etag': 'v1'}}}).encode())
            def do_POST(self):
                body = json.loads(self.rfile.read(int(self.headers['Content-Length'])))
                cls.calls.append((self.command, self.path, dict(self.headers), body))
                if self.path.startswith('/v1/chat'):
                    return self.reply(json.dumps({'choices': [{'finish_reason': cls.finish_reason, 'message': {'content': cls.model_content, **cls.message_extra}}]}).encode())
                return self.reply(json.dumps({'ocs': {'meta': {'statuscode': 200}, 'data': {'stored': True}}}).encode(), cls.ocs_status)
            def do_PROPFIND(self):
                cls.calls.append((self.command, self.path, dict(self.headers), self.rfile.read(int(self.headers['Content-Length']))))
                return self.reply(cls.dav_xml, 207)
        cls.server = ThreadingHTTPServer(('127.0.0.1', 0), Handler)
        cls.thread = threading.Thread(target=cls.server.serve_forever, daemon=True); cls.thread.start()
        cls.url = 'http://127.0.0.1:' + str(cls.server.server_port)
    @classmethod
    def tearDownClass(cls):
        cls.server.shutdown(); cls.server.server_close(); cls.thread.join()
    def setUp(self):
        type(self).calls.clear(); type(self).finish_reason = 'stop'; type(self).model_content = json.dumps(VISION); type(self).ocs_status = 200; type(self).message_extra = {}
    def test_real_model_wire_protocol(self):
        model = p.Model({'url': self.url + '/v1', 'model': 'test-model', 'api_key_env': 'MOCK_KEY'}, 'vision')
        with patch.dict(os.environ, {'MOCK_KEY': 'test-only'}):
            model.health()
            self.assertEqual(model.analyze(b'fake', 'image/jpeg'), VISION)
        body = self.calls[-1][3]
        self.assertTrue(body['messages'][0]['content'][1]['image_url']['url'].startswith('data:image/jpeg;base64,'))
        self.assertEqual(self.calls[-1][2]['Authorization'], 'Bearer test-only')
        self.assertNotIn('tools', body)
    def test_prepared_image_is_transmitted_as_complete_identical_base64(self):
        from PIL import Image
        original=image_bytes();wire,mime,_=p.prepare_image(original,32,1_000_000,'ocr')
        type(self).model_content=json.dumps({'text':''})
        model=p.Model({'url':self.url+'/v1','model':'test-model'},'ocr');model.analyze(wire,mime)
        url=self.calls[-1][3]['messages'][0]['content'][1]['image_url']['url']
        prefix,encoded=url.split(',',1);self.assertEqual(prefix,'data:'+mime+';base64')
        decoded=base64.b64decode(encoded,validate=True);self.assertEqual(decoded,wire)
        with Image.open(io.BytesIO(decoded)) as sent:sent.load();self.assertEqual(sent.size,(32,19))
    def test_unlimited_output_does_not_send_token_or_reasoning_limit(self):
        model = p.Model({'url': self.url+'/v1', 'model': 'test-model', 'max_tokens': 0}, 'vision')
        model.analyze(b'x', 'image/jpeg')
        body = self.calls[-1][3]
        for key in ('max_tokens', 'max_completion_tokens', 'reasoning_effort', 'thinking_budget'):
            self.assertNotIn(key, body)
        self.assertEqual(body['messages'][0]['content'][0]['text'], p.VISION_PROMPT)
    def test_explicit_optional_output_limit_is_sent(self):
        p.Model({'url': self.url+'/v1', 'model': 'test-model', 'max_tokens': 2000}, 'vision').analyze(b'x', 'image/jpeg')
        self.assertEqual(self.calls[-1][3]['max_tokens'], 2000)
    def test_truncated_response_rejected(self):
        type(self).finish_reason = 'length'
        with self.assertRaises(p.PipelineError):
            p.Model({'url': self.url+'/v1', 'model': 'test-model'}, 'vision').analyze(b'x', 'image/jpeg')
    def test_reasoning_json_requires_explicit_opt_in_and_valid_schema(self):
        type(self).model_content = ''
        type(self).message_extra = {'reasoning_content': json.dumps(VISION)}
        config = {'url': self.url+'/v1', 'model': 'test-model'}
        with self.assertRaises(p.PipelineError): p.Model(config, 'vision').analyze(b'x', 'image/jpeg')
        model = p.Model(config | {'allow_reasoning_json': True}, 'vision')
        self.assertEqual(model.analyze(b'x', 'image/jpeg'), VISION)
        self.assertEqual(model.output_channel, 'reasoning_json_workaround')
    def test_prose_and_reasoning_plus_json_are_not_published(self):
        type(self).model_content = ''
        config = {'url': self.url+'/v1', 'model': 'test-model', 'allow_reasoning_json': True}
        for content in ('Let me think first. ' + json.dumps(VISION), json.dumps({'answer': 'invalid schema'})):
            type(self).message_extra = {'reasoning_content': content}
            with self.assertRaises((p.PipelineError, ValueError)):
                p.Model(config, 'vision').analyze(b'x', 'image/jpeg')
    def test_ocr_never_accepts_reasoning_as_transcription(self):
        type(self).model_content = ''
        type(self).message_extra = {'reasoning_content': 'I think the text says Rechnung'}
        config = {'url': self.url+'/v1', 'model': 'test-model', 'allow_reasoning_json': True}
        with self.assertRaises(p.PipelineError): p.Model(config, 'ocr').analyze(b'x', 'image/png')
    def test_unexpected_model_tool_calls_are_rejected(self):
        type(self).message_extra = {'tool_calls': [{'id': 'fake-action'}]}
        with self.assertRaises(p.PipelineError):
            p.Model({'url': self.url+'/v1', 'model': 'test-model'}, 'vision').analyze(b'x', 'image/jpeg')
    def test_ocr_preserves_literal_text(self):
        type(self).model_content = json.dumps({'text': 'Rechnung\n123 €'})
        result = p.Model({'url': self.url+'/v1', 'model': 'test-model'}, 'ocr').analyze(b'x', 'image/png')
        self.assertEqual(result['text'], 'Rechnung\n123 €')
    def test_redirects_and_oversized_responses_rejected(self):
        with self.assertRaises(p.PipelineError): p.request(self.url+'/redirect')
        with self.assertRaises(p.PipelineError): p.request(self.url+'/v1/models', maximum=2)
    def dav(self):
        with patch.dict(os.environ, {'MOCK_PASSWORD': 'test-only'}):
            return p.WebDAVSource({'url': self.url+'/dav/', 'username': 'paul', 'password_env': 'MOCK_PASSWORD', 'request_pause_seconds': 0})
    def test_webdav_empty_and_failed_propstat_rejected(self):
        source = self.dav()
        with self.assertRaises(p.PipelineError): source.inventory()
        type(self).dav_xml = b'<d:multistatus xmlns:d="DAV:"><d:response><d:href>/dav/a.jpg</d:href><d:propstat><d:status>HTTP/1.1 403 Forbidden</d:status></d:propstat></d:response></d:multistatus>'
        with self.assertRaises(p.PipelineError): source.inventory()
    def test_webdav_scope_cannot_escape(self):
        source = self.dav()
        for href in ('http://other.invalid/dav/a.jpg', '/else/a.jpg', '/dav/%2e%2e/a.jpg'):
            with self.assertRaises(p.PipelineError): source._url(href)
    def test_publisher_ocs_wire_and_stale_conflict(self):
        with patch.dict(os.environ, {'MOCK_PASSWORD': 'test-only'}):
            pub = p.Publisher({'url': self.url, 'username': 'paul', 'password_env': 'MOCK_PASSWORD', 'path_prefix': '/InstantUpload'})
        target = pub.capture(p.Item('1', 'Camera/a.jpg', 'a', 'v1', 10), Source())
        self.assertIn('InstantUpload', self.calls[-1][1])
        self.assertEqual(target, {'file_id': 7, 'etag': 'v1'})
        row = {'metadata': json.dumps({'vision': VISION, 'ocr': {'text': ''}, 'nextcloud_target': target}), 'exif': '{}', 'sha256': 'a'*64, 'revision': 'b'*64}
        pub.send(row)
        self.assertEqual(self.calls[-1][2]['Ocs-Apirequest'], 'true')
        self.assertEqual(self.calls[-1][3]['file_id'], 7)
        type(self).ocs_status = 409
        with self.assertRaisesRegex(p.PipelineError, '409'): pub.send(row)


if __name__ == '__main__': unittest.main()
