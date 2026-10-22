import importlib.util
from pathlib import Path
import unittest

spec = importlib.util.spec_from_file_location('quality_evaluation', Path(__file__).resolve().parents[2] / 'benchmarks/evaluate.py')
evaluation = importlib.util.module_from_spec(spec)
spec.loader.exec_module(evaluation)


class QualityEvaluation(unittest.TestCase):
    def test_recall_requires_every_labelled_hit_and_counts_absent_queries(self):
        metrics = evaluation.search_metrics([
            {'id': 'many', 'relevant': [1, 2], 'ranking': [1, 3, 2]},
            {'id': 'missing', 'relevant': [4], 'ranking': []},
            {'id': 'absent', 'relevant': [], 'ranking': [9], 'absent': True},
        ], (1, 3))
        self.assertEqual(metrics['recall@1'], .25)
        self.assertEqual(metrics['recall@3'], .5)
        self.assertEqual(metrics['all_relevant@3'], .5)
        self.assertEqual(metrics['mrr'], .5)
        self.assertEqual(metrics['absent_candidate_rate'], 1)

    def test_false_merges_splits_and_unassigned_singletons_are_separate(self):
        metrics = evaluation.people_metrics([
            {'id': 'a', 'identity': 'alice', 'group': 'mixed'},
            {'id': 'b', 'identity': 'alice', 'group': 'mixed'},
            {'id': 'c', 'identity': 'alice', 'group': None},
            {'id': 'd', 'identity': 'bob', 'group': 'mixed'},
            {'id': 'e', 'identity': 'bob', 'group': None},
        ])
        self.assertEqual(metrics['correct_pairs'], 1)
        self.assertEqual(metrics['false_merge_pairs'], 2)
        self.assertEqual(metrics['false_split_pairs'], 3)
        self.assertEqual(metrics['pair_precision'], 1 / 3)
        self.assertEqual(metrics['pair_recall'], 1 / 4)

    def test_duplicates_and_unlabelled_negatives_are_rejected(self):
        for queries in [
            [{'id': 'bad', 'relevant': [], 'ranking': []}],
            [{'id': 'bad', 'relevant': [1], 'ranking': [1, 1]}],
            [{'id': 'same', 'relevant': [1], 'ranking': [1]}] * 2,
        ]:
            with self.assertRaises(ValueError): evaluation.search_metrics(queries)
        with self.assertRaises(ValueError): evaluation.evaluate({})

    def test_no_evidence_is_unavailable_instead_of_perfect(self):
        metrics = evaluation.search_metrics([])
        self.assertIsNone(metrics['recall@10'])
        self.assertIsNone(evaluation.people_metrics([])['pair_precision'])
