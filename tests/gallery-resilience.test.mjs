import test from 'node:test';
import assert from 'node:assert/strict';
import {galleryFixture,savedCollection,seedCollection} from './helpers/gallery-browser.mjs';

test('Timeline keeps loaded photos during a slow refresh and after its failure',async t=>{
 const f=await galleryFixture(t);if(!f)return;const{page,state}=f;
 let release;state.holdSummary=()=>new Promise(resolve=>release=resolve);
 await page.locator('#ch-refresh').click();await page.waitForFunction(()=>document.getElementById('ch-scroll').getAttribute('aria-busy')==='true');
 assert.equal(await page.locator('.ch-tile[data-id]').count(),9);
 state.failSummary=true;state.holdSummary=null;release();
 await page.locator('#ch-message').getByText(/Bisheriger Stand/).waitFor();
 assert.equal(await page.locator('.ch-tile[data-id]').count(),9);
 state.failSummary=false;await page.locator('#ch-message button').click();
 await page.waitForFunction(()=>document.getElementById('ch-scroll').getAttribute('aria-busy')==='false');
 await page.locator('.ch-tile[data-id]').first().waitFor();
 assert.equal(await page.locator('.ch-tile[data-id]').count(),9);
});

test('Failed timeline pages provide retry and can recover on returning to the view',async t=>{
 const f=await galleryFixture(t);if(!f)return;const{page,state}=f;
 state.failItems=true;await page.locator('#ch-refresh').click();await page.locator('.ch-tile[data-error]').first().waitFor();
 state.failItems=false;await page.locator('.ch-tile[data-error]').first().click();await page.locator('.ch-tile[data-id]').first().waitFor();
 assert.equal(await page.locator('.ch-tile[data-id]').count(),9);
 state.failItems=true;await page.locator('#ch-refresh').click();await page.locator('.ch-tile[data-error]').first().waitFor();
 state.failItems=false;await page.locator('[data-view=offline]').first().click();await page.locator('[data-view=timeline]').first().click();
 await page.locator('.ch-tile[data-id]').first().waitFor();assert.equal(await page.locator('.ch-tile[data-id]').count(),9);
});

for(const failure of ['items','preview','cancel'])test(`Offline ${failure} failure retains the complete previously saved collection`,async t=>{
 const f=await galleryFixture(t);if(!f)return;const{page,state,offlineModule}=f;
 await seedCollection(page,offlineModule);const before=await savedCollection(page,offlineModule);
 if(failure==='items')state.failItems=true;
 if(failure==='preview')state.failPreview=true;
 let held;const firstHeld=new Promise(resolve=>held=resolve),releases=[];if(failure==='cancel')state.holdPreview=()=>{held();return new Promise(resolve=>releases.push(resolve));};
 await page.locator('[data-view=offline]').first().click();await page.locator('#ch-pin').click();
 if(failure==='cancel'){
  await firstHeld;await page.locator('#ch-pin-cancel').click();state.holdPreview=null;for(const release of releases)release();
 }
 await page.waitForFunction(()=>document.getElementById('ch-offline-progress').hidden);
 assert.deepEqual(await savedCollection(page,offlineModule),before);
 assert.match(await page.locator('#ch-message').innerText(),/bisherige Sammlung bleibt erhalten/);
});

test('Successful offline update atomically replaces the previous collection at the selected limit',async t=>{
 const f=await galleryFixture(t);if(!f)return;const{page,offlineModule}=f;
 await seedCollection(page,offlineModule);
 await page.locator('[data-view=offline]').first().click();await page.locator('#ch-offline-limit').fill('2');await page.locator('#ch-pin').click();
 await page.waitForFunction(()=>!document.getElementById('ch-export').disabled);
 const saved=await savedCollection(page,offlineModule);
 assert.equal(saved.meta.count,2);assert.deepEqual(saved.ids,[8,9]);assert(saved.blobs.every(size=>size>0));
});

test('An interrupted publication rolls back items, metadata and blobs, and removal invalidates staging',async t=>{
 const f=await galleryFixture(t);if(!f)return;const{page,offlineModule}=f;
 await seedCollection(page,offlineModule);const before=await savedCollection(page,offlineModule);
 const result=await page.evaluate(async module=>{
  const store=await import(module),db=await store.openStore('synthetic-tests'),stage=await store.beginCollection(db);
  await store.stageItem(db,stage,{id:3},new Blob(['new']));
  let aborted=false;try{await store.commitCollection(db,stage,{count:2});}catch{aborted=true;}
  await store.discardCollection(db,stage);db.close();return aborted;
 },offlineModule);
 assert(result);assert.deepEqual(await savedCollection(page,offlineModule),before);
 assert(await page.evaluate(async module=>{
  const store=await import(module),db=await store.openStore('synthetic-tests'),stage=await store.beginCollection(db);
  await store.stageItem(db,stage,{id:3},new Blob(['new']));await store.clear(db);
  try{await store.commitCollection(db,stage,{count:1});return false;}catch{return (await store.readCollection(db)).length===0;}finally{db.close();}
 },offlineModule));
});

test('Replacing overlapping blob IDs retains every new preview and invalidates a concurrent older stage',async t=>{
 const f=await galleryFixture(t);if(!f)return;const{page,offlineModule}=f;
 assert.deepEqual(await page.evaluate(async module=>{
  const store=await import(module),db=await store.openStore('synthetic-tests');
  await store.saveItem(db,{id:1},new Blob(['old-one']));await store.saveItem(db,{id:3},new Blob(['old-three']));
  const stage=await store.beginCollection(db),other=await store.beginCollection(db);
  for(const id of [2,3,4])await store.stageItem(db,stage,{id},new Blob(['new-'+id]));
  await store.stageItem(db,other,{id:9},new Blob(['obsolete']));
  await store.commitCollection(db,stage,{count:3});
  let staleRejected=false;try{await store.commitCollection(db,other,{count:1});}catch{staleRejected=true;}
  const items=await store.readCollection(db),blobs=await Promise.all(items.map(async item=>(await store.readBlob(db,item.id)).text()));
  await store.discardCollection(db,other);db.close();return {ids:items.map(item=>item.id),blobs,staleRejected};
 },offlineModule),{ids:[2,3,4],blobs:['new-2','new-3','new-4'],staleRejected:true});
});

test('Offline quota failures and reopening an abandoned download preserve the active collection',async t=>{
 const f=await galleryFixture(t);if(!f)return;const{page,offlineModule}=f;
 await seedCollection(page,offlineModule);const before=await savedCollection(page,offlineModule);
 assert(await page.evaluate(async module=>{
  const store=await import(module);let db=await store.openStore('synthetic-tests');
  const stage=await store.beginCollection(db);await store.stageItem(db,stage,{id:2},new Blob(['staged']));db.close();
  db=await store.openStore('synthetic-tests');
  const put=IDBObjectStore.prototype.put;let failed=false;
  IDBObjectStore.prototype.put=function(...args){if(this.name==='blobs'&&Array.isArray(args[1]))throw new DOMException('Synthetic full disk','QuotaExceededError');return put.apply(this,args);};
  try{await store.stageItem(db,stage,{id:3},new Blob(['overflow']));}catch{failed=true;}finally{IDBObjectStore.prototype.put=put;}
  await store.discardCollection(db,stage);db.close();return failed;
 },offlineModule));
 assert.deepEqual(await savedCollection(page,offlineModule),before);
});

test('An app upgrade keeps schema-one collections readable by the previously saved offline shell',async t=>{
 const f=await galleryFixture(t);if(!f)return;const{page,offlineModule}=f;
 assert.deepEqual(await page.evaluate(async module=>{
  const old=await new Promise(resolve=>{const r=indexedDB.open('nextgallery-legacy-test',1);r.onupgradeneeded=()=>{r.result.createObjectStore('items',{keyPath:'id'});r.result.createObjectStore('blobs');r.result.createObjectStore('meta');};r.onsuccess=()=>resolve(r.result);});
  await new Promise(resolve=>{const t=old.transaction(['items','blobs','meta'],'readwrite');t.objectStore('items').put({id:7,name:'Legacy'});t.objectStore('blobs').put(new Blob(['legacy']),7);t.objectStore('meta').put({count:1},'collection');t.oncomplete=resolve;});old.close();
  const store=await import(module),db=await store.openStore('legacy-test');
  const result={version:db.version,items:await store.readCollection(db),meta:await store.readMeta(db),blob:await(await store.readBlob(db,7)).text()};db.close();return result;
 },offlineModule),{version:1,items:[{id:7,name:'Legacy'}],meta:{count:1},blob:'legacy'});
});

test('Viewer loads the next timeline page instead of stopping at the 150th photo',async t=>{
 const f=await galleryFixture(t,{total:500});if(!f)return;const{page,state}=f;
 await page.locator('.ch-tile[data-id]').first().click();
 for(let i=0;i<151;i++){
  await page.locator('#ch-viewer-next').click();
  await page.waitForFunction(()=>document.getElementById('ch-viewer-media').getAttribute('aria-busy')!=='true');
 }
 assert.equal(await page.locator('#ch-viewer-name').innerText(),'Synthetic 349.png');
 assert(state.requests.some(path=>path.includes('/api/items?')&&path.includes('offset=150')));
 await page.locator('#ch-viewer-prev').click();await page.waitForFunction(()=>document.getElementById('ch-viewer-name').textContent==='Synthetic 350.png');
});

test('Mobile introduction stays compact, can be dismissed, and survives reload without hiding processing',async t=>{
 const f=await galleryFixture(t,{width:320,height:844});if(!f)return;const{page}=f;
 assert((await page.locator('#ch-ai-feature').boundingBox()).height<95);
 assert(await page.locator('#ch-ai-link').isVisible());
 await page.locator('#ch-ai-hint-dismiss').click();assert(!await page.locator('#ch-ai-feature').isVisible());
 await page.reload();await page.locator('.ch-tile[data-id]').first().waitFor();
 assert(!await page.locator('#ch-ai-feature').isVisible());assert(await page.locator('#ch-ai-link').isVisible());
 for(const width of [320,390,768,1360]){
  await page.setViewportSize({width,height:844});
  assert(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth));
 }
});

test('Enlarged text reflows mobile navigation into usable rows',async t=>{
 const f=await galleryFixture(t,{width:320,height:844});if(!f)return;const{page}=f;
 await page.evaluate(()=>{const values=[...document.querySelectorAll('#nextgallery,#nextgallery *')].map(node=>[node,parseFloat(getComputedStyle(node).fontSize)]);for(const[node,size]of values)node.style.fontSize=size*2+'px';});
 const controls=page.locator('.ch-sidebar button,.ch-sidebar #ch-ai-link');
 const boxes=await controls.evaluateAll(nodes=>nodes.filter(el=>el.getBoundingClientRect().height).map(el=>({top:el.getBoundingClientRect().top,bottom:el.getBoundingClientRect().bottom,width:el.getBoundingClientRect().width})));
 assert(new Set(boxes.map(box=>Math.round(box.top))).size>=2);
 assert(boxes.every(box=>box.width>=90&&box.bottom<=844));
 assert((await page.locator('#ch-title').boundingBox()).y>=0);
 assert(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth));
});

test('The map stops downloading pages when hidden and resumes without duplicate requests',async t=>{
 const f=await galleryFixture(t,{total:500});if(!f)return;const{page,state}=f;
 state.items.forEach((item,i)=>item.metadata.gps=[48+(i%10)*.01,16+(i%20)*.01]);
 let held;const requested=new Promise(resolve=>held=resolve);let release;
 state.holdMap=()=>{held();return new Promise(resolve=>release=resolve);};
 await page.locator('[data-view=map]').first().click();await requested;
 await page.locator('[data-view=timeline]').first().click();state.holdMap=null;release();
 await page.waitForFunction(()=>document.getElementById('ch-map-count').textContent.includes('200'));
 assert.equal(state.requests.filter(path=>path.includes('/api/map?')).length,1);
 await page.locator('[data-view=map]').first().click();
 await page.waitForFunction(()=>document.getElementById('ch-map-count').textContent.includes('500'));
 assert.deepEqual(state.requests.filter(path=>path.includes('/api/map?')).map(path=>new URL('https://gallery.test'+path).searchParams.get('offset')),['0','200','400']);
});
