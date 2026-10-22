import test from 'node:test';
import assert from 'node:assert/strict';
import {createTimelinePager} from '../frontend/viewer.mjs';

test('Timeline cursor crosses periods in both directions and skips revoked empty pages',async()=>{
 const calls=[];
 const pager=createTimelinePager({groups:[{key:'new',count:3},{key:'old',count:1}],period:'new',offset:0,itemId:3,seed:[{id:3},{id:2}],pageSize:2,
  request:async params=>{calls.push(params);return params.period==='new'?{items:[],more:false,next:null}:{items:[{id:1}],more:false,next:null};}});
 assert.equal((await pager.move(1)).id,2);assert.equal((await pager.move(1)).id,1);
 assert(!pager.canMove(1));assert.equal((await pager.move(-1)).id,2);assert.equal((await pager.move(-1)).id,3);assert(!pager.canMove(-1));
 assert.deepEqual(calls.map(p=>[p.period,p.offset]),[['new',2],['old',0]]);
});

test('Timeline cursor rejects non-advancing pages and discards late context responses',async()=>{
 let active=true,release;
 const pager=createTimelinePager({groups:[{key:'month',count:4}],period:'month',offset:0,itemId:4,seed:[{id:4}],pageSize:1,current:()=>active,
  request:()=>new Promise(resolve=>release=resolve)});
 const pending=pager.move(1);active=false;release({items:[{id:3}],more:true,next:2});assert.equal(await pending,null);
 assert.equal(await pager.move(1),null);
});
