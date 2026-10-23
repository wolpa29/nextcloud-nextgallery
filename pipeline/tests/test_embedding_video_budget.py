"""Video preparation gets time to finish without changing sampling or image limits."""
from pathlib import Path
import tempfile
import unittest
from unittest.mock import patch
from test_embedding import API, FakeClient, config
import media_pipeline as p
import media_ai_embedding as e


class Video:
    def inventory(self):return [p.Item('synthetic:1','video.mp4','1','v1',1,'video/mp4')]
    def download_to(self,item,path,maximum):path.write_bytes(b'x')
    def unchanged(self,item):return True


class VideoBudgetTests(unittest.TestCase):
    def test_video_preparation_uses_its_own_budget_and_keeps_frame_mapping(self):
        c=config();c['image_timeout_seconds']=600;c['embedding']['video_timeout_seconds']=3600
        limits=[];original=p.ImageBudget
        def budget(seconds):limits.append(seconds);return original(seconds)
        def prepare(path,setting,max_pixels,report):
            self.assertEqual(setting['decoder_timeout_seconds'],3600)
            self.assertEqual(setting['frame_count'],c['embedding']['video_frames'])
            self.assertGreater(p.network_timeout(7200),3500)
            report['frames']=[{'sample_seconds':0.0},{'sample_seconds':12.5}]
            return p.ImageBatch([(b'one','image/png','one'),(b'two','image/png','two')],'synthetic'),'image/png',{}
        api=API()
        with tempfile.TemporaryDirectory() as tmp,patch.object(e,'Client',FakeClient),patch.object(p,'ImageBudget',budget),patch.object(p,'prepare_video',prepare):
            result=e.run(c,Video(),api,Path(tmp),lambda *a,**k:None,lambda:False)
        self.assertEqual(result['processed'],1);self.assertEqual(limits,[3600,600])
        self.assertEqual([v['seconds'] for v in api.published[0]['vectors']],[0.0,12.5])
        self.assertEqual(c['image_timeout_seconds'],600)

    def test_higher_video_budget_does_not_change_vector_space(self):
        c=config()['embedding'];info={'space':'a'*64}
        before=e.space_id(info,c);c['video_timeout_seconds']=7200
        self.assertEqual(e.space_id(info,c),before)

    def test_video_preparation_timeout_names_the_video_phase(self):
        c=config();c['embedding']['video_timeout_seconds']=3600
        with tempfile.TemporaryDirectory() as tmp,patch.object(e,'Client',FakeClient),patch.object(p,'prepare_video',side_effect=p.ImageTimeout('old image wording')):
            d=Path(tmp);api=API();result=e.run(c,Video(),api,d,lambda *a,**k:None,lambda:False)
            store=e.Store(d/'embeddings.sqlite3')
            error=store.db.execute('SELECT error FROM embeddings').fetchone()[0];store.db.close()
        self.assertEqual(result['errors'],1);self.assertIn('Video-Aufbereitung',error);self.assertIn('3600 s',error);self.assertEqual(api.published,[])


if __name__=='__main__':unittest.main()
