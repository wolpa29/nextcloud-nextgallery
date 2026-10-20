"""Merge suggestions computed by the face worker after a complete run."""
import io
import math
from pathlib import Path
import tempfile
import unittest
from unittest.mock import Mock, patch

from PIL import Image

import media_ai_faces as faces
from test_faces_pipeline import MockAPI, MockItem, MockSource


def unit(*values):
    vector = list(values) + [0.0] * (128 - len(values))
    norm = math.sqrt(sum(v * v for v in vector))
    return [v / norm for v in vector]


def angle(degrees):
    """Unit vector whose cosine to angle(0) is cos(degrees)."""
    return unit(math.cos(math.radians(degrees)), math.sin(math.radians(degrees)))


@unittest.skipIf(faces.np is None, 'NumPy is part of the optional face runtime')
class MergeSuggestionTests(unittest.TestCase):
    def test_close_groups_are_suggested_most_similar_first(self):
        items = [
            ('a', angle(0)), ('a', angle(4)),     # centroid at 2 degrees
            ('b', angle(-40)),                    # cos 42 = 0.74
            ('c', angle(60)), ('c', angle(64)),   # cos 60 = 0.50
            ('z', unit(0, 0, 1)), ('z', unit(0, 0, 1, 0.1)),  # someone else
        ]
        pairs = faces.merge_suggestions(items, cosine_threshold=0.55)
        keys = [(p['a'], p['b']) for p in pairs]
        self.assertEqual(keys[0], ('a', 'b'))
        self.assertEqual(keys[1], ('a', 'c'))
        self.assertNotIn(('b', 'c'), keys)
        self.assertNotIn(('a', 'z'), keys)
        self.assertTrue(all(p['a'] < p['b'] for p in pairs), 'pairs are canonical')
        self.assertEqual([p['similarity'] for p in pairs], sorted((p['similarity'] for p in pairs), reverse=True))

    def test_threshold_follows_assignment_threshold_with_floor(self):
        items = [('a', angle(0)), ('a', angle(0)), ('b', angle(50))]  # cosine 0.64
        self.assertTrue(faces.merge_suggestions(items, cosine_threshold=0.55))
        self.assertFalse(faces.merge_suggestions(items, cosine_threshold=0.95))
        far = [('a', angle(0)), ('a', angle(0)), ('b', angle(75))]  # cosine 0.26
        self.assertFalse(faces.merge_suggestions(far, cosine_threshold=0.30), 'never below the floor')

    def test_single_faces_are_only_compared_with_anchors(self):
        items = [('s1', unit(1, 0)), ('s2', unit(1, 0.01))]
        self.assertEqual(faces.merge_suggestions(items), [])

    def test_bounded_per_group_and_in_total(self):
        items = [('anchor', unit(1, 0)), ('anchor', unit(1, 0))]
        items += [(f'g{i:02d}', unit(1, 0.01 * i)) for i in range(20)]
        pairs = faces.merge_suggestions(items, per_group=3)
        self.assertEqual(len(pairs), 3)
        self.assertEqual(len(faces.merge_suggestions(items, per_group=8, limit=2)), 2)

    def test_empty_and_degenerate_input(self):
        self.assertEqual(faces.merge_suggestions([]), [])
        self.assertEqual(faces.merge_suggestions([('a', unit(1)), ('a', unit(1))]), [])


@unittest.skipIf(faces.np is None, 'NumPy is part of the optional face runtime')
class WeakSuggestionTests(unittest.TestCase):
    strong = [('known', angle(0)), ('known', angle(4))]  # centroid at 2 degrees

    def test_weak_group_points_at_strong_group(self):
        pairs = faces.merge_suggestions(self.strong, 0.55, weak_items=[('weak', angle(40))])  # cos 38 = 0.79
        self.assertEqual([(p['a'], p['b']) for p in pairs], [('known', 'weak')])

    def test_weak_threshold_is_the_assignment_threshold(self):
        # cos 52 = 0.62 would be a regular suggestion (>= 0.40) and is one for an eligible face.
        self.assertTrue(faces.merge_suggestions(self.strong + [('single', angle(54))], 0.70))
        self.assertEqual(faces.merge_suggestions(self.strong, 0.70, weak_items=[('weak', angle(54))]), [])
        self.assertTrue(faces.merge_suggestions(self.strong, 0.60, weak_items=[('weak', angle(54))]))

    def test_weak_faces_never_anchor(self):
        weak = [('w1', unit(0, 1)), ('w1', unit(0, 1)), ('w2', unit(0, 1, 0.01))]
        self.assertEqual(faces.merge_suggestions(self.strong, weak_items=weak), [], 'no weak-vs-weak pairs')
        single = [('single', unit(0, 1))]
        self.assertEqual(faces.merge_suggestions(single, weak_items=weak), [], 'one eligible face is no anchor')

    def test_weak_faces_of_a_known_group_are_no_source(self):
        mixed = self.strong + [('other', angle(90)), ('other', angle(92))]
        pairs = faces.merge_suggestions(mixed, 0.55, weak_items=[('other', angle(1))])
        self.assertEqual(pairs, [])

    def test_regular_pairs_keep_priority_within_bounds(self):
        items = self.strong + [('single', angle(30))]  # cos 28 = 0.88
        weak = [(f'w{i:02d}', angle(1)) for i in range(10)]
        pairs = faces.merge_suggestions(items, 0.55, limit=3, weak_items=weak)
        self.assertEqual(len(pairs), 3)
        self.assertEqual((pairs[0]['a'], pairs[0]['b']), ('known', 'single'), 'regular pair first despite lower similarity')
        many = [('a', unit(1, 0)), ('a', unit(1, 0))] + [(f's{i}', unit(1, 0, 0.1 * i)) for i in range(1, 6)]
        per_weak = faces.merge_suggestions(many + [('b', unit(1, 0.1)), ('b', unit(1, 0.1))], 0.55, per_group=1,
                                           weak_items=[('weak', unit(1, 0.05))])
        self.assertEqual(len([p for p in per_weak if 'weak' in (p['a'], p['b'])]), 1)

    def test_store_loads_weak_vectors_separately(self):
        with tempfile.TemporaryDirectory() as temp:
            store = faces.FaceStore(Path(temp) / 'faces.sqlite3')
            base = {'file_id': 1, 'etag': 'e', 'bbox': [0, 0, 0.1, 0.1], 'score': 0.9, 'review': 0}
            store.save_detections([
                {**base, 'id': 'good', 'vector': angle(0), 'group_id': 'g'},
                {**base, 'id': 'weak', 'vector': angle(10), 'group_id': 'w', 'eligible': False},
                {**base, 'id': 'review', 'vector': angle(20), 'group_id': 'r', 'eligible': False, 'review': 1},
            ])
            self.assertEqual([g for g, _ in store.load_active_vectors()], ['g'])
            self.assertEqual([g for g, _ in store.load_weak_vectors()], ['w'])
            store.close()


class SuggestionAPI(MockAPI):
    def __init__(self, fail=False):
        super().__init__()
        self.suggestions = []
        self.fail = fail

    def call(self, endpoint, body=None):
        if endpoint == 'faces-suggestions':
            if self.fail:
                raise OSError('service unavailable')
            self.suggestions.append(body)
            return {'stored': len(body['pairs'])}
        return super().call(endpoint, body)


class QualityAPI(SuggestionAPI):
    """Canonical detections carry the server's quality verdict like Faces::publish()."""

    def call(self, endpoint, body=None):
        response = super().call(endpoint, body)
        if endpoint == 'publish-faces':
            for canon, sent in zip(response['detections'], body['detections']):
                canon['eligible'] = faces.face_eligible(sent)
        return response


@unittest.skipIf(faces.np is None, 'NumPy is part of the optional face runtime')
class RunPublishesSuggestionsTests(unittest.TestCase):
    def run_pipeline(self, api, stop=lambda: False, faces_per_file=None):
        image = io.BytesIO()
        Image.new('RGB', (64, 64), 'green').save(image, format='JPEG')
        with tempfile.TemporaryDirectory() as temp:
            d = Path(temp)
            models = d / 'faces-local' / 'models'
            models.mkdir(parents=True)
            (models / 'face_detection_yunet_2023mar.onnx').touch()
            (models / 'face_recognition_sface_2021dec.onnx').touch()
            config = {'faces': {'enabled': True, 'cosine_threshold': 0.55}, '_model_directory': str(d), '_inference_directory': str(d)}
            detector = Mock()
            # The third face is below the assignment threshold (cos 60 = 0.50 < 0.55),
            # so the online index starts a second group that the centroids still relate.
            detector.process_image.side_effect = faces_per_file or [
                [{'bbox': [0.1, 0.1, 0.3, 0.3], 'vector': angle(0), 'score': 0.95}],
                [{'bbox': [0.1, 0.1, 0.3, 0.3], 'vector': angle(-5), 'score': 0.95}],
                [{'bbox': [0.1, 0.1, 0.3, 0.3], 'vector': angle(60), 'score': 0.95}],
            ]
            events = []
            files = range(1, len(faces_per_file) + 1) if faces_per_file else (1, 2, 3)
            source = MockSource([MockItem(i, f'etag-{i}') for i in files], image.getvalue())
            with patch.object(faces, 'cv2', Mock()), patch.object(faces, 'FaceDetector', return_value=detector):
                result = faces.run(config, source, api, d, lambda event, **fields: events.append((event, fields)), stop)
            return result, events

    def test_finished_run_publishes_suggestions_with_account_state(self):
        api = SuggestionAPI()
        result, events = self.run_pipeline(api)
        self.assertEqual(result['state'], 'finished')
        self.assertEqual(len(api.suggestions), 1)
        body = api.suggestions[0]
        self.assertEqual((body['epoch'], body['generation']), (api.epoch, api.generation))
        groups = {d['group_id'] for p in api.published for d in p['detections']}
        self.assertEqual(len(groups), 2, 'fixture produced two groups')
        self.assertEqual(len(body['pairs']), 1)
        self.assertEqual({body['pairs'][0]['a'], body['pairs'][0]['b']}, groups)
        self.assertIn('faces_suggestions', [name for name, _ in events])

    def test_filtered_face_gets_a_suggestion_toward_a_known_person(self):
        api = QualityAPI()
        tiny = [0.1, 0.1, 0.04, 0.04]  # 0.16% of the image: below the default area filter
        result, _ = self.run_pipeline(api, faces_per_file=[
            [{'bbox': [0.1, 0.1, 0.3, 0.3], 'vector': angle(0), 'score': 0.95}],
            [{'bbox': [0.1, 0.1, 0.3, 0.3], 'vector': angle(-5), 'score': 0.95}],
            [{'bbox': tiny, 'vector': angle(10), 'score': 0.95}],
            [{'bbox': tiny, 'vector': angle(11), 'score': 0.95}],
        ])
        self.assertEqual(result['state'], 'finished')
        published = [d['group_id'] for p in api.published for d in p['detections']]
        known, weak = published[0], published[2:]
        self.assertEqual(published[1], known)
        self.assertNotIn(known, weak, 'filtered faces are never assigned')
        pairs = api.suggestions[0]['pairs']
        self.assertEqual(sorted(tuple(sorted((p['a'], p['b']))) for p in pairs),
                         sorted(tuple(sorted((known, w))) for w in weak),
                         'each filtered face points at the known person, never at the other filtered face')

    def test_suggestion_failure_does_not_fail_the_run(self):
        result, events = self.run_pipeline(SuggestionAPI(fail=True))
        self.assertEqual(result['state'], 'finished')
        self.assertEqual(result['error'], 0)
        self.assertIn('faces_suggestions_error', [name for name, _ in events])

    def test_paused_run_publishes_nothing(self):
        api = SuggestionAPI()
        result, _ = self.run_pipeline(api, stop=lambda: True)
        self.assertEqual(result['state'], 'paused')
        self.assertEqual(api.suggestions, [])


if __name__ == '__main__':
    unittest.main()
