import test from 'node:test';
import assert from 'node:assert/strict';
import {progressiveSearch} from '../frontend/search.mjs';
const text={items:[{id:1}],more:false,next:1,mode:'lexical',embedding_state:'pending'};
const hybrid={...text,items:[{id:1},{id:2}],mode:'hybrid',embedding_state:'ready'};
const deferred=()=>{let resolve;const promise=new Promise(r=>resolve=r);return {promise,resolve};};

test('slow inference leaves text visible and usable before enrichment finishes',async()=>{
 const inference=deferred(),events=[];
 const runner=progressiveSearch({request:async p=>p.phase==='text'?text:inference.promise,show:async(r,meta)=>events.push([r,meta.stage])});
 const job=await runner.run({query:'dog',offset:0,limit:60});
 assert.deepEqual(events,[[text,'text']]);
 inference.resolve(hybrid);await job.finished;assert.deepEqual(events,[[text,'text'],[hybrid,'hybrid']]);
});
test('inference failure keeps the same text hits and signals fallback',async()=>{
 const events=[];const runner=progressiveSearch({request:async p=>{if(p.phase==='text')return text;throw new Error('offline');},show:async(r,m)=>events.push([r,m.stage])});
 await (await runner.run({query:'invoice'})).finished;
 assert.equal(events[1][1],'fallback');assert.equal(events[1][0].embedding_state,'unavailable');assert.deepEqual(events[1][0].items,text.items);
});
test('hung inference has a deadline even if the transport ignores cancellation',async()=>{
 const events=[];let signal;const runner=progressiveSearch({timeout:15,request:async(p,s)=>{signal=s;return p.phase==='text'?text:new Promise(()=>{});},show:async(r,m)=>events.push([r,m.stage])});
 await (await runner.run({query:'dog'})).finished;
 assert(signal.aborted);assert.equal(events.at(-1)[1],'fallback');assert.deepEqual(events.at(-1)[0].items,text.items);
});
test('late responses from old queries cannot overwrite a newer search',async()=>{
 const old=deferred(),events=[];let oldSignal;
 const runner=progressiveSearch({request:async(p,s)=>{if(p.phase==='text')return {...text,query:p.query};if(p.query==='old'){oldSignal=s;return old.promise;}return {...hybrid,query:p.query};},show:async(r)=>events.push(r.query)});
 const previous=await runner.run({query:'old'});const current=await runner.run({query:'new'});await current.finished;
 old.resolve({...hybrid,query:'old'});await previous.finished;
 assert(oldSignal.aborted);assert.deepEqual(events,['old','new','new']);
});
test('cancel on gallery/view change prevents both old phases from rendering',async()=>{
 const pending=deferred(),events=[];const runner=progressiveSearch({request:()=>pending.promise,show:r=>events.push(r)});
 const job=runner.run({query:'old'});runner.cancel();pending.resolve(text);await job;assert.deepEqual(events,[]);
 const background=deferred();const second=progressiveSearch({request:async p=>p.phase==='text'?text:background.promise,show:(r,m)=>events.push(m.stage)});
 const active=await second.run({query:'old'});second.cancel();await active.finished;background.resolve(hybrid);assert.deepEqual(events,['text']);
});
test('disabled, unindexed and empty-query text results never start inference',async()=>{
 for(const state of ['disabled','not_indexed',undefined]){const phases=[];const runner=progressiveSearch({request:async p=>{phases.push(p.phase);return {...text,embedding_state:state};},show:()=>{}});assert.equal(await runner.run({query:''}),undefined);assert.deepEqual(phases,['text']);}
});
test('both phases preserve gallery scope and pagination parameters',async()=>{
 const requests=[];const params={query:'beach',gallery:'personal',kind:'video',offset:60,limit:60};const runner=progressiveSearch({request:async p=>{requests.push(p);return p.phase==='text'?text:hybrid;},show:()=>{}});
 await(await runner.run(params)).finished;assert.deepEqual(requests,[{...params,phase:'text'},{...params,phase:'hybrid'}]);
});
test('a lexical error is reported without starting or fabricating semantic results',async()=>{
 const runner=progressiveSearch({request:async()=>{throw new Error('session expired');},show:()=>assert.fail('rendered unavailable lexical response')});
 await assert.rejects(runner.run({query:'dog'}),/session expired/);
});
