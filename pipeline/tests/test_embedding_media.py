"""Synthetic coverage of same-host original access and embedding-only limits."""
import contextlib
import io
import json
from pathlib import Path
import tempfile
import sys
import unittest
import time
import shutil
import subprocess
from unittest.mock import patch

sys.path.insert(0,str(Path(__file__).resolve().parents[2]/'worker'))
import app_worker as w
import media_pipeline as p
import media_ai_embedding as e
import media_ai_inputs as inputs
from test_embedding import API, FakeClient, Source as ImageSource, config


class SourceAPI:
    url='https://synthetic.invalid/native/'
    def __init__(self,item,path):
        self.target={'file_id':int(item.locator),'etag':item.etag,'size':item.size,'local_path':str(path) if path else None}
        self.calls=[]
    def call(self,endpoint):
        self.calls.append(endpoint)
        return dict(self.target)


class NativeMediaTests(unittest.TestCase):
    def source(self,item,path):
        api=SourceAPI(item,path)
        return w.NativeSource(api,config()|{'_pipeline':'embeddings'}),api

    def test_large_local_video_never_downloaded_and_original_preserved(self):
        with tempfile.TemporaryDirectory() as temp:
            root=Path(temp);original=root/'original.mp4'
            with original.open('wb') as stream:stream.truncate(536870913)
            item=p.Item('synthetic:9','video.mp4','9','v1',original.stat().st_size,'video/mp4')
            source,api=self.source(item,original)
            before=original.stat()
            with patch.object(source,'download_to',side_effect=AssertionError('Local video was downloaded')):
                with source.embedding_video(item,root/'copy.mp4',536870912) as selected:
                    self.assertEqual(selected,original)
            self.assertEqual(original.stat().st_size,before.st_size)
            self.assertEqual(original.stat().st_mtime_ns,before.st_mtime_ns)
            self.assertFalse((root/'copy.mp4').exists())
            self.assertIn('etag=v1',api.calls[0]);self.assertIn('local_media=true',api.calls[0])

    def test_remote_video_still_obeys_download_limit(self):
        item=p.Item('synthetic:9','video.mp4','9','v1',536870913,'video/mp4')
        source,_=self.source(item,None)
        with patch.object(source,'download_to',side_effect=AssertionError('Oversized download')):
            with self.assertRaisesRegex(p.PipelineError,'worker on the Nextcloud server'):
                with source.embedding_video(item,Path('/unused'),536870912):pass

    def test_remote_small_video_uses_existing_download_transport(self):
        item=p.Item('synthetic:9','video.mp4','9','v1',20,'video/mp4')
        source,_=self.source(item,None);destination=Path('/synthetic/copy.mp4')
        with patch.object(source,'download_to') as download:
            with source.embedding_video(item,destination,100) as selected:self.assertEqual(selected,destination)
        download.assert_called_once_with(item,destination,100)

    def test_local_media_changed_before_or_during_extraction_not_published(self):
        with tempfile.TemporaryDirectory() as temp:
            original=Path(temp)/'original.mp4';original.write_bytes(b'synthetic')
            item=p.Item('synthetic:9','video.mp4','9','v1',original.stat().st_size,'video/mp4')
            source,api=self.source(item,original)
            api.target['etag']='v2'
            with self.assertRaisesRegex(p.PipelineError,'before frame'):
                with source.embedding_video(item,Path(temp)/'copy',100):pass
            api.target['etag']='v1'
            with self.assertRaisesRegex(p.PipelineError,'during frame'):
                with source.embedding_video(item,Path(temp)/'copy',100):original.write_bytes(b'changed')

    def test_full_worker_uses_large_local_video_and_keeps_frame_mapping(self):
        from PIL import Image
        with tempfile.TemporaryDirectory() as temp:
            root=Path(temp);original=root/'original.mp4';original.write_bytes(b'synthetic')
            item=p.Item('synthetic:9','video.mp4','9','v1',9,'video/mp4')
            source,_=self.source(item,original)
            source.inventory=lambda:[item]
            source.embedding_inventory=lambda space:([item],set())
            source.unchanged=lambda item:True
            source.download_to=lambda *args: (_ for _ in ()).throw(AssertionError('downloaded'))
            frames=p.ImageBatch([(b'frame-one','image/png','one'),(b'frame-two','image/png','two')],'synthetic')
            def prepare(path,setting,max_pixels,report):
                self.assertEqual(path,original)
                self.assertEqual(setting['decoder_timeout_seconds'],3600)
                report['frames']=[{'sample_seconds':0.0},{'sample_seconds':9.5}]
                return frames,'image/png',{}
            setting=config();setting['max_video_download_bytes']=1
            api=API()
            with patch.object(e,'Client',FakeClient),patch.object(p,'prepare_video',prepare):
                result=e.run(setting,source,api,root,lambda *a,**k:None,lambda:False)
            self.assertEqual(result['processed'],1);self.assertEqual(result['errors'],0)
            self.assertEqual([v['seconds'] for v in api.published[0]['vectors']],[0.0,9.5])
            self.assertEqual([v['frame'] for v in api.published[0]['vectors']],[0,1])
            self.assertEqual(original.read_bytes(),b'synthetic')

    def test_native_image_short_download_is_rejected_before_decoder(self):
        item=p.Item('synthetic:1','image.jpg','1','v1',100,'image/jpeg')
        source,api=self.source(item,None)
        api.wire=lambda *a,**k:(b'incomplete',{'ETag':'"v1"'})
        with self.assertRaisesRegex(p.PipelineError,'Incomplete source image'):
            source.read(item,100)

    def test_video_timeout_override_changes_only_timeout_not_sampling(self):
        for invalid in (0,-1,True,float('inf'),'600'):
            with self.subTest(invalid=invalid),self.assertRaisesRegex(p.PipelineError,'time limit'):
                inputs.prepare_video(Path('/unused'),{'decoder_timeout_seconds':invalid},1000,{})

    def test_one_video_budget_timeout_does_not_abort_following_files(self):
        first=p.Item('synthetic:1','one.mp4','1','v1',1,'video/mp4')
        second=p.Item('synthetic:2','two.mp4','2','v1',1,'video/mp4')
        class Source:
            def inventory(self):return [first,second]
            def unchanged(self,item):return True
            def download_to(self,item,path,maximum):path.write_bytes(b'x')
        def prepare(path,setting,max_pixels,report):
            if prepare.calls==0:
                prepare.calls+=1
                raise p.ImageTimeout('Synthetic image time budget expired')
            report['frames']=[{'sample_seconds':0}]
            return p.ImageBatch([(b'frame','image/png','synthetic')],'frame'),'image/png',{}
        prepare.calls=0
        with tempfile.TemporaryDirectory() as temp,patch.object(e,'Client',FakeClient),patch.object(p,'prepare_video',prepare):
            api=API();result=e.run(config(),Source(),api,Path(temp),lambda *a,**k:None,lambda:False)
        self.assertEqual(result['state'],'finished');self.assertEqual(result['errors'],1)
        self.assertEqual([row['file_id'] for row in api.published],[2])

    def test_retry_errors_precede_ordinary_pending_without_reembedding_successes(self):
        setting=config();source=ImageSource()
        items=[p.Item('synthetic:'+str(i),'image.png',str(i),'v1',len(source.wire),'image/png') for i in (1,2,3)]
        source.inventory=lambda:items
        source.read=lambda item,maximum:source.wire+item.locator.encode()  # distinct content per file
        with tempfile.TemporaryDirectory() as temp,patch.object(e,'Client',FakeClient):
            root=Path(temp);space=FakeClient(setting['embedding']).space;store=e.Store(root/'embeddings.sqlite3')
            saved=json.dumps([{'frame':0,'seconds':0,'values':[1.]+[0.]*127}])
            with store.db:
                for item,status in zip(items,('pending','error','done')):
                    store.db.execute('INSERT INTO embeddings(id,space,etag,path,status,vectors,error) VALUES (?,?,?,?,?,?,?)',
                        (item.key,space,item.etag,item.path,status,saved if status=='done' else None,'old decoder error' if status=='error' else None))
            store.db.close();FakeClient.calls=0;api=API()
            result=e.run(setting,source,api,root,lambda *a,**k:None,lambda:False,retry_errors=True)
            self.assertEqual([row['file_id'] for row in api.published],[2,1])
            self.assertEqual(result['processed'],2);self.assertEqual(FakeClient.calls,2)
            store=e.Store(root/'embeddings.sqlite3')
            self.assertEqual(store.db.execute('SELECT vectors FROM embeddings WHERE id=? AND space=?',('synthetic:3',space)).fetchone()[0],saved)
            store.db.close()

    def test_paused_retries_keep_priority_and_images_precede_smaller_videos(self):
        setting=config();source=ImageSource()
        items=[p.Item('synthetic:'+str(i),name,str(i),'v1',size,mime) for i,name,size,mime in
               [(1,'ordinary.png',1,'image/png'),(2,'large.mp4',1000,'video/mp4'),
                (3,'image.png',len(source.wire),'image/png'),(4,'small.mp4',100,'video/mp4')]]
        source.inventory=lambda:items
        source.download_to=lambda item,path,maximum:path.write_bytes(b'synthetic')
        def prepare(path,setting,max_pixels,report):
            report['frames']=[{'sample_seconds':0}]
            return p.ImageBatch([(b'frame','image/png','synthetic')],'frame'),'image/png',{}
        with tempfile.TemporaryDirectory() as temp,patch.object(e,'Client',FakeClient),patch.object(p,'prepare_video',prepare):
            root=Path(temp);space=FakeClient(setting['embedding']).space;store=e.Store(root/'embeddings.sqlite3')
            with store.db:
                for item in items:
                    store.db.execute('INSERT INTO embeddings(id,space,etag,path,status,error) VALUES (?,?,?,?,?,?)',
                        (item.key,space,item.etag,item.path,'pending','previous failure' if item.locator!='1' else None))
            store.db.close();api=API()
            result=e.run(setting,source,api,root,lambda *a,**k:None,lambda:False)
            self.assertEqual([row['file_id'] for row in api.published],[3,4,2,1])
            self.assertEqual(result['errors'],0)

    def test_video_decoder_cancellation_reaps_process_promptly(self):
        from inference_gate import InferenceCancelled
        calls=0
        def stopping():
            nonlocal calls
            calls+=1
            return calls>=3
        started=time.monotonic()
        with self.assertRaises(InferenceCancelled):
            inputs.command([sys.executable,'-c','import time;time.sleep(10)'],timeout=20,should_stop=stopping)
        self.assertLess(time.monotonic()-started,3)


if __name__=='__main__':unittest.main()


@unittest.skipUnless(shutil.which('ffmpeg') and shutil.which('ffprobe'),'FFmpeg required')
class DecoderThreadTests(unittest.TestCase):
    def test_parallel_decoder_preserves_complete_timeline_and_png_bytes(self):
        with tempfile.TemporaryDirectory() as temp:
            for variable in (False,True):
                with self.subTest(variable_framerate=variable):
                    path=Path(temp)/('variable.mp4' if variable else 'constant.mp4')
                    filters=['-vf',"select='not(eq(mod(n,3),1))'",'-fps_mode','vfr'] if variable else []
                    subprocess.run(['ffmpeg','-nostdin','-v','error','-f','lavfi','-i',
                        'testsrc2=size=320x240:rate=30:duration=2',*filters,'-c:v','libx264','-bf','3',
                        '-threads','1','-pix_fmt','yuv420p',str(path)],check=True)
                    reports=[];images=[]
                    for threads in (1,4):
                        report={};batch,_,_=inputs.prepare_video(path,{'frame_count':10,'max_edge':128,'decoder_threads':threads},1000000,report)
                        reports.append(report);images.append(batch.images)
                    self.assertEqual(reports[0],reports[1])
                    self.assertEqual(images[0],images[1])
                    self.assertEqual(reports[0]['frames'][-1]['source_frame_index'],39 if variable else 59)

    def test_invalid_thread_counts_rejected_before_starting_decoder(self):
        for value in (0,5,True,'4'):
            with self.subTest(value=value),self.assertRaisesRegex(p.PipelineError,'thread limit'):
                inputs.prepare_video(Path('/unused'),{'decoder_threads':value},1000000,{})

    def test_oversized_frames_use_single_thread_for_bounded_buffers(self):
        commands=[]
        def command(args,**kwargs):
            commands.append(args)
            return json.dumps({'streams':[{'width':4000,'height':3000}]}).encode()
        with patch.object(inputs,'command',command),patch.object(inputs,'video_timeline',side_effect=p.PipelineError('stop')) as timeline:
            with self.assertRaisesRegex(p.PipelineError,'stop'):
                inputs.prepare_video(Path('/unused'),{'decoder_threads':4},80000000,{})
            self.assertEqual(timeline.call_args.args[-1],1)
