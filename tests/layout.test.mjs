import test from 'node:test';
import assert from 'node:assert/strict';
import {layout,visible,localSummary} from '../frontend/layout.mjs';
test('100000 items keep a bounded visible window at every point',()=>{
 const groups=Array.from({length:120},(_,i)=>({key:String(i),count:i===119?800:833}));
 for(const width of [320,430,900,1600]){const l=layout(groups,width,180);
  for(const t of [0,l.height/2,l.height-700]){const rows=visible(l,t,700);assert(rows.length<80);assert(rows.some(r=>r.type==='row'));assert(rows.every(r=>r.y+r.height>=Math.max(0,t-400)-l.size));}
 }
});
test('month-only and year-only dates remain ordered without assigning a day',()=>{
 const items=[{id:1,kind:'image',date:{sort:'20240800000000',period:'2024-08-00'}},{id:2,kind:'video',date:{sort:'20240000000000',period:'2024-00-00'}},{id:3,kind:'image',date:{sort:'20240812140000',period:'2024-08-12'}}];
 let s=localSummary(items,{order:'asc',grouping:'month'});assert.deepEqual(s.groups,[{key:'2024-00',count:1},{key:'2024-08',count:2}]);assert.deepEqual(s.items.map(i=>i.id),[2,1,3]);
 s=localSummary(items,{order:'desc',grouping:'day'},'image');assert.equal(s.total,2);assert.deepEqual(s.items.map(i=>i.id),[3,1]);
});

test('native two to six column selection stays exact on phone and desktop',()=>{
 for(const width of [320,591,1280])for(const columns of [2,3,4,5,6]){const l=layout([{key:'2026-10',count:20000}],width,180,true,columns);assert.equal(l.columns,columns);assert(l.size>0);assert(visible(l,l.height/2,700).length<100);}
});
