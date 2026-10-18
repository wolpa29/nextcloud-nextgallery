"""Synthetic batching, lossless preparation and resumability checks."""
import base64
import hashlib
import io
import json
from pathlib import Path
import tempfile
import unittest
from unittest.mock import patch
from PIL import Image
from pipeline.tests.test_embedding import config, API
import media_pipeline as p
import media_ai_embedding as e
import app_worker as w


def png(index):
    out=io.BytesIO();Image.new('RGB',(32,24),(index,20,100)).save(out,'PNG')
    return out.getvalue()


def values(index):
    return [float(i==index) for i in range(128)]


class Source:
    def __init__(self,count):
        self.items=[p.Item(str(i),f'{i}.png',str(i),'v1',len(png(i)),'image/png') for i in range(count)]
        self.changed=set()
    def inventory(self):return self.items
    def read(self,item,maximum):return png(int(item.locator))
    def unchanged(self,item):return item.key not in self.changed
    def download_to(self,item,path,maximum):path.write_bytes(b'synthetic video')


class BatchClient:
    calls=[]
    def __init__(self,setting):
        self.setting=setting;self.info={'space':'a'*64,'dimensions':128}
        self.space=e.space_id(self.info,setting)
    def health(self):return self.info
    def batch_size(self):return self.setting.get('batch_size',1)
    def images(self,wires,should_stop=lambda:False):
        BatchClient.calls.append(len(wires))
        return [values(Image.open(io.BytesIO(wire)).getpixel((0,0))[0]) for wire in wires]
    def image(self,wire):return self.images([wire])[0]


class BatchWorkerTests(unittest.TestCase):
    def setUp(self):BatchClient.calls=[]
    def run_worker(self,directory,source,api,stop=lambda:False):
        setting=config();setting['embedding']['batch_size']=10
        with patch.object(e,'Client',BatchClient):
            return e.run(setting,source,api,directory,lambda *a,**k:None,stop)

    def test_ten_photos_and_tail_keep_individual_file_vectors(self):
        with tempfile.TemporaryDirectory() as tmp:
            api=API();result=self.run_worker(Path(tmp),Source(23),api)
            self.assertEqual(result['processed'],23)
            self.assertEqual(BatchClient.calls,[10,10,3])
            for row in api.published:
                self.assertEqual(row['vectors'],[{'frame':0,'seconds':0,'values':values(row['file_id'])}])

    def test_publication_outage_persists_whole_batch_and_resumes_without_reembedding(self):
        with tempfile.TemporaryDirectory() as tmp:
            directory=Path(tmp);source=Source(10);api=API();api.fail=True
            result=self.run_worker(directory,source,api)
            self.assertEqual(result['state'],'deferred')
            store=e.Store(directory/'embeddings.sqlite3')
            self.assertEqual(store.db.execute('SELECT COUNT(*) FROM embeddings WHERE vectors IS NOT NULL').fetchone()[0],10)
            store.db.close();api.fail=False
            result=self.run_worker(directory,source,api)
            self.assertEqual(result['processed'],10);self.assertEqual(BatchClient.calls,[10])

    def test_batch_size_change_does_not_reembed_completed_photos(self):
        with tempfile.TemporaryDirectory() as tmp,patch.object(e,'Client',BatchClient):
            setting=config();setting['embedding']['batch_size']=4
            source=Source(11);api=API();directory=Path(tmp)
            e.run(setting,source,api,directory,lambda *a,**k:None,lambda:False)
            setting['embedding']['batch_size']=10
            result=e.run(setting,source,api,directory,lambda *a,**k:None,lambda:False)
            self.assertEqual(result['processed'],0);self.assertEqual(BatchClient.calls,[4,4,3])

    def test_pause_after_first_publication_retains_rest_of_computed_batch(self):
        with tempfile.TemporaryDirectory() as tmp:
            source=Source(10);api=API();directory=Path(tmp)
            result=self.run_worker(directory,source,api,lambda:len(api.published)==1)
            self.assertEqual(result['state'],'paused');self.assertEqual(result['stats']['pending'],9)
            self.assertEqual(result['processed'],1)
            self.run_worker(directory,source,api)
            self.assertEqual(len(api.published),10);self.assertEqual(BatchClient.calls,[10])

    def test_real_worker_client_and_http_server_batch_end_to_end(self):
        from http.server import HTTPServer
        import threading
        from embedding_server import handler
        class Engine:
            info={'model':'embeddinggemma-2','family':'gemma2','space':'a'*64,'dimensions':128,
                  'modalities':['text','image'],'image_batch':True,'max_batch_size':64}
            calls=[]
            def embed(self,text=None,image=None):
                self.calls.append(1)
                return values(Image.open(io.BytesIO(image)).getpixel((0,0))[0])
            def embed_images(self,images):
                self.calls.append(len(images))
                return [values(Image.open(io.BytesIO(wire)).getpixel((0,0))[0]) for wire in images]
        engine=Engine();server=HTTPServer(('127.0.0.1',0),handler(engine,'synthetic-secret'))
        thread=threading.Thread(target=server.serve_forever,daemon=True);thread.start()
        try:
            with tempfile.TemporaryDirectory() as tmp:
                setting=config();setting['embedding'].update(batch_size=10,url=f'http://127.0.0.1:{server.server_port}/v1',api_key='synthetic-secret')
                api=API();result=e.run(setting,Source(23),api,Path(tmp),lambda *a,**k:None,lambda:False)
                self.assertEqual(result['processed'],23);self.assertEqual(engine.calls,[10,10,3])
                for row in api.published:self.assertEqual(row['vectors'][0]['values'],values(row['file_id']))
        finally:server.shutdown();server.server_close();thread.join()

    def test_changed_file_isolated_from_healthy_batch_members(self):
        source=Source(10);original=BatchClient.images
        def change(client,wires,should_stop=lambda:False):
            result=original(client,wires,should_stop);source.changed.add('2');return result
        with tempfile.TemporaryDirectory() as tmp,patch.object(BatchClient,'images',change):
            api=API();result=self.run_worker(Path(tmp),source,api)
            self.assertEqual(result['processed'],9);self.assertEqual(result['errors'],1)
            self.assertNotIn(2,[row['file_id'] for row in api.published])

    def test_video_frames_are_batched_and_keep_frame_timestamps(self):
        source=Source(1);source.items=[p.Item('v','sample.mp4','9','v1',1,'video/mp4')]
        frames=p.ImageBatch([(png(i),'image/png','synthetic') for i in range(10)],'synthetic frames')
        def prepare(path,setting,max_pixels,report):
            report['frames']=[{'sample_seconds':i*2.5} for i in range(10)]
            return frames,'image/png',{}
        with tempfile.TemporaryDirectory() as tmp,patch.object(p,'prepare_video',prepare):
            api=API();result=self.run_worker(Path(tmp),source,api)
            self.assertEqual(result['processed'],1);self.assertEqual(BatchClient.calls,[10])
            self.assertEqual([v['seconds'] for v in api.published[0]['vectors']],[i*2.5 for i in range(10)])
            self.assertEqual([v['values'] for v in api.published[0]['vectors']],[values(i) for i in range(10)])

    def test_photos_moved_outside_nextcloud_reuse_vectors_by_content(self):
        # A move on the NAS reaches Nextcloud as delete + create: new file ID, same bytes.
        class Moved(Source):
            def __init__(self,count):
                super().__init__(0)
                self.items=[p.Item(str(100+i),f'sorted/{i}.png',str(100+i),'v2',len(png(i)),'image/png') for i in range(count)]
            def read(self,item,maximum):return png(int(item.locator)-100)
        with tempfile.TemporaryDirectory() as tmp:
            directory=Path(tmp);self.run_worker(directory,Source(5),API())
            self.assertEqual(BatchClient.calls,[5])
            api=API();source=Moved(6);result=self.run_worker(directory,source,api)
            self.assertEqual(result['processed'],6);self.assertEqual(BatchClient.calls,[5,1])
            self.assertEqual({row['file_id']:row['vectors'][0]['values'] for row in api.published},
                             {100+i:values(i) for i in range(6)})
            store=e.Store(directory/'embeddings.sqlite3');store.reset();store.db.close()
            self.run_worker(directory,Moved(6),API())
            self.assertEqual(BatchClient.calls,[5,1,6])

    def test_video_moved_outside_nextcloud_skips_frame_extraction(self):
        frames=p.ImageBatch([(png(i),'image/png','synthetic') for i in range(3)],'synthetic frames');extracted=[]
        def prepare(path,setting,max_pixels,report):
            extracted.append(path);report['frames']=[{'sample_seconds':i*2.0} for i in range(3)]
            return frames,'image/png',{}
        first=Source(0);first.items=[p.Item('9','clip.mp4','9','v1',1,'video/mp4')]
        moved=Source(0);moved.items=[p.Item('19','sorted/clip.mp4','19','v2',1,'video/mp4')]
        with tempfile.TemporaryDirectory() as tmp,patch.object(p,'prepare_video',prepare):
            self.run_worker(Path(tmp),first,API())
            api=API();result=self.run_worker(Path(tmp),moved,api)
            self.assertEqual(result['processed'],1);self.assertEqual(len(extracted),1);self.assertEqual(BatchClient.calls,[3])
            self.assertEqual(api.published[0]['file_id'],19)
            self.assertEqual([v['seconds'] for v in api.published[0]['vectors']],[0.0,2.0,4.0])

    def test_embedding_png_preserves_all_pixels_of_scaled_image(self):
        original=Image.new('RGB',(40,30));original.putdata([(i%256,(i*17)%256,(i*37)%256) for i in range(1200)])
        out=io.BytesIO();original.save(out,'PNG')
        wire,mime,_=p.prepare_image(out.getvalue(),20,10000,'embedding')
        expected=original.copy();expected.thumbnail((20,20),Image.Resampling.LANCZOS)
        with Image.open(io.BytesIO(wire)) as image:
            self.assertEqual(mime,'image/png');self.assertEqual(image.format,'PNG')
            self.assertEqual(image.size,(20,15));self.assertEqual(image.tobytes(),expected.tobytes())

    def test_lossless_migration_requires_confirmation_without_deleting_legacy_vectors(self):
        with tempfile.TemporaryDirectory() as tmp,patch.object(e,'Client',BatchClient):
            directory=Path(tmp);setting=config();store=e.Store(directory/'embeddings.sqlite3')
            old_space=hashlib.sha256(('a'*64+'|image-v1|edge=512|frames=3').encode()).hexdigest()
            saved=json.dumps([{'frame':0,'seconds':0,'values':values(0)}])
            with store.db:store.db.execute('INSERT INTO embeddings(id,space,etag,path,status,active,vectors) VALUES (?,?,?,?,?,?,?)',('1',old_space,'v1','synthetic.png','done',1,saved))
            self.assertTrue(w.detect_embedding_rebuild(setting,directory,None))
            marker=w.read(directory/'embedding-rebuild.json',{})
            self.assertTrue(marker['required']);self.assertFalse(marker['approved'])
            self.assertNotEqual(marker['target_space'],old_space)
            self.assertEqual(store.db.execute('SELECT space,vectors FROM embeddings').fetchone()['vectors'],saved)
            self.assertEqual(store.db.execute('SELECT COUNT(*) FROM embeddings WHERE space=?',(old_space,)).fetchone()[0],1)
            store.db.close()


class BatchClientTests(unittest.TestCase):
    def client(self,info=None,size=10):
        setting=config()['embedding'];setting['batch_size']=size
        client=e.Client(setting)
        client.info={'space':'a'*64,'dimensions':128,'image_batch':True,'max_batch_size':64}|(info or {})
        return client

    def test_batch_request_and_tail_match_input_order(self):
        client=self.client(size=4);calls=[]
        def call(endpoint,body,**kwargs):
            calls.append(body)
            if 'image' in body:
                index=Image.open(io.BytesIO(base64.b64decode(body['image']))).getpixel((0,0))[0]
                return {'space':'a'*64,'embedding':values(index)}
            indices=[Image.open(io.BytesIO(base64.b64decode(wire))).getpixel((0,0))[0] for wire in body['images']]
            return {'space':'a'*64,'embeddings':[values(i) for i in indices]}
        with patch.object(client,'call',side_effect=call):
            self.assertEqual(client.images([png(i) for i in range(9)]),[values(i) for i in range(9)])
        self.assertEqual([len(body.get('images',[body.get('image')])) for body in calls],[4,4,1])

    def test_old_or_local_service_uses_single_image_requests(self):
        for info in ({'image_batch':False},{'image_batch':None},{'max_batch_size':True}):
            client=self.client(info)
            with patch.object(client,'call',return_value={'space':'a'*64,'embedding':values(0)}) as call:
                client.images([png(0)]*3)
                self.assertEqual(call.call_count,3)
                self.assertTrue(all('image' in c.args[1] for c in call.call_args_list))
        client=self.client();client.config['location']='local';self.assertEqual(client.batch_size(),1)

    def test_invalid_batch_results_are_rejected(self):
        client=self.client()
        for response in ({'space':'b'*64,'embeddings':[values(0),values(1)]},
                         {'space':'a'*64,'embeddings':[values(0)]},
                         {'space':'a'*64,'embeddings':[[1.]*256,[1.]*256]}):
            with self.subTest(response=response),patch.object(client,'call',return_value=response):
                with self.assertRaises(p.PipelineError):client.images([png(0),png(1)])

    def test_request_byte_limit_splits_large_groups(self):
        client=self.client();wire=b'x'*(10*1024*1024)
        with patch.object(client,'_image',return_value=values(0)) as single:
            self.assertEqual(client.images([wire,wire]),[values(0),values(0)])
            self.assertEqual(single.call_count,2)



class RejectingClient(BatchClient):
    """Fails one photo with a server error; health tells input faults from outages."""
    reject=3;status=503;healthy=True
    def health(self):
        # The run's opening check passes; later probes follow the simulated service state.
        self.probes=getattr(self,'probes',0)+1
        if self.probes>1 and not RejectingClient.healthy:raise p.ServiceUnavailable('Connection unavailable')
        return self.info
    def images(self,wires,should_stop=lambda:False):
        if any(Image.open(io.BytesIO(wire)).getpixel((0,0))[0]==RejectingClient.reject for wire in wires):
            BatchClient.calls.append(len(wires))
            raise p.ServiceUnavailable(f'HTTP {RejectingClient.status} from model',RejectingClient.status)
        return super().images(wires,should_stop)


class ServiceRejectionTests(unittest.TestCase):
    def setUp(self):
        BatchClient.calls=[];RejectingClient.status=503;RejectingClient.healthy=True
    def run_worker(self,directory,source,api,batch_size=4):
        setting=config();setting['embedding']['batch_size']=batch_size
        with patch.object(e,'Client',RejectingClient):
            return e.run(setting,source,api,directory,lambda *a,**k:None,lambda:False)

    def test_server_error_on_one_photo_fails_only_that_photo_while_service_answers(self):
        for batch_size in (1,4):
            with self.subTest(batch_size=batch_size),tempfile.TemporaryDirectory() as tmp:
                api=API();result=self.run_worker(Path(tmp),Source(6),api,batch_size)
                self.assertEqual(result['state'],'finished')
                self.assertEqual((result['processed'],result['errors']),(5,1))
                self.assertNotIn(3,[row['file_id'] for row in api.published])
                store=e.Store(Path(tmp)/'embeddings.sqlite3')
                row=store.db.execute("SELECT status,error FROM embeddings WHERE id='3'").fetchone();store.db.close()
                self.assertEqual(row['status'],'error');self.assertIn('HTTP 503',row['error'])
                # The next run moves on instead of stopping at the same photo again.
                BatchClient.calls=[];result=self.run_worker(Path(tmp),Source(6),api,batch_size)
                self.assertEqual((result['state'],result['processed'],BatchClient.calls),('finished',0,[]))

    def test_outage_or_auth_failure_defers_without_charging_the_photo(self):
        for status,healthy in ((503,False),(None,True),(401,True)):
            with self.subTest(status=status,healthy=healthy),tempfile.TemporaryDirectory() as tmp:
                RejectingClient.status=status;RejectingClient.healthy=healthy
                result=self.run_worker(Path(tmp),Source(6),API(),1)
                self.assertEqual(result['state'],'deferred')
                self.assertEqual((result['errors'],result['stats']['error']),(0,0))

if __name__=='__main__':unittest.main()
