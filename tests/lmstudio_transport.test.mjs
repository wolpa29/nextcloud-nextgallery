import test from 'node:test';
import assert from 'node:assert/strict';
import {buildHistory, predictionConfig, finishReason} from '../nextcloud-app/media_ai/worker/lmstudio/protocol.mjs';

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
