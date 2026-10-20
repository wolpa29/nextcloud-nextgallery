"""Unit tests for FaceStore, FaceClusterIndex, and IoU computation."""
import math
from pathlib import Path
import tempfile
import unittest

import media_ai_faces as faces


def unit_vector(index: int, dim: int = 128) -> list[float]:
    v = [0.0] * dim
    v[index] = 1.0
    return v


def noisy_vector(base: list[float], angle_rad: float) -> list[float]:
    """Create a unit vector at a specific cosine similarity: cos(angle_rad)."""
    dim = len(base)
    ortho = [0.0] * dim
    for i in range(dim):
        if abs(base[i]) < 1e-5:
            ortho[i] = 1.0
            break
    dot = sum(a * b for a, b in zip(base, ortho))
    ortho = [o - dot * b for o, b in zip(ortho, base)]
    norm = math.sqrt(sum(x * x for x in ortho))
    ortho = [x / norm for x in ortho]

    return [math.cos(angle_rad) * b + math.sin(angle_rad) * o for b, o in zip(base, ortho)]


class FacesClusteringTests(unittest.TestCase):
    def test_compute_iou(self):
        # Identical boxes
        self.assertAlmostEqual(faces.compute_iou([0.1, 0.1, 0.2, 0.2], [0.1, 0.1, 0.2, 0.2]), 1.0)
        # Non-overlapping
        self.assertAlmostEqual(faces.compute_iou([0.1, 0.1, 0.1, 0.1], [0.5, 0.5, 0.1, 0.1]), 0.0)
        # 50% overlap: Box 1 [0, 0, 2, 2] (area 4), Box 2 [1, 0, 2, 2] (area 4).
        # Inter [1, 0, 1, 2] (area 2). Union: 4 + 4 - 2 = 6. IoU: 2 / 6 = 1/3 ~ 0.3333
        box1 = [0.0, 0.0, 0.2, 0.2]
        box2 = [0.1, 0.0, 0.2, 0.2]
        self.assertAlmostEqual(faces.compute_iou(box1, box2), 1.0 / 3.0, places=4)

    def test_face_store_crud_and_snapshot_sync(self):
        with tempfile.TemporaryDirectory() as temp:
            db_path = Path(temp) / 'faces.sqlite3'
            store = faces.FaceStore(db_path)

            store.set_meta('epoch', 'test-epoch-123')
            store.set_meta('generation', '4')
            self.assertEqual(store.get_meta('epoch'), 'test-epoch-123')
            self.assertEqual(store.get_meta('generation'), '4')

            self.assertFalse(store.is_file_done(101, 'etag-a'))
            self.assertFalse(store.is_file_error(101, 'etag-a'))
            store.record_file(101, 'etag-a', 'error', error='temporary')
            self.assertTrue(store.is_file_error(101, 'etag-a'))

            store.record_file(101, 'etag-a', 'done', face_count=2)
            self.assertTrue(store.is_file_done(101, 'etag-a'))
            self.assertFalse(store.is_file_error(101, 'etag-a'))

            vec1 = unit_vector(0)
            vec2 = unit_vector(1)
            store.save_detections([
                {'id': 'det-1', 'file_id': 101, 'etag': 'etag-a', 'bbox': [0.1, 0.1, 0.2, 0.2], 'vector': vec1, 'score': 0.95, 'group_id': 'grp-1', 'review': 0},
                {'id': 'det-2', 'file_id': 101, 'etag': 'etag-a', 'bbox': [0.4, 0.4, 0.2, 0.2], 'vector': vec2, 'score': 0.90, 'group_id': 'grp-2', 'review': 0},
            ])

            active = store.load_active_vectors()
            self.assertEqual(len(active), 2)

            # Snapshot sync from backend: det-1 is kept and updated, det-2 was deleted/merged in backend
            store.sync_snapshot([
                {'id': 'det-1', 'file_id': 101, 'etag': 'etag-a', 'bbox': [0.1, 0.1, 0.2, 0.2], 'vector': vec1, 'score': 0.98, 'group_id': 'grp-merged', 'review': 0},
                {'id': 'det-3', 'file_id': 102, 'etag': 'etag-c', 'bbox': [0.2, 0.2, 0.1, 0.1], 'vector': unit_vector(3), 'score': 0.88, 'group_id': 'grp-3', 'review': 0},
            ])

            active_synced = store.load_active_vectors()
            self.assertEqual(len(active_synced), 2)
            groups = {item[0] for item in active_synced}
            self.assertIn('grp-merged', groups)
            self.assertIn('grp-3', groups)
            self.assertNotIn('grp-2', groups)  # Stale detection purged

            store.close()

    def test_dense_vector_search_does_not_drop_known_nearest_identity(self):
        if faces.np is None:
            self.skipTest('numpy required')
        np=faces.np
        rng=np.random.default_rng(29102026)
        vectors=rng.standard_normal((2048,128)).astype(np.float32)
        vectors/=np.linalg.norm(vectors,axis=1,keepdims=True)
        index=faces.FaceClusterIndex(0.55)
        index.populate([(str(i),v.tolist()) for i,v in enumerate(vectors)])
        for i in rng.integers(0,len(vectors),size=50):
            noise=rng.standard_normal(128).astype(np.float32)
            noise-=np.dot(noise,vectors[i])*vectors[i]
            noise/=np.linalg.norm(noise)
            query=0.8*vectors[i]+0.6*noise
            index.checkpoint()
            self.assertEqual(index.match_or_create(query.tolist()),str(i))
            index.rollback()

    def test_cluster_index_rollback_prevents_ghost_groups(self):
        index = faces.FaceClusterIndex(cosine_threshold=0.55)
        v1 = unit_vector(0)

        # Checkpoint before processing file A: tentative vectors must be isolated
        index.checkpoint()
        g1 = index.match_or_create(v1)
        self.assertEqual(len(index.tentative_groups), 1)
        self.assertEqual(len(index.tail_groups), 0)

        # Second face in same file can match first tentative face
        v1_close = noisy_vector(v1, math.acos(0.85))
        g1_matched = index.match_or_create(v1_close)
        self.assertEqual(g1_matched, g1)
        self.assertEqual(len(index.tentative_groups), 2)
        self.assertEqual(len(index.tail_groups), 0)

        # File A fails to publish -> rollback clears tentative buffers completely
        index.rollback()
        self.assertEqual(len(index.tentative_groups), 0)
        self.assertEqual(len(index.tail_groups), 0)

        # Next query with v1 creates a new group, not matching rolled-back ghost group
        index.checkpoint()
        g1_new = index.match_or_create(v1)
        self.assertEqual(len(index.tentative_groups), 1)
        # Commit on publish success
        index.commit()
        self.assertEqual(len(index.tentative_groups), 0)
        self.assertEqual(len(index.tail_groups), 1)

        # Subsequent matching vector matches committed g1_new
        g1_matched_after = index.match_or_create(v1_close)
        self.assertEqual(g1_matched_after, g1_new)

    def test_cluster_index_apply_canonical_overrides_tentative_group(self):
        index = faces.FaceClusterIndex(cosine_threshold=0.55)
        v1 = unit_vector(0)

        index.checkpoint()
        tentative_group = index.match_or_create(v1)
        self.assertIn(tentative_group, index.tentative_groups)

        # Server overrides group assignment due to user cannot-link or manual rule
        canonical_group = 'canonical-user-chosen-id'
        index.apply_canonical([{'group_id': canonical_group, 'vector': v1}])

        # Tentative group must NOT exist in the index; canonical_group must be in tail
        self.assertEqual(len(index.tentative_groups), 0)
        self.assertNotIn(tentative_group, index.tail_groups)
        self.assertIn(canonical_group, index.tail_groups)

    def test_cluster_index_disables_rebuild_during_checkpoint(self):
        if faces.np is None:
            raise unittest.SkipTest("numpy required for indexed matrix buffer")
        index = faces.FaceClusterIndex(cosine_threshold=0.55)
        # Seed 70 items
        initial = [(f'grp-{i}', unit_vector(i % 128)) for i in range(70)]
        index.populate(initial)
        self.assertIsNotNone(index.indexed_vectors)
        initial_indexed_count = len(index.indexed_groups)

        # Begin checkpoint
        index.checkpoint()
        # Add 100 tentative items during file processing
        for i in range(100):
            index.match_or_create(unit_vector((i + 70) % 128))

        # Rebuild must NOT have run mid-transaction; indexed set unchanged
        self.assertEqual(len(index.indexed_groups), initial_indexed_count)
        self.assertEqual(len(index.tentative_groups), 100)

        # On rollback: all 100 tentative discarded cleanly
        index.rollback()
        self.assertEqual(len(index.indexed_groups), initial_indexed_count)
        self.assertEqual(len(index.tentative_groups), 0)
        self.assertEqual(len(index.tail_groups), 0)

    def test_cluster_index_populate_and_tail_matching(self):
        index = faces.FaceClusterIndex(cosine_threshold=0.60)
        v_a = unit_vector(1)
        v_b = unit_vector(2)
        index.populate([('group-a', v_a), ('group-b', v_b)])

        v_a_close = noisy_vector(v_a, math.acos(0.85))
        self.assertEqual(index.match_or_create(v_a_close), 'group-a')

        v_b_close = noisy_vector(v_b, math.acos(0.85))
        self.assertEqual(index.match_or_create(v_b_close), 'group-b')

        v_c = unit_vector(3)
        new_g = index.match_or_create(v_c)
        self.assertNotIn(new_g, ('group-a', 'group-b'))


if __name__ == '__main__':
    unittest.main()
