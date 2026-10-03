import json
import threading
import time
import tempfile
from pathlib import Path
from unittest.mock import patch
import unittest
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
import media_pipeline as p
from media_ai_stream import RepetitionGuard, StreamLoop


def frames(text, channel='content', finish='stop', done=True):
    result=[]
    for offset in range(0,len(text),7):
        result.append({'choices':[{'index':0,'delta':{channel:text[offset:offset+7]},'finish_reason':None}]})
    result.append({'choices':[{'index':0,'delta':{},'finish_reason':finish}]})
    result.append({'choices':[],'usage':{'prompt_tokens':10,'completion_tokens':20,'completion_tokens_details':{'reasoning_tokens':12}}})
    return result,done


class StreamingTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.responses=[];cls.calls=[];cls.write_counts=[]
        class Handler(BaseHTTPRequestHandler):
            def log_message(self,*args):pass
            def do_POST(self):
                payload=json.loads(self.rfile.read(int(self.headers['Content-Length'])))
                cls.calls.append(payload)
                response=cls.responses.pop(0)
                events,done=response[:2]
                delay=response[2] if len(response)>2 else 0
                if delay=='json':
                    self.send_response(200);self.send_header('Content-Type','application/json');self.end_headers()
                    self.wfile.write(json.dumps(events).encode());self.wfile.flush()
                    cls.write_counts.append(1)
                    return
                self.send_response(200);self.send_header('Content-Type','text/event-stream');self.end_headers()
                count=0
                try:
                    for event in events:
                        self.wfile.write(('data: '+json.dumps(event)+'\n\n').encode());self.wfile.flush();count+=1
                        if delay:time.sleep(delay)
                    if done:self.wfile.write(b'data: [DONE]\n\n');self.wfile.flush()
                except (BrokenPipeError,ConnectionResetError):pass
                finally:cls.write_counts.append(count)
        cls.server=ThreadingHTTPServer(('127.0.0.1',0),Handler)
        cls.thread=threading.Thread(target=cls.server.serve_forever,daemon=True);cls.thread.start()
        cls.url='http://127.0.0.1:'+str(cls.server.server_port)+'/v1'
    @classmethod
    def tearDownClass(cls):
        cls.server.shutdown();cls.server.server_close();cls.thread.join()
    def setUp(self):
        type(self).responses=[];type(self).calls=[];type(self).write_counts=[]
    def model(self,**extra):return p.Model({'url':self.url,'model':'test-model',**extra},'ocr')
    def test_json_empty_means_no_text_and_both_stages_have_schema(self):
        type(self).responses=[frames('{"text":""}')]
        model=self.model();self.assertEqual(model.analyze(b'x','image/jpeg')['text'],'')
        payload=self.calls[0]
        self.assertTrue(payload['stream']);self.assertTrue(payload['response_format']['json_schema']['strict'])
        self.assertEqual(payload['response_format']['json_schema']['schema']['required'],['text'])
        self.assertTrue(model.attempt_history[0]['empty_text'])
        self.assertNotIn('max_tokens',payload);self.assertNotIn('reasoning_effort',payload)
    def test_reasoning_loop_cancels_and_corrective_attempt_keeps_only_same_image(self):
        type(self).responses=[frames('Dies ist immer derselbe Gedanke. '*200,'reasoning_content'),frames('{"text":"Hallo"}')]
        model=self.model();events=[];model.observer=lambda event,**kw:events.append((event,kw))
        self.assertEqual(model.analyze(b'x','image/jpeg')['text'],'Hallo')
        self.assertEqual(len(self.calls),2);history=self.calls[1]['messages']
        self.assertEqual([m['role'] for m in history],['user','assistant','user'])
        self.assertIn('nur kurz',history[-1]['content'])
        self.assertNotIn('derselbe Gedanke',json.dumps(history))
        self.assertEqual(model.attempt_history[0]['channel'],'reasoning_content')
        self.assertLess(model.attempt_history[0]['reasoning_chars'],len('Dies ist immer derselbe Gedanke. '*200))
        self.assertTrue(any(e[0]=='inference_attempt_error' for e in events))
    def test_three_retries_max_and_next_image_has_fresh_context(self):
        type(self).responses=[frames('[unlesbar] '*50,'reasoning_content') for _ in range(4)]
        model=self.model()
        with self.assertRaisesRegex(p.PipelineError,'after 4 attempts'):model.analyze(b'x','image/jpeg')
        self.assertEqual(len(self.calls),4)
        type(self).responses=[frames('{"text":"Neu"}')]
        model.analyze(b'new','image/jpeg')
        self.assertEqual(len(self.calls[-1]['messages']),1)
        self.assertEqual(len(model.attempt_history),1)
    def test_truncated_json_finish_length_and_missing_done_never_accepted(self):
        for response in (frames('{"text":'),frames('{"text":""}',finish='length'),frames('{"text":""}',done=False)):
            type(self).responses=[response]
            with self.assertRaises(p.PipelineError):self.model(loop_retries=0).analyze(b'x','image/jpeg')
    def test_ocr_schema_rejects_non_json_extra_fields_null_and_duplicate_keys(self):
        for text in ('<NO_TEXT>','{"text":null}','{"text":"", "extra":1}','{"text":"a", "text":"b"}'):
            type(self).responses=[frames(text)]
            with self.assertRaises(p.PipelineError):self.model(loop_retries=0).analyze(b'x','image/jpeg')

    def test_nonstreaming_setting_and_fallback_validate_result_and_reasoning(self):
        response={'choices':[{'finish_reason':'stop','message':{'content':'{"text":""}'}}]}
        type(self).responses=[(response,True,'json')]
        m=self.model(stream=False,loop_retries=0)
        self.assertEqual(m.analyze(b'x','image/jpeg')['text'],'')
        self.assertFalse(self.calls[0]['stream']);self.assertNotIn('stream_options',self.calls[0])
        self.assertFalse(m.attempt_history[0]['streamed'])
        response={'choices':[{'finish_reason':'stop','message':{'content':'{"text":"OK"}','reasoning_content':'Warnung '*30}}]}
        type(self).responses=[(response,True,'json')]
        with self.assertRaises(p.PipelineError):m.analyze(b'x','image/jpeg')
        self.assertEqual(m.attempt_history[0]['state'],'loop')

    def test_malformed_nonstreaming_responses_are_logged_as_errors(self):
        for response in ({'choices':'bad'},{'choices':[]},{'choices':[None]},{'choices':[{'message':'bad'}]},None,[],{'choices':[{'finish_reason':'stop','message':{'content':'{"text":""}'}}],'usage':'bad'}):
            type(self).responses=[(response,True,'json')]
            m=self.model(loop_retries=0)
            with self.assertRaises(p.PipelineError):m.analyze(b'x','image/jpeg')
            self.assertEqual(m.attempt_history[0]['state'],'invalid')
    def test_final_content_loop_is_rejected_during_stream(self):
        type(self).responses=[frames(json.dumps({'text':'derselbe lange Satz. '*100}))]
        model=self.model(loop_retries=0)
        with self.assertRaises(p.PipelineError):model.analyze(b'x','image/jpeg')
        self.assertEqual(model.attempt_history[0]['channel'],'content')
    def test_healthy_long_reasoning_and_normal_numbered_steps_are_allowed(self):
        guard=RepetitionGuard(5)
        text='\n'.join(f'Schritt {n}: die unterschiedliche Aufgabe lautet {n*n} und weitere Details {n+9}.' for n in range(1000))
        for i in range(0,len(text),11):guard.feed(text[i:i+11])
        guard.feed('',final=True)
        guard=RepetitionGuard();guard.feed('JUSB1 JUSB2 JUSB3 JUSB4 JUSB5 JUSB6 JUSB7 JUSB8 ',final=True)
    def test_guard_recognizes_numbered_runaway_and_split_tokens(self):
        for text in ('[unlesbar] '*5,'Warnung '*5,'testwort'*20,'Dies ist derselbe Satz. '*5,' '.join(f'JUSB{n}' for n in range(1,100))):
            guard=RepetitionGuard(5)
            with self.assertRaises(StreamLoop):
                for c in text:guard.feed(c)
                guard.feed('',final=True)

    def test_slow_server_observes_connection_closed_before_finishing_loop(self):
        events,done=frames('Dies ist ein endloser Gedanke. '*300,'reasoning_content')
        type(self).responses=[(events,done,0.001)]
        start=time.monotonic()
        with self.assertRaises(p.PipelineError):self.model(loop_retries=0).analyze(b'x','image/jpeg')
        self.assertLess(time.monotonic()-start,1)
        for _ in range(100):
            if self.write_counts:break
            time.sleep(0.005)
        self.assertTrue(self.write_counts)
        self.assertLess(self.write_counts[0],len(events)//4)

    def test_configurable_threshold_and_custom_correction(self):
        type(self).responses=[frames('Derselbe lange Gedanke. '*8,'reasoning_content'),frames('{"text":"OK"}')]
        m=self.model(loop_repetitions=7,loop_retries=1,temperature=0.2,retry_temperature=1.0,retry_prompt='Nur dieses Bild nochmals als JSON.')
        self.assertEqual(m.analyze(b'x','image/jpeg')['text'],'OK')
        self.assertIn('7 Mal',m.attempt_history[0]['error'])
        self.assertEqual(self.calls[0]['temperature'],0.2);self.assertEqual(self.calls[1]['temperature'],1.0)
        self.assertEqual(self.calls[1]['messages'][-1]['content'],'Nur dieses Bild nochmals als JSON.')
        guard=RepetitionGuard(numeric_repetitions=60)
        guard.feed(' '.join(f'JUSB{n}' for n in range(1,60))+' ')
        with self.assertRaises(StreamLoop):guard.feed('JUSB60 ')

    def test_reasoning_json_workaround_requires_complete_schema_and_normal_finish(self):
        for text,finish,valid in [('{"text":""}','stop',True),('{"text":"OK"}','stop',True),('I think {"text":"OK"}','stop',False),('{"text":','stop',False),('{"text":"OK"}','length',False)]:
            type(self).responses=[frames(text,'reasoning_content',finish)]
            m=self.model(loop_retries=0,allow_reasoning_json=True)
            if valid:
                m.analyze(b'x','image/jpeg');self.assertEqual(m.output_channel,'reasoning_json_workaround')
            else:
                with self.assertRaises(p.PipelineError):m.analyze(b'x','image/jpeg')
        type(self).responses=[frames('{"text":""}','reasoning_content')]
        with self.assertRaises(p.PipelineError):self.model(loop_retries=0).analyze(b'x','image/jpeg')

    def test_invalid_stream_shapes_tools_and_missing_finish_rejected(self):
        cases=[([{'choices':'wrong'}],True),([{'choices':[{'delta':[]}]}],True),([{'choices':[{'delta':{'content':42}}]}],True),([{'error':{'message':'bad'}}],True),([{'choices':[{'delta':{'content':'{"text":""}','tool_calls':[{}]},'finish_reason':'stop'}]}],True),([{'choices':[{'delta':{'content':'{"text":""}'}}]}],True)]
        # An empty delta list is equivalent to no delta; it still lacks finish/content.
        for response in cases:
            type(self).responses=[response]
            with self.assertRaises(p.PipelineError):self.model(loop_retries=0).analyze(b'x','image/jpeg')

    def test_recovered_pipeline_publishes_once_and_rerun_does_not_reinfer(self):
        from test_pipeline import Source,Model,Publisher
        type(self).responses=[frames('Derselbe lange Gedanke. '*50,'reasoning_content'),frames('{"text":""}')]
        m=self.model();m.health=lambda:None
        with tempfile.TemporaryDirectory() as directory:
            store=p.Store(Path(directory)/'state.sqlite3');source=Source();publisher=Publisher();events=[]
            models={'vision':Model('vision'),'ocr':m}
            try:
                report=p.run({},store,source,models,publisher=publisher,observe=lambda ev,**fields:events.append((ev,fields)))
                self.assertEqual(report['published'],1);self.assertEqual(report['stats'],{'done':1})
                self.assertEqual(len(publisher.sent),1)
                self.assertEqual(publisher.sent[0]['provenance']['inference_attempts']['ocr'][0]['state'],'loop')
                self.assertNotIn('Derselbe lange Gedanke',json.dumps(events))
                self.assertTrue(any(ev=='inference_attempt_error' and fields['file_id']=='a' and fields['stage']=='ocr' for ev,fields in events))
                report=p.run({},store,source,models,publisher=publisher)
                self.assertEqual(report['processed'],0);self.assertEqual(len(self.calls),2)
            finally:store.db.close()

    def test_failed_attempts_never_cached_or_published_and_valid_vision_can_retry(self):
        from test_pipeline import Source,Model,Publisher
        type(self).responses=[frames('[unlesbar] '*50,'reasoning_content') for _ in range(4)]
        m=self.model();m.health=lambda:None
        with tempfile.TemporaryDirectory() as directory:
            store=p.Store(Path(directory)/'state.sqlite3');publisher=Publisher();vision=Model('vision');models={'vision':vision,'ocr':m};source=Source()
            try:
                report=p.run({},store,source,models,publisher=publisher)
                self.assertEqual(report['stats'],{'error':1});self.assertEqual(publisher.sent,[])
                self.assertEqual(store.db.execute("SELECT count(*) FROM results WHERE stage='ocr'").fetchone()[0],0)
                self.assertEqual(store.db.execute('SELECT count(*) FROM search').fetchone()[0],0)
                store.db.execute('UPDATE files SET retry_at=0');store.db.commit()
                type(self).responses=[frames('{"text":"OK"}')]
                report=p.run({},store,source,models,publisher=publisher)
                self.assertEqual(report['published'],1);self.assertEqual(vision.calls,1)
            finally:store.db.close()

    def test_loop_with_changing_last_word_is_rejected(self):
        text='\n'.join('Please do not use the battery if it is not properly '+word+'.' for word in ['aligned','connected','formatted','encrypted','erased','flushed','packed'])
        for wire in [text,json.dumps({'text':text})]:
            guard=RepetitionGuard(5)
            with self.assertRaisesRegex(StreamLoop,'Satz'):
                for pos in range(0,len(wire),3):guard.feed(wire[pos:pos+3])
                guard.feed('',final=True)

    def test_repeated_long_phrase_with_changing_start_and_end_is_rejected(self):
        text='\n'.join(f'{word} Ein wiederholter sehr langer Abschnitt mit denselben sichtbaren Woertern endet hier anders{word}.' for word in ['alpha','bravo','charlie','delta','echo','foxtrot'])
        for wire in [text,json.dumps({'text':text})]:
            guard=RepetitionGuard()
            with self.assertRaisesRegex(StreamLoop,'Satzteil'):
                for pos in range(0,len(wire),3):guard.feed(wire[pos:pos+3])
                guard.feed('',final=True)
        guard=RepetitionGuard(); healthy=[]
        for n in range(6):
            healthy.append('Ein wiederholter langer Referenzsatz mit denselben sichtbaren Woertern.')
            healthy.extend(f'Anderer Schritt {j+n*30}: unterschiedliches Ergebnis {j*j+n}.' for j in range(30))
        guard.feed('\n'.join(healthy),final=True)

    def test_numeric_tables_and_real_terminal_templates_are_not_fuzzy_loops(self):
        table='\n'.join(f'Mo {n:02d}.06.2026 7013 GLAZ TZ Wochentag frei 00:00h GLAZ ohne KZ' for n in range(1,25))
        terminal='\n'.join(f'{n}: enp{n}s0 BROADCAST MULTICAST UP LOWER_UP mtu 1500 qdisc mq state UP mode DEFAULT group default qlen 1000' for n in range(1,10))
        for text in [table,terminal]:
            RepetitionGuard().feed(text,final=True)
            p.assert_no_repetition(text,'OCR')

    def test_vision_has_short_description_prompt_and_twenty_search_term_limit(self):
        from test_pipeline import VISION
        m=p.Model({'url':self.url,'model':'test','loop_retries':0},'vision')
        self.assertIn('1 bis 2 Saetzen',m.prompt);self.assertIn('20 unterschiedliche',m.prompt)
        result={**VISION,'tags':[f'Begriff{n}' for n in range(21)],'objects':[]}
        type(self).responses=[frames(json.dumps(result))]
        with self.assertRaises(p.PipelineError):m.analyze(b'x','image/jpeg')
