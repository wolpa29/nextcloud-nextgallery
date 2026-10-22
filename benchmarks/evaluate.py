#!/usr/bin/env python3
"""Evaluate fixed, labelled results; never read photographs or call a model."""
import argparse
from collections import Counter
import json
from math import comb
from pathlib import Path


def search_metrics(queries, cutoffs=(1, 5, 10, 20, 50)):
    positives, negatives = [], []
    seen = set()
    for query in queries:
        if not query.get('id') or query['id'] in seen:
            raise ValueError('Each query needs a unique, nonempty id')
        seen.add(query['id'])
        relevant, ranking = query['relevant'], query['ranking']
        if len(set(relevant)) != len(relevant) or len(set(ranking)) != len(ranking):
            raise ValueError('Labels and rankings must not contain duplicate IDs')
        if not relevant:
            if query.get('absent') is not True:
                raise ValueError('Queries without relevant items must be explicitly labelled absent')
            negatives.append(ranking)
        else:
            positives.append((set(relevant), ranking))
    result = {'positive_queries': len(positives), 'absent_queries': len(negatives)}
    for cutoff in cutoffs:
        if not isinstance(cutoff, int) or cutoff < 1:
            raise ValueError('Cutoffs must be positive integers')
        recalls = [len(relevant.intersection(ranking[:cutoff])) / len(relevant) for relevant, ranking in positives]
        complete = [relevant.issubset(ranking[:cutoff]) for relevant, ranking in positives]
        result[f'recall@{cutoff}'] = sum(recalls) / len(recalls) if recalls else None
        result[f'all_relevant@{cutoff}'] = sum(complete) / len(complete) if complete else None
    reciprocal = [next((1 / (i + 1) for i, item in enumerate(ranking) if item in relevant), 0) for relevant, ranking in positives]
    result['mrr'] = sum(reciprocal) / len(reciprocal) if reciprocal else None
    coverage = [relevant.issubset(ranking) for relevant, ranking in positives]
    result['all_relevant_in_candidates'] = sum(coverage) / len(coverage) if coverage else None
    result['absent_queries_with_candidates'] = sum(bool(ranking) for ranking in negatives)
    result['absent_candidate_rate'] = sum(bool(ranking) for ranking in negatives) / len(negatives) if negatives else None
    return result


def people_metrics(assignments):
    """Pair counts are linear in input size, with no quadratic distance matrix."""
    truth, predicted, joint, ids = Counter(), Counter(), Counter(), set()
    for row in assignments:
        if not row.get('id') or row['id'] in ids:
            raise ValueError('Each detection needs a unique, nonempty id')
        ids.add(row['id'])
        if not row.get('identity'):
            raise ValueError('Ground-truth identity is required')
        # Unassigned detections are distinct predicted singletons, not one person.
        group = ('group', row['group']) if row.get('group') else ('unassigned', row['id'])
        truth[row['identity']] += 1
        predicted[group] += 1
        joint[(row['identity'], group)] += 1
    pairs = lambda counts: sum(comb(count, 2) for count in counts.values())
    true_positive, same_identity, same_group = pairs(joint), pairs(truth), pairs(predicted)
    return {
        'detections': len(ids), 'identities': len(truth), 'predicted_groups': len(predicted),
        'correct_pairs': true_positive, 'false_merge_pairs': same_group - true_positive,
        'false_split_pairs': same_identity - true_positive,
        'pair_precision': true_positive / same_group if same_group else None,
        'pair_recall': true_positive / same_identity if same_identity else None,
    }


def evaluate(data):
    if not data.get('provenance'):
        raise ValueError('Record dataset provenance and model/settings before evaluation')
    return {'provenance': data['provenance'], 'kind': 'labelled-result-evaluation; no new inference',
            'search': search_metrics(data.get('queries', [])),
            'people': people_metrics(data.get('people', []))}


if __name__ == '__main__':
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('input', type=Path)
    parser.add_argument('--output', type=Path)
    args = parser.parse_args()
    output = json.dumps(evaluate(json.loads(args.input.read_text())), indent=2, ensure_ascii=False) + '\n'
    if args.output:
        args.output.write_text(output)
    else:
        print(output, end='')
