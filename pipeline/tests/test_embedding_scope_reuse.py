"""Published vectors survive selection changes without touching private media."""
import json
from pathlib import Path
import tempfile
import unittest
from unittest.mock import patch
from urllib.parse import parse_qs, urlsplit
from test_embedding import API, FakeClient, config
import app_worker as w
import media_ai_embedding as e


class PublishedAPI(API):
    def __init__(self, rows, space, acknowledged=True):
        super().__init__();self.rows=rows;self.space=space;self.acknowledged=acknowledged
    def call(self, endpoint, body=None):
        if endpoint.startswith('inventory?'):
            query=parse_qs(urlsplit(endpoint).query)
            page={'items':self.rows,'cursor':None}
            if self.acknowledged:page['embedding_space']=self.space
            return page
        return super().call(endpoint,body)


class ScopeReuse(unittest.TestCase):
    def setUp(self):FakeClient.calls=0
    def row(self, identifier=1, etag='v1', published='v1'):
        return {'file_id':identifier,'path':f'Synthetic/{identifier}.mp4','etag':etag,
                'embedding_etag':published,'size':100,'mime':'video/mp4','analyzed_etag':etag}
    def setting(self, folders=None):
        c=config();c['_pipeline']='embeddings';c['folders']=folders or ['/Synthetic'];return c
    def source(self, api, setting):
        source=w.NativeSource(api,setting)
        source.read=lambda *a: self.fail('Published media read')
        source.download_to=lambda *a: self.fail('Published video downloaded')
        source.unchanged=lambda *a: self.fail('Published file unnecessarily resolved')
        return source

    def test_expansion_reorder_and_exclusion_reuse_published_images_and_videos(self):
        with tempfile.TemporaryDirectory() as tmp,patch.object(e,'Client',FakeClient):
            for folders,excluded in [(['/Synthetic'],[]),(['/Synthetic','/More'],[]),
                                     (['/More','/Synthetic'],[]),(['/More','/Synthetic'],['/More/Skip'])]:
                c=self.setting(folders);c['exclude_folders']=excluded;space=FakeClient(c['embedding']).space
                image=self.row(2);image.update(mime='image/png',path='Synthetic/2.png')
                api=PublishedAPI([self.row(),image],space)
                result=e.run(c,self.source(api,c),api,Path(tmp),lambda *a,**k:None,lambda:False)
                self.assertEqual(result['stats'],{'done':2,'pending':0,'error':0})
                self.assertEqual(result['processed'],0);self.assertEqual(api.published,[])
            self.assertEqual(FakeClient.calls,0)

    def test_old_server_wrong_space_changed_etag_and_unpublished_files_are_not_completed(self):
        c=self.setting();space=FakeClient(c['embedding']).space
        for rows,echo,ack in [([self.row()],space,False),([self.row()],'b'*64,True),
                              ([self.row(etag='v2')],space,True),([self.row(published=None)],space,True)]:
            api=PublishedAPI(rows,echo,ack)
            items,completed=w.NativeSource(api,c).embedding_inventory(space)
            self.assertEqual(len(items),1);self.assertEqual(completed,set())

    def test_canonical_completion_repairs_pending_and_error_without_changing_other_spaces(self):
        c=self.setting();space=FakeClient(c['embedding']).space;api=PublishedAPI([self.row()],space)
        source=self.source(api,c);key=source.inventory()[0].key
        with tempfile.TemporaryDirectory() as tmp,patch.object(e,'Client',FakeClient):
            d=Path(tmp);store=e.Store(d/'embeddings.sqlite3')
            with store.db:
                store.db.execute('INSERT INTO embeddings(id,space,etag,path,status,error) VALUES (?,?,?,?,?,?)',
                                 (key,space,'v1','Synthetic/1.mp4','error','old timeout'))
                store.db.execute('INSERT INTO embeddings(id,space,etag,path,status) VALUES (?,?,?,?,?)',
                                 (key,'b'*64,'v1','Synthetic/1.mp4','done'))
            store.db.close()
            result=e.run(c,source,api,d,lambda *a,**k:None,lambda:False,retry_errors=True)
            self.assertEqual(result['stats'],{'done':1,'pending':0,'error':0})
            store=e.Store(d/'embeddings.sqlite3')
            self.assertEqual(store.db.execute('SELECT status,error,vectors FROM embeddings WHERE space=?',(space,)).fetchone()[:],('done',None,None))
            self.assertEqual(store.db.execute('SELECT status FROM embeddings WHERE space=?',('b'*64,)).fetchone()[0],'done')
            store.db.close();self.assertEqual(FakeClient.calls,0);self.assertEqual(api.published,[])

    def test_description_completion_cannot_be_used_for_embedding_completion(self):
        c=self.setting();c['_pipeline']='descriptions';space=FakeClient(c['embedding']).space
        _,completed=w.NativeSource(PublishedAPI([self.row()],space),c).embedding_inventory(space)
        self.assertEqual(completed,set())

    def test_reconciled_rows_without_local_vectors_still_request_model_rebuild(self):
        c=self.setting();old=FakeClient(c['embedding']).space
        with tempfile.TemporaryDirectory() as tmp:
            d=Path(tmp);store=e.Store(d/'embeddings.sqlite3')
            with store.db:store.db.execute('INSERT INTO embeddings(id,space,etag,path,status) VALUES (?,?,?,?,?)',('file',old,'v1','synthetic.png','done'))
            store.db.close();c['embedding']['max_edge']*=2
            with patch.object(e,'Client',FakeClient):self.assertTrue(w.detect_embedding_rebuild(c,d,None))
            marker=json.loads((d/'embedding-rebuild.json').read_text())
            self.assertTrue(marker['required']);self.assertFalse(marker['approved']);self.assertEqual(marker['file_count'],1)


if __name__=='__main__':unittest.main()
