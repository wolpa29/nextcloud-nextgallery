import base64
import io
import json
from pathlib import Path
import sys
import threading
import unittest
from unittest.mock import Mock, patch
import urllib.request
import urllib.error
from http.server import HTTPServer
from types import SimpleNamespace

sys.path.insert(0, str(Path(__file__).resolve().parents[2] / 'worker'))
from embedding_server import (
    vector, is_oom, handler, Gemma, GemmaFull, Qwen, Custom
)


def make_png(color='red', size=(16, 16)):
    from PIL import Image
    buf = io.BytesIO()
    Image.new('RGB', size, color).save(buf, format='PNG')
    return buf.getvalue()


def make_b64_png(color='red', size=(16, 16)):
    return base64.b64encode(make_png(color, size)).decode('ascii')


class FakeEngine:
    def __init__(self, model='test-model', dimensions=128, space='a' * 64, batch_support=True):
        self.info = {
            'model': model,
            'family': 'gemma2',
            'dimensions': dimensions,
            'space': space,
            'modalities': ['text', 'image'],
        }
        if batch_support:
            self.info['image_batch'] = True
            self.info['max_batch_size'] = 64
        self.embed_calls = []
        self.embed_images_calls = []
        self.return_dimension = dimensions
        self.return_bad_vector = False
        self.return_wrong_count = False

    def embed(self, text=None, image=None):
        self.embed_calls.append((text, image))
        if self.return_bad_vector:
            return [float('nan')] * self.return_dimension
        return [1.0] + [0.0] * (self.return_dimension - 1)

    def embed_images(self, images):
        self.embed_images_calls.append(images)
        if self.return_wrong_count:
            return [[1.0] + [0.0] * (self.return_dimension - 1)] * (len(images) - 1)
        if self.return_bad_vector:
            return [[float('nan')] * self.return_dimension for _ in images]
        results = []
        for i, _ in enumerate(images):
            idx = (i + 1) % self.return_dimension
            v = [0.0] * self.return_dimension
            v[idx] = 1.0
            results.append(v)
        return results


def run_test_server(engine, token='test-token'):
    server = HTTPServer(('127.0.0.1', 0), handler(engine, token))
    thread = threading.Thread(target=server.serve_forever, daemon=True)
    thread.start()
    url = f'http://127.0.0.1:{server.server_port}/v1'
    return server, thread, url


def http_post(url, body, token=None):
    wire = json.dumps(body).encode('utf-8')
    req = urllib.request.Request(url, data=wire, method='POST')
    req.add_header('Content-Type', 'application/json')
    if token:
        req.add_header('Authorization', f'Bearer {token}')
    try:
        with urllib.request.urlopen(req) as resp:
            return resp.status, json.loads(resp.read().decode('utf-8'))
    except urllib.error.HTTPError as e:
        body_text = e.read().decode('utf-8')
        try:
            parsed = json.loads(body_text)
        except Exception:
            parsed = {'raw': body_text}
        return e.code, parsed


def http_get(url, token=None):
    req = urllib.request.Request(url, method='GET')
    if token:
        req.add_header('Authorization', f'Bearer {token}')
    try:
        with urllib.request.urlopen(req) as resp:
            return resp.status, json.loads(resp.read().decode('utf-8'))
    except urllib.error.HTTPError as e:
        body_text = e.read().decode('utf-8')
        try:
            parsed = json.loads(body_text)
        except Exception:
            parsed = {'raw': body_text}
        return e.code, parsed


class EmbeddingBatchServerTests(unittest.TestCase):
    def test_info_advertises_batch_for_gemma_full_and_qwen_without_altering_space_or_precision(self):
        import tempfile
        versions = {'torch': '2.14.1', 'transformers': '5.19.0', 'sentence-transformers': '6.1.0'}
        weights = 'a' * 64
        torch_mock = SimpleNamespace(
            float32=object(),
            set_num_threads=Mock(),
            backends=SimpleNamespace(cuda=SimpleNamespace(matmul=SimpleNamespace(allow_tf32=True)),
                                     cudnn=SimpleNamespace(allow_tf32=True)),
            cuda=SimpleNamespace(is_available=lambda: False),
            inference_mode=Mock()
        )
        loader = Mock()
        args_full = SimpleNamespace(device='cpu', model_path='/synthetic', model='embeddinggemma-2-full', threads=4)
        with patch.dict(sys.modules, {'torch': torch_mock, 'sentence_transformers': SimpleNamespace(SentenceTransformer=loader)}), \
             patch('embedding_server.checkpoint_digest', return_value=weights), \
             patch('importlib.metadata.version', side_effect=versions.__getitem__):
            gf = GemmaFull(args_full)

        self.assertTrue(gf.info['image_batch'])
        self.assertEqual(gf.info['max_batch_size'], 64)
        self.assertEqual(gf.info['precision'], 'float32')
        import hashlib
        expected_space = hashlib.sha256((weights + '|2.14.1+cpu|5.19.0|6.1.0|fp32-cpu-vision280-search-v1').encode()).hexdigest()
        self.assertEqual(gf.info['space'], expected_space)

        with tempfile.TemporaryDirectory() as tmp:
            src_dir = Path(tmp) / 'src' / 'models'
            src_dir.mkdir(parents=True)
            (src_dir / 'qwen3_vl_embedding.py').write_bytes(b'impl-bytes')

            qwen_versions = {'transformers': '4.48.0', 'torch': '2.5.1', 'qwen-vl-utils': '0.0.8'}
            embedder_class = Mock()
            embedder_instance = Mock()
            embedder_instance.model.config.hidden_size = 2048
            embedder_class.return_value = embedder_instance
            args_qwen = SimpleNamespace(
                model_path='/synthetic/qwen', model='qwen/qwen3-vl-emb',
                qwen_source=str(Path(tmp)), model_revision='rev1'
            )
            with patch('embedding_server.checkpoint_digest', return_value=weights), \
                 patch('importlib.metadata.version', side_effect=qwen_versions.__getitem__), \
                 patch.dict(sys.modules, {'src.models.qwen3_vl_embedding': SimpleNamespace(Qwen3VLEmbedder=embedder_class), 'torch': torch_mock}):
                qw = Qwen(args_qwen)

        self.assertTrue(qw.info['image_batch'])
        self.assertEqual(qw.info['max_batch_size'], 64)
        impl_hash = hashlib.sha256(b'impl-bytes').hexdigest()
        runtime_str = '4.48.0|2.5.1|0.0.8|' + str(torch_mock.float32)
        expected_qwen_space = hashlib.sha256(('qwen/qwen3-vl-emb|rev1|' + weights + '|' + impl_hash + '|' + runtime_str + '|qwen-v1').encode()).hexdigest()
        self.assertEqual(qw.info['space'], expected_qwen_space)

    def test_lite_and_custom_do_not_advertise_batch_in_info(self):
        engine = FakeEngine(batch_support=False)
        self.assertNotIn('image_batch', engine.info)
        self.assertNotIn('max_batch_size', engine.info)

    def test_http_auth_and_routing(self):
        engine = FakeEngine()
        server, thread, url = run_test_server(engine, token='secret123')
        try:
            status, data = http_get(f'{url}/info')
            self.assertEqual(status, 401)
            self.assertIn('Authentication required', data.get('error', ''))

            status, data = http_get(f'{url}/info', token='wrong')
            self.assertEqual(status, 401)

            status, data = http_get(f'{url}/info', token='secret123')
            self.assertEqual(status, 200)
            self.assertEqual(data['model'], 'test-model')
            self.assertTrue(data['image_batch'])

            status, _ = http_get(f'{url}/unknown', token='secret123')
            self.assertEqual(status, 404)

            status, _ = http_post(f'{url}/unknown', {'model': 'test-model'}, token='secret123')
            self.assertEqual(status, 404)
        finally:
            server.shutdown()
            server.server_close()
            thread.join()

    def test_single_text_and_image_api_preserved(self):
        engine = FakeEngine(dimensions=128)
        server, thread, url = run_test_server(engine, token='secret123')
        try:
            status, data = http_post(f'{url}/embed', {'model': 'test-model', 'text': 'a white cat'}, token='secret123')
            self.assertEqual(status, 200)
            self.assertIn('embedding', data)
            self.assertEqual(len(data['embedding']), 128)
            self.assertEqual(data['space'], engine.info['space'])
            self.assertEqual(len(engine.embed_calls), 1)
            self.assertEqual(engine.embed_calls[0][0], 'a white cat')

            png_b64 = make_b64_png('blue')
            status, data = http_post(f'{url}/embed', {'model': 'test-model', 'image': png_b64}, token='secret123')
            self.assertEqual(status, 200)
            self.assertIn('embedding', data)
            self.assertEqual(len(data['embedding']), 128)
            self.assertEqual(len(engine.embed_calls), 2)
            self.assertIsNotNone(engine.embed_calls[1][1])
        finally:
            server.shutdown()
            server.server_close()
            thread.join()

    def test_batch_images_returns_embeddings_in_exact_order(self):
        engine = FakeEngine(dimensions=128)
        server, thread, url = run_test_server(engine, token='secret123')
        try:
            images = [make_b64_png('red'), make_b64_png('green'), make_b64_png('blue')]
            status, data = http_post(f'{url}/embed', {'model': 'test-model', 'images': images}, token='secret123')
            self.assertEqual(status, 200)
            self.assertIn('embeddings', data)
            self.assertEqual(data['space'], engine.info['space'])
            embeddings = data['embeddings']
            self.assertEqual(len(embeddings), 3)

            for i, emb in enumerate(embeddings):
                self.assertEqual(len(emb), 128)
                norm = sum(x * x for x in emb)
                self.assertAlmostEqual(norm, 1.0, places=5)
                idx = (i + 1) % 128
                self.assertAlmostEqual(emb[idx], 1.0, places=5)

            self.assertEqual(len(engine.embed_images_calls), 1)
            self.assertEqual(len(engine.embed_images_calls[0]), 3)
        finally:
            server.shutdown()
            server.server_close()
            thread.join()

    def test_batch_boundary_conditions(self):
        engine = FakeEngine(dimensions=128)
        server, thread, url = run_test_server(engine, token='secret123')
        try:
            img = make_b64_png('red', size=(2, 2))
            status, data = http_post(f'{url}/embed', {'model': 'test-model', 'images': [img] * 64}, token='secret123')
            self.assertEqual(status, 200)
            self.assertEqual(len(data['embeddings']), 64)

            status, data = http_post(f'{url}/embed', {'model': 'test-model', 'images': []}, token='secret123')
            self.assertEqual(status, 400)
            self.assertIn('error', data)

            status, data = http_post(f'{url}/embed', {'model': 'test-model', 'images': [img] * 65}, token='secret123')
            self.assertEqual(status, 400)
            self.assertIn('error', data)
        finally:
            server.shutdown()
            server.server_close()
            thread.join()

    def test_exactly_one_input_form_enforced(self):
        engine = FakeEngine()
        server, thread, url = run_test_server(engine, token='secret123')
        try:
            img = make_b64_png('yellow')

            cases = [
                {'model': 'test-model', 'text': 'hi', 'image': img},
                {'model': 'test-model', 'text': 'hi', 'images': [img]},
                {'model': 'test-model', 'image': img, 'images': [img]},
                {'model': 'test-model', 'text': 'hi', 'image': img, 'images': [img]},
                {'model': 'test-model'},
            ]
            for case in cases:
                with self.subTest(case=case):
                    calls_before = len(engine.embed_calls) + len(engine.embed_images_calls)
                    status, data = http_post(f'{url}/embed', case, token='secret123')
                    self.assertEqual(status, 400)
                    self.assertIn('error', data)
                    calls_after = len(engine.embed_calls) + len(engine.embed_images_calls)
                    self.assertEqual(calls_after, calls_before, 'Inference must not be called when input form is invalid')
        finally:
            server.shutdown()
            server.server_close()
            thread.join()

    def test_invalid_inputs_prevent_inference_execution(self):
        engine = FakeEngine()
        server, thread, url = run_test_server(engine, token='secret123')
        try:
            valid_img = make_b64_png('blue')
            invalid_cases = [
                {'model': 'wrong-model', 'images': [valid_img]},
                {'model': 'test-model', 'images': 'not-a-list'},
                {'model': 'test-model', 'images': [123]},
                {'model': 'test-model', 'images': [None]},
                {'model': 'test-model', 'images': ['not-valid-base64!@#$']},
                {'model': 'test-model', 'images': [base64.b64encode(b'plain text not an image').decode()]},
                {'model': 'test-model', 'images': [valid_img, 'bad-base64']},
                {'model': 'test-model', 'text': ''},
                {'model': 'test-model', 'text': '   '},
                {'model': 'test-model', 'text': 'x' * 201},
                {'model': 'test-model', 'image': 'bad-base64'},
            ]
            for case in invalid_cases:
                with self.subTest(case=case):
                    calls_before = len(engine.embed_calls) + len(engine.embed_images_calls)
                    status, data = http_post(f'{url}/embed', case, token='secret123')
                    self.assertEqual(status, 400)
                    calls_after = len(engine.embed_calls) + len(engine.embed_images_calls)
                    self.assertEqual(calls_after, calls_before, 'Inference must never be called on invalid input')
        finally:
            server.shutdown()
            server.server_close()
            thread.join()

    def test_invalid_engine_vectors_or_count_return_error(self):
        engine = FakeEngine(dimensions=128)
        server, thread, url = run_test_server(engine, token='secret123')
        try:
            img = make_b64_png('red')

            engine.return_wrong_count = True
            status, data = http_post(f'{url}/embed', {'model': 'test-model', 'images': [img, img]}, token='secret123')
            self.assertEqual(status, 400)
            self.assertIn('error', data)

            engine.return_wrong_count = False
            engine.return_dimension = 64
            status, data = http_post(f'{url}/embed', {'model': 'test-model', 'images': [img]}, token='secret123')
            self.assertEqual(status, 400)
            self.assertIn('error', data)

            engine.return_dimension = 128
            engine.return_bad_vector = True
            status, data = http_post(f'{url}/embed', {'model': 'test-model', 'images': [img]}, token='secret123')
            self.assertEqual(status, 400)
            self.assertIn('error', data)
        finally:
            server.shutdown()
            server.server_close()
            thread.join()

    def test_gemma_full_embed_images_and_cuda_oom_halving_retry(self):
        class MockCUDAOOM(Exception):
            pass

        import contextlib
        torch_mock = SimpleNamespace(
            float32=object(),
            set_num_threads=Mock(),
            backends=SimpleNamespace(cuda=SimpleNamespace(matmul=SimpleNamespace(allow_tf32=True)),
                                     cudnn=SimpleNamespace(allow_tf32=True)),
            inference_mode=contextlib.nullcontext,
            cuda=SimpleNamespace(empty_cache=Mock(), OutOfMemoryError=MockCUDAOOM)
        )

        encode_mock = Mock()
        def mock_encode(items, batch_size=None, normalize_embeddings=True, show_progress_bar=False):
            if batch_size > 2:
                raise MockCUDAOOM('CUDA out of memory')
            rows = []
            for item in items:
                rows.append([1.0] + [0.0] * 767)
            return rows

        encode_mock.side_effect = mock_encode

        loader = Mock()
        loader.return_value.encode = encode_mock
        args = SimpleNamespace(device='cpu', model_path='/synthetic', model='embeddinggemma-2-full', threads=4)
        versions = {'torch': '2.14.1', 'transformers': '5.19.0', 'sentence-transformers': '6.1.0'}

        with patch.dict(sys.modules, {'torch': torch_mock, 'sentence_transformers': SimpleNamespace(SentenceTransformer=loader)}), \
             patch('embedding_server.checkpoint_digest', return_value='a' * 64), \
             patch('importlib.metadata.version', side_effect=versions.__getitem__):
            gf = GemmaFull(args)

        test_images = [make_png('red'), make_png('green'), make_png('blue'), make_png('yellow')]
        embeddings = gf.embed_images(test_images)

        self.assertEqual(len(embeddings), 4)
        self.assertTrue(torch_mock.cuda.empty_cache.called)
        self.assertEqual(encode_mock.call_count, 3)
        self.assertEqual(encode_mock.call_args_list[0].kwargs['batch_size'], 4)
        self.assertEqual(encode_mock.call_args_list[1].kwargs['batch_size'], 2)
        self.assertEqual(encode_mock.call_args_list[2].kwargs['batch_size'], 2)

        encode_mock.reset_mock()
        encode_mock.side_effect = MockCUDAOOM('CUDA out of memory')
        with self.assertRaises(MockCUDAOOM):
            gf.embed_images([test_images[0]])

    def test_qwen_embed_images_and_cuda_oom_halving_retry(self):
        import contextlib
        import tempfile
        class MockCUDAOOM(Exception):
            pass

        torch_mock = SimpleNamespace(
            float32=object(),
            cuda=SimpleNamespace(empty_cache=Mock(), is_available=lambda: True, OutOfMemoryError=MockCUDAOOM),
            bfloat16=object(),
            inference_mode=contextlib.nullcontext
        )

        process_mock = Mock()
        def mock_process(items, normalize=True):
            if len(items) > 2:
                raise MockCUDAOOM('CUDA out of memory')
            rows = []
            for _ in items:
                row = Mock()
                row.float.return_value.cpu.return_value.tolist.return_value = [1.0] + [0.0] * 127
                rows.append(row)
            return rows

        process_mock.side_effect = mock_process

        embedder_class = Mock()
        embedder_instance = Mock()
        embedder_instance.model.config.hidden_size = 128
        embedder_instance.process = process_mock
        embedder_class.return_value = embedder_instance

        with tempfile.TemporaryDirectory() as tmp:
            src_dir = Path(tmp) / 'src' / 'models'
            src_dir.mkdir(parents=True)
            (src_dir / 'qwen3_vl_embedding.py').write_bytes(b'impl-bytes')

            qwen_versions = {'transformers': '4.48.0', 'torch': '2.5.1', 'qwen-vl-utils': '0.0.8'}
            args = SimpleNamespace(
                model_path='/synthetic/qwen', model='qwen/qwen3-vl-emb',
                qwen_source=str(Path(tmp)), model_revision='rev1'
            )

            with patch('embedding_server.checkpoint_digest', return_value='b' * 64), \
                 patch('importlib.metadata.version', side_effect=qwen_versions.__getitem__), \
                 patch.dict(sys.modules, {'src.models.qwen3_vl_embedding': SimpleNamespace(Qwen3VLEmbedder=embedder_class), 'torch': torch_mock}):
                qw = Qwen(args)

        test_images = [make_png('red'), make_png('green'), make_png('blue'), make_png('yellow')]
        embeddings = qw.embed_images(test_images)

        self.assertEqual(len(embeddings), 4)
        self.assertTrue(torch_mock.cuda.empty_cache.called)
        self.assertEqual(process_mock.call_count, 3)

    def test_single_item_oom_raises_503_on_http_server(self):
        class OOMEngine(FakeEngine):
            def embed_images(self, images):
                raise Exception('CUDA out of memory')

        engine = OOMEngine()
        server, thread, url = run_test_server(engine, token='secret123')
        try:
            img = make_b64_png('red')
            status, data = http_post(f'{url}/embed', {'model': 'test-model', 'images': [img]}, token='secret123')
            self.assertEqual(status, 503)
            self.assertIn('unavailable', data.get('error', ''))
        finally:
            server.shutdown()
            server.server_close()
            thread.join()

    def test_fallback_for_engine_without_embed_images(self):
        class LegacyEngine:
            def __init__(self):
                self.info = {'model': 'legacy', 'dimensions': 128, 'space': 'c' * 64, 'modalities': ['text', 'image']}
                self.calls = []
            def embed(self, text=None, image=None):
                self.calls.append((text, image))
                return [1.0] + [0.0] * 127

        engine = LegacyEngine()
        server, thread, url = run_test_server(engine, token='secret123')
        try:
            imgs = [make_b64_png('red'), make_b64_png('blue')]
            status, data = http_post(f'{url}/embed', {'model': 'legacy', 'images': imgs}, token='secret123')
            self.assertEqual(status, 200)
            self.assertEqual(len(data['embeddings']), 2)
            self.assertEqual(len(engine.calls), 2)
        finally:
            server.shutdown()
            server.server_close()
            thread.join()


if __name__ == '__main__':
    unittest.main()
