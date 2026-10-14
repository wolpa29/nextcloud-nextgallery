"""Frozen SQL paging, atomic updates and private cache lifetime regressions."""
import json
import os
from pathlib import Path
import sqlite3
import sys
import tempfile
import time
import unittest
sys.path.insert(0, str(Path(__file__).resolve().parents[2] / 'worker'))
from search_index import SearchIndex, SearchSessionExpired


def doc(i, name=None):
    return {'type':'document','file_id':i,'path':'/Demo/'+(name or f'motorcycle-{i}.png'),
            'name':name or f'motorcycle-{i}.png','kind':'image','etag':'synthetic-etag',
            'mtime':1700000000,'size':100,'metadata':{},'ocr':'private-synthetic-OCR-ZX314'}


class SearchSessionTest(unittest.TestCase):
    def setUp(self):
        self.tmp=tempfile.TemporaryDirectory()
        self.path=Path(self.tmp.name)/'index.sqlite3'
        self.index=SearchIndex(self.path)
        self.index.import_ndjson(json.dumps(doc(i)) for i in range(1,7))
        self.request={'query':'motorcycle','roots':['/Demo'],'phase':'text','limit':2,
                      'offset':0,'embedding_enabled':False,'context':'model-context'}

    def tearDown(self):
        self.index.close()
        self.tmp.cleanup()

    def test_frozen_paging_survives_incremental_reranking(self):
        first=self.index.query(self.request)
        self.assertEqual([r['file_id'] for r in first['items']],[1,2])
        self.index.update_ndjson([json.dumps(doc(1,'changed.png')),json.dumps(doc(7))])
        follow=self.index.query(self.request|{'offset':2,'search_token':first['search_token']})
        self.assertEqual([r['file_id'] for r in follow['items']],[3,4])
        self.assertEqual(follow['total'],6)
        self.assertEqual(follow['search_token'],first['search_token'])
        fresh=self.index.query(self.request|{'limit':20})
        self.assertEqual([r['file_id'] for r in fresh['items']],[2,3,4,5,6,7])

    def test_snapshot_survives_atomic_full_catalog_publication(self):
        first=self.index.query(self.request)
        self.index.close()
        replacement=Path(self.tmp.name)/'replacement.sqlite3'
        with SearchIndex(replacement) as other:
            other.import_ndjson(json.dumps(doc(i)) for i in range(10,15))
        os.replace(replacement,self.path)
        self.index=SearchIndex(self.path)
        frozen=self.index.query(self.request|{'offset':2,'search_token':first['search_token']})
        self.assertEqual([r['file_id'] for r in frozen['items']],[3,4])
        self.assertEqual([r['file_id'] for r in self.index.query(self.request)['items']],[10,11])

    def test_scope_query_model_and_phase_cannot_reuse_other_snapshot(self):
        token=self.index.query(self.request)['search_token']
        for changed in [{'roots':['/Other']},{'query':'ZX314'},{'context':'other-model'},
                        {'phase':'hybrid'},{'kind':'video'},{'period':'2024'}]:
            with self.subTest(changed=changed),self.assertRaises(SearchSessionExpired):
                self.index.query(self.request|changed|{'search_token':token})

    def test_expiry_and_disk_retention_are_bounded_without_query_plaintext(self):
        result=self.index.query(self.request)
        conn=self.index.get_connection()
        conn.execute('UPDATE session_store.search_sessions SET created_at=?',(int(time.time())-901,))
        conn.commit()
        with self.assertRaises(SearchSessionExpired):
            self.index.query(self.request|{'search_token':result['search_token']})
        for _ in range(20):self.index.query(self.request)
        self.assertEqual(conn.execute('SELECT COUNT(*) FROM session_store.search_sessions').fetchone()[0],16)
        columns=[r[1] for r in conn.execute('PRAGMA session_store.table_info(search_sessions)')]
        self.assertNotIn('query',columns)
        self.assertFalse(any('ocr' in r[1] or 'vector' in r[1] for r in conn.execute('PRAGMA session_store.table_info(search_results)')))
        self.assertEqual(os.stat(str(self.path)+'.sessions').st_mode & 0o777,0o600)

    def test_failed_update_after_1000_records_rolls_back_every_record(self):
        before=self.index.query(self.request|{'limit':20})
        def broken():
            for i in range(1,1003):yield json.dumps(doc(i,'changed-'+str(i)+'.png'))
            yield 'not valid JSON'
        with self.assertRaises(json.JSONDecodeError):self.index.update_ndjson(broken())
        after=self.index.query(self.request|{'limit':20})
        self.assertEqual(after['items'],before['items'])
        self.assertEqual(self.index.get_connection().execute('SELECT COUNT(*) FROM documents').fetchone()[0],6)


if __name__=='__main__':unittest.main()
