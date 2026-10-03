import base64
import hashlib
import io
import json
from pathlib import Path
import shutil
import subprocess
import tempfile
import threading
import unittest
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from unittest.mock import patch

from PIL import Image
import media_pipeline as p
from media_ai_inputs import ImageBatch, prepare_ocr, prepare_video
from media_ai_budget import ImageBudget, ImageTimeout
from test_pipeline import Source, Model, VISION, Publisher


class MultiImageTests(unittest.TestCase):
    def original(self, size=(120,90)):
        image=Image.new('RGB',size)
        image.putdata([(x%256,y%256,(x+y)%256) for y in range(size[1]) for x in range(size[0])])
        out=io.BytesIO(); image.save(out,'PNG');return out.getvalue()

    def test_tiles_preserve_pixels_full_coverage_overlap_and_order(self):
        data=self.original(); before=hashlib.sha256(data).hexdigest(); report={}
        batch,mime,exif=prepare_ocr(data,{'max_edge':64},1000000,p.prepare_image,report)
        self.assertEqual(len(batch.images),5)
        coverage=Image.new('L',(120,90)); expected=Image.open(io.BytesIO(data))
        from PIL import ImageDraw
        draw=ImageDraw.Draw(coverage)
        for (wire,_,label),detail in zip(batch.images[1:],report['tiles']):
            self.assertIn('Originalausschnitt',label)
            box=detail['box']; tile=Image.open(io.BytesIO(wire))
            self.assertEqual(tile.tobytes(),expected.crop(box).tobytes())
            self.assertLessEqual(max(tile.size),64)
            self.assertFalse(detail['resized'])
            draw.rectangle((box[0],box[1],box[2]-1,box[3]-1),fill=255)
        self.assertEqual(coverage.getextrema(),(255,255))
        a,b=report['tiles'][:2];self.assertGreater(a['box'][2]-b['box'][0],0)
        self.assertEqual(hashlib.sha256(data).hexdigest(),before)

    def test_exif_rotation_applies_to_overview_and_tiles(self):
        image=Image.new('RGB',(120,90),'red'); exif=Image.Exif();exif[274]=6
        out=io.BytesIO();image.save(out,'PNG',exif=exif);report={}
        batch,_,_=prepare_ocr(out.getvalue(),{'max_edge':64},1000000,p.prepare_image,report)
        self.assertEqual((report['oriented_width'],report['oriented_height']),(90,120))
        self.assertEqual(max(x['box'][2] for x in report['tiles']),90)
        self.assertEqual(max(x['box'][3] for x in report['tiles']),120)

    def test_small_image_is_single_unscaled_input(self):
        report={};batch,_,_=prepare_ocr(self.original((32,24)),{'max_edge':64},1000000,p.prepare_image,report)
        self.assertEqual(len(batch.images),1);self.assertEqual(report['tiles'],[])
        self.assertFalse(report['resized'])

    def test_no_silent_truncation_when_context_image_limit_exceeded(self):
        with self.assertRaisesRegex(p.PipelineError,'No regions were omitted'):
            prepare_ocr(self.original(),{'max_edge':64,'max_batch_images':4},1000000,p.prepare_image,{})

    def test_all_inputs_sent_byte_identically_in_one_http_request(self):
        calls=[]; result={'text':'ALPHA\nBRAVO\nCHARLIE\nDELTA'}
        class Handler(BaseHTTPRequestHandler):
            def log_message(self,*args):pass
            def do_POST(self):
                calls.append(json.loads(self.rfile.read(int(self.headers['Content-Length']))))
                body=json.dumps({'choices':[{'message':{'content':json.dumps(result)},'finish_reason':'stop'}]}).encode()
                self.send_response(200);self.send_header('Content-Length',str(len(body)));self.end_headers();self.wfile.write(body)
        server=ThreadingHTTPServer(('127.0.0.1',0),Handler);thread=threading.Thread(target=server.serve_forever);thread.start()
        try:
            batch,_,_=prepare_ocr(self.original(),{'max_edge':64},1000000,p.prepare_image,{})
            model=p.Model({'model':'test','url':f'http://127.0.0.1:{server.server_port}/v1','stream':False},'ocr')
            self.assertEqual(model.analyze(batch,'image/png')['text'],result['text'])
            self.assertEqual(len(calls),1)
            content=calls[0]['messages'][0]['content'];sent=[x for x in content if x['type']=='image_url']
            self.assertEqual(len(sent),len(batch.images))
            self.assertIn('EINEM Original',content[0]['text'])
            for entry,(wire,mime,label) in zip(sent,batch.images):
                self.assertEqual(base64.b64decode(entry['image_url']['url'].split(',',1)[1]),wire)
                self.assertEqual(entry['image_url']['url'].split(';')[0],'data:'+mime)
            self.assertNotIn('max_tokens',calls[0])
        finally:server.shutdown();server.server_close();thread.join()

    def test_media_copy_enforces_size_and_hash(self):
        with tempfile.TemporaryDirectory() as tmp:
            target=Path(tmp)/'video'
            self.assertEqual(p.copy_media(io.BytesIO(b'abc'),target,3),hashlib.sha256(b'abc').hexdigest())
            with self.assertRaises(p.PipelineError):p.copy_media(io.BytesIO(b'abcd'),target,3)

    def test_video_fingerprint_does_not_change_image_stage_cache(self):
        vision=p.Model({'url':'http://localhost/v1','model':'test'},'vision')
        self.assertEqual(vision.revision,p.digest([p.VERSION,'vision',vision.config,vision.prompt]))
        ocr=p.Model({'url':'http://localhost/v1','model':'test'},'ocr')
        self.assertNotEqual(ocr.revision,p.digest([p.VERSION,'ocr',ocr.config,ocr.prompt]))


class EncoderAreaTests(unittest.TestCase):
    def test_area_limit_grid_full_frame_and_lossless_small_images(self):
        from media_ai_resolution import image_box
        for source in [(4000,3000),(3000,4000),(2048,2048),(8000,1000),(51,29)]:
            image=Image.new('RGB',source,(50,70,90));out=io.BytesIO();image.save(out,'PNG');report={}
            wire,mime,_=p.prepare_image(out.getvalue(),8192,80_000_000,'vision',report,4194304)
            sent=Image.open(io.BytesIO(wire));self.assertEqual(mime,'image/png')
            self.assertLessEqual(sent.width*sent.height,4194304)
            self.assertEqual(sent.width%32,0);self.assertEqual(sent.height%32,0)
            self.assertTrue(report['full_frame'])
            w,h=report['content_width'],report['content_height']
            self.assertAlmostEqual(w/h,source[0]/source[1],delta=(source[0]/source[1])/h)
            for pos in [(0,0),(w-1,0),(0,h-1),(w-1,h-1)]:self.assertEqual(sent.getpixel(pos),(50,70,90))
            if source==(4000,3000):
                self.assertGreater(report['sent_pixels'],2048*1536)
                self.assertEqual(sent.size,(2368,1760))
            if source==(51,29):
                self.assertFalse(report['resized']);self.assertEqual(sent.crop((0,0,51,29)).tobytes(),image.tobytes())

    def test_area_ocr_tiles_keep_every_original_pixel(self):
        from PIL import ImageDraw
        data=MultiImageTests().original((240,180));report={}
        batch,_,_=prepare_ocr(data,{'max_edge':512,'model_max_pixels':128*128},1000000,p.prepare_image,report)
        coverage=Image.new('L',(240,180));draw=ImageDraw.Draw(coverage);original=Image.open(io.BytesIO(data))
        for (wire,_,_),tile in zip(batch.images[1:],report['tiles']):
            sent=Image.open(io.BytesIO(wire));box=tile['box'];expected=original.crop(box)
            self.assertLessEqual(sent.width*sent.height,128*128)
            self.assertEqual(sent.crop((0,0,expected.width,expected.height)).tobytes(),expected.tobytes())
            draw.rectangle((box[0],box[1],box[2]-1,box[3]-1),fill=255)
        self.assertEqual(coverage.getextrema(),(255,255))

    @unittest.skipUnless(shutil.which('node'),'Node required')
    def test_sdk_budget_interrupts_blocked_pipe_with_graceful_cancel(self):
        from media_ai_lmstudio import SDKResponse
        from media_ai_budget import track_cancel
        import time
        process=subprocess.Popen(['node','-e',"process.on('SIGTERM',()=>process.exit(0));setInterval(()=>{},1000)"],stdout=subprocess.PIPE)
        time.sleep(.1)  # Let the test process install its signal handler.
        response=SDKResponse(process,30);start=time.monotonic()
        try:
            with self.assertRaises(ImageTimeout):
                with ImageBudget(.15),track_cancel(response.cancel):response.readline(100)
            self.assertLess(time.monotonic()-start,2)
            self.assertEqual(process.wait(timeout=2),0)
        finally:response.cancel();process.wait();process.stdout.close()


@unittest.skipUnless(shutil.which('ffmpeg') and shutil.which('ffprobe'),'FFmpeg required')
class VideoTests(unittest.TestCase):
    def setUp(self):
        self.tmp=tempfile.TemporaryDirectory();self.root=Path(self.tmp.name);self.video=self.root/'scene.mp4'
        subprocess.run(['ffmpeg','-nostdin','-v','error','-f','lavfi','-i','testsrc2=size=320x240:rate=10:duration=2',
                        '-c:v','libx264','-threads','1','-pix_fmt','yuv420p',str(self.video)],check=True)
    def tearDown(self):self.tmp.cleanup()

    def test_ten_evenly_spaced_frames_rotate_fit_and_no_original_change(self):
        before=self.video.read_bytes();report={}
        batch,_,_=prepare_video(self.video,{'frame_count':10,'max_edge':128},1000000,report)
        self.assertEqual(len(batch.images),10)
        self.assertEqual(report['duration_seconds'],2)
        self.assertEqual([x['sample_seconds'] for x in report['frames']],[round(.1+.2*i,6) for i in range(10)])
        self.assertTrue(all((x['width'],x['height'])==(128,96) for x in report['frames']))
        self.assertIn('EINEM Video',batch.instructions);self.assertIn('Keine OCR',batch.instructions)
        self.assertEqual(self.video.read_bytes(),before)

    def test_pipeline_publishes_searchable_video_once_without_ocr(self):
        source=p.DirectorySource({'directory':str(self.root)});store=p.Store(self.root/'state/db.sqlite3')
        class Recording(Model):
            def analyze(self,data,mime):
                self.calls+=1;self.batch=data
                return dict(VISION,type='video')
        vision=Recording('vision');ocr=Model('ocr');publisher=Publisher();config={'video':{'frame_count':10}}
        try:
            result=p.run(config,store,source,{'vision':vision,'ocr':ocr},publisher=publisher)
            self.assertEqual(result['processed'],1);self.assertEqual(result['errors'],0)
            self.assertEqual(vision.calls,1);self.assertEqual(ocr.calls,0)
            self.assertEqual(len(vision.batch.images),10)
            row=store.db.execute('SELECT metadata FROM files').fetchone();metadata=json.loads(row[0])
            self.assertEqual(metadata['ocr'],{'text':'','source':'skipped_video'})
            self.assertEqual(metadata['vision']['type'],'video');self.assertEqual(len(publisher.sent),1)
            self.assertEqual(len(store.search('Fahrrad')),1)
            again=p.run(config,store,source,{'vision':vision,'ocr':ocr},publisher=publisher)
            self.assertEqual(again['processed'],0);self.assertEqual(vision.calls,1)
            config['video']['frame_count']=5
            changed=p.run(config,store,source,{'vision':vision,'ocr':ocr},publisher=publisher)
            self.assertEqual(changed['processed'],1);self.assertEqual(vision.calls,2)
            self.assertEqual(len(vision.batch.images),5)
        finally:store.db.close()

    def test_bad_video_and_shared_decoder_deadline(self):
        bad=self.root/'broken.mp4';bad.write_bytes(b'broken')
        with self.assertRaises(p.PipelineError):prepare_video(bad,{},1000000,{})
        with self.assertRaises(ImageTimeout):
            with ImageBudget(.0001):prepare_video(self.video,{},1000000,{})

    def test_rotated_video_keeps_display_orientation(self):
        rotated=self.root/'rotated.mp4'
        help_text=subprocess.run(['ffmpeg','-h','full'],stdout=subprocess.PIPE,stderr=subprocess.DEVNULL,text=True).stdout
        rotation=['-display_rotation','90'] if '-display_rotation' in help_text else []
        subprocess.run(['ffmpeg','-v','error',*rotation,'-i',str(self.video),'-c','copy','-metadata:s:v:0','rotate=90',str(rotated)],check=True)
        report={};prepare_video(rotated,{'frame_count':1,'max_edge':128},1000000,report)
        self.assertEqual((report['frames'][0]['width'],report['frames'][0]['height']),(96,128))
