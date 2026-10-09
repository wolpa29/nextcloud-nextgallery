import test from 'node:test';
import assert from 'node:assert/strict';
import {buildHistory, predictionConfig, finishReason, requestFailure} from '../nextcloud-app/media_ai/worker/lmstudio/protocol.mjs';
import {spawnSync} from 'node:child_process';
import net from 'node:net';

test('SDK service outages are classified without exposing endpoint or credentials', () => {
  for (const error of [{code:'ECONNREFUSED',message:'secret-token at private-host'},
    {cause:{code:'ENETUNREACH'}},{message:'WebSocket connection closed'}, {message:'Websocket timed out'}]) {
    const failure=requestFailure(error);
    assert.equal(failure.code,'service_unavailable');
    assert.doesNotMatch(JSON.stringify(failure),/secret-token|private-host/);
  }
  assert.equal(requestFailure(Error('context exceeded')).code,'inference_error');
});

test('real SDK connection refusal produces an unavailable event', async () => {
  const reservation=net.createServer();
  await new Promise(resolve=>reservation.listen(0,'127.0.0.1',resolve));
  try {
    // Bound but no longer listening: reserve the address by using a refused port
    // after closing this local listener, without contacting production LM Studio.
    const port=reservation.address().port;
    await new Promise(resolve=>reservation.close(resolve));
    const result=spawnSync(process.execPath,['nextcloud-app/media_ai/worker/lmstudio/stream.mjs'],{
      input:JSON.stringify({base_url:`ws://127.0.0.1:${port}`,payload:{model:'synthetic-test',messages:[]}}),
      encoding:'utf8',timeout:15000});
    assert.equal(result.status,1);
    const event=JSON.parse(result.stdout.split('\n').find(line=>line.startsWith('data:')).slice(6));
    assert.equal(event.error.code,'service_unavailable');
    assert.doesNotMatch(result.stdout,/127\.0\.0\.1|synthetic-test/);
  } finally {if(reservation.listening)reservation.close();}
});

test('ordered labels and all uploaded images form one history, including retry', async () => {
  const uploaded = [];
  const client = {files:{async prepareImage(path){uploaded.push(path);return {name:path,identifier:path,type:'image',sizeBytes:123};}}};
  const history = await buildHistory(client, [{role:'user',content:[
    {type:'text',text:'Overview'}, {type:'image_path',path:'whole.png'},
    {type:'text',text:'Tile at 0,0'}, {type:'image_path',path:'tile.png'}
  ]}, {role:'user',content:'Correct the JSON'}]);
  assert.deepEqual(uploaded,['whole.png','tile.png']);
  assert.deepEqual(history.messages[0].content.map(x=>x.text||x.identifier), ['Overview','whole.png','Tile at 0,0','tile.png']);
  assert.equal(history.messages[1].content[0].text,'Correct the JSON');
});

test('SDK disables dimension resizing, preserves schema and rejects context truncation', () => {
  const schema = {type:'object',properties:{text:{type:'string'}}};
  const config = predictionConfig({temperature:1,max_tokens:0,response_format:{json_schema:{schema}}});
  assert.equal(config.maxTokens,false);
  assert.equal(config.userMaxImageDimensionPixels,false);
  assert.equal(config.ignoreModelPreferredMaxImageDimension,true);
  assert.equal(config.contextOverflowPolicy,'stopAtLimit');
  assert.equal(config.structured.jsonSchema,schema);
});

test('only regular completion is treated as successful', () => {
  assert.equal(finishReason('eosFound'),'stop');
  assert.equal(finishReason('maxPredictedTokensReached'),'length');
  assert.equal(finishReason('contextLengthReached'),'contextLengthReached');
  assert.equal(finishReason('userStopped'),'userStopped');
});
