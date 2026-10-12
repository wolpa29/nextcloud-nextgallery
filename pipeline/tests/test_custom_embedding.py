import argparse
import base64
from http.server import HTTPServer
import importlib.util
import io
import json
from pathlib import Path
import tempfile
import threading
import unittest
from unittest.mock import patch
import sys
sys.path.insert(0,str(Path(__file__).resolve().parents[2]/'worker'))
from embedding_server import Custom,handler
from media_ai_embedding import Client
from media_ai_errors import PipelineError
spec=importlib.util.spec_from_file_location('service_check',Path(__file__).resolve().parents[2]/'scripts/embedding_service_check.py')
check=importlib.util.module_from_spec(spec);spec.loader.exec_module(check)

class Engine:
    def __init__(self):
        self.info={'model':'operator/custom-model','family':'a-different-backend','space':'a'*64,'dimensions':128,'modalities':['text','image']}
        self.calls=[]
    def embed(self,text=None,image=None):
        self.calls.append((text,image));return [1.]+[0.]*127

class CustomTests(unittest.TestCase):
    def test_arbitrary_family_indexes_images_and_keeps_identity_checks(self):
        engine=Engine()
        config={'location':'external','url':'http://127.0.0.1:9379/v1','family':'custom','model':engine.info['model'],'max_edge':512,'video_frames':3,'timeout_seconds':10}
        client=Client(config)
        with patch.object(client,'call',side_effect=[engine.info,{'space':'a'*64,'embedding':[2.]+[0.]*127}]):
            self.assertEqual(client.health()['family'],'a-different-backend')
            self.assertEqual(client.image(b'synthetic'),[1.]+[0.]*127)
        for field,value in [('model','other-model'),('modalities',['text']),('family',''),('space','invalid')]:
            with self.subTest(field=field),patch.object(client,'call',return_value=engine.info|{field:value}):
                with self.assertRaises(PipelineError):client.health()
        with patch.object(client,'call',return_value=engine.info|{'modalities':[['invalid']]}):
            with self.assertRaises(PipelineError):client.health()

    def test_real_http_readiness_checks_text_and_synthetic_png_and_authentication(self):
        engine=Engine();server=HTTPServer(('127.0.0.1',0),handler(engine,'test-secret'))
        thread=threading.Thread(target=server.serve_forever,daemon=True);thread.start()
        url=f'http://127.0.0.1:{server.server_port}/v1'
        try:
            result=check.check_service(url,'test-secret',engine.info['model'],'custom')
            self.assertEqual(result,engine.info)
            self.assertEqual(engine.calls[0][0],'a red square')
            from PIL import Image
            with Image.open(io.BytesIO(engine.calls[1][1])) as image:self.assertEqual(image.size,(32,32));image.verify()
            with self.assertRaisesRegex(check.ServiceCheckError,'authentication'):check.check_service(url,'wrong')
            with self.assertRaisesRegex(check.ServiceCheckError,'model'):check.check_service(url,'test-secret','wrong-model')
            engine.info['modalities']=['text']
            with self.assertRaisesRegex(check.ServiceCheckError,'identity'):check.check_service(url,'test-secret')
            engine.info['modalities']=['text','image'];engine.embed=lambda **kwargs:[0.]*128
            with self.assertRaisesRegex(check.ServiceCheckError,'vector'):check.check_service(url,'test-secret')
        finally:server.shutdown();server.server_close();thread.join()

    def test_custom_adapter_gateway_validates_and_normalizes_model_outputs(self):
        with tempfile.TemporaryDirectory() as tmp:
            path=Path(tmp)/'adapter.py'
            path.write_text("class Engine:\n    info="+repr(Engine().info)+"\n    def embed(self,text=None,image=None):return [2.]+[0.]*127\ndef create_engine(args):return Engine()\n")
            args=argparse.Namespace(custom_adapter=str(path),model='operator/custom-model')
            engine=Custom(args)
            self.assertEqual(engine.embed(text='synthetic'),[1.]+[0.]*127)
            engine.engine.embed=lambda **kwargs:[1.]*256
            with self.assertRaisesRegex(ValueError,'dimension'):engine.embed(image=b'synthetic')
            args.model='wrong-model'
            with self.assertRaisesRegex(ValueError,'must declare'):Custom(args)
            args.custom_adapter=None
            with self.assertRaisesRegex(ValueError,'custom-adapter'):Custom(args)

    def test_address_checks_reject_credentials_and_unsupported_protocols(self):
        for url in ['file:///tmp/model','http://user:secret@localhost/v1','http://localhost/v1?key=secret']:
            with self.assertRaisesRegex(check.ServiceCheckError,'address'):check.check_service(url)

if __name__=='__main__':unittest.main()
