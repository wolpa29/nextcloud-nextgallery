// Disposable synthetic Nextcloud only. Delays/outages are simulated, never private media.
import assert from 'node:assert/strict';
import {mkdir,writeFile} from 'node:fs/promises';
const {chromium}=await import(process.env.PLAYWRIGHT_MODULE||'playwright');
const origin='http://127.0.0.1:8782',base=origin+'/index.php/apps/nextgallery',out='artifacts/progressive-search-ui';
await mkdir(out,{recursive:true});
const browser=await chromium.launch({headless:true,...(process.env.PLAYWRIGHT_EXECUTABLE?{executablePath:process.env.PLAYWRIGHT_EXECUTABLE}:{})});
const context=await browser.newContext({viewport:{width:1360,height:900}}),page=await context.newPage();
page.setDefaultTimeout(15000);page.setDefaultNavigationTimeout(30000);
const checks=[],errors=[],shots=[],pending=new Map();let mode='slow',hybridCalls=0;
const check=(v,label)=>{assert(v,label);checks.push(label);console.log(label);};
page.on('pageerror',e=>errors.push(e.message));
try{
 await page.goto(origin+'/login');await page.locator('#user').fill('merge-synthetic');await page.locator('#password').fill('SyntheticMergeOnly2026!');await page.locator('form').evaluate(f=>f.requestSubmit());await page.waitForURL(u=>!u.pathname.endsWith('/login'));
 await page.goto(base+'/gallery');await page.locator('#ch-count').waitFor();
 const wizard=page.locator('#firstrunwizard[role=dialog]');if(await wizard.isVisible())await wizard.getByRole('button',{name:/close|schließen/i}).first().click();
 const items=await page.evaluate(async()=> (await(await fetch(OC.generateUrl('/apps/nextgallery/api/items')+'?gallery=default&limit=60')).json()).items);
 check(items.length>=2,'Synthetic image fixtures exist');const [first,second]=items;
 const lexical={items:[first],next:1,more:false,mode:'lexical',embedding_state:'pending'};
 const enriched={items:[second,first],next:2,more:false,mode:'hybrid',embedding_state:'ready'};
 await page.route('**/api/search?**',async route=>{
  const url=new URL(route.request().url()),phase=url.searchParams.get('phase'),query=url.searchParams.get('query');
  if(phase==='text')return route.fulfill({json:{...lexical,items:mode==='empty'?[]:[first],embedding_state:mode==='disabled'?'disabled':'pending'}});
  hybridCalls++;
  if(mode==='offline')return route.fulfill({json:{...lexical,embedding_state:'unavailable'}});
  await new Promise(resolve=>pending.set(query,resolve));
  try{await route.fulfill({json:{...enriched,items:query==='old'?[second]:enriched.items}});}catch{} // cancelled requests are intentional
 });
 await page.locator('[data-view=search]').first().click();
 async function submit(query){await page.locator('#ch-search-query').fill(query);await page.locator('#ch-search-form').evaluate(f=>f.requestSubmit());}
 async function ready(){await page.waitForFunction(()=>/Bildsuche läuft|Searching image/.test(document.getElementById('ch-search-status').textContent));}
 async function snap(name){await page.screenshot({path:out+'/'+name+'.png',animations:'disabled'});shots.push(name);check(await page.locator('#nextgallery').evaluate(e=>e.scrollWidth<=e.clientWidth+1),name+' fits viewport');}
 await submit('slow');await ready();check(await page.locator('.ch-search-tile').count()===1,'Text visible before delayed model completes');check(pending.has('slow'),'Model request pending while text is visible');await page.locator('.ch-search-tile').first().focus();await snap('text-ready-image-pending-desktop');
 pending.get('slow')();await page.waitForFunction(()=>document.querySelectorAll('.ch-search-tile').length===2);check(await page.locator('.ch-search-tile').last().evaluate(e=>document.activeElement===e),'Focused text tile survives enrichment/reranking');check(new Set(await page.locator('.ch-search-tile').evaluateAll(es=>es.map(e=>e.dataset.id))).size===2,'Hybrid results contain no duplicates');await snap('hybrid-ready-desktop');
 await page.locator('.ch-search-tile').last().click();await page.locator('#ch-viewer').waitFor();check(await page.locator('#ch-viewer-name').count()===0||await page.locator('#ch-viewer').isVisible(),'Text hit opens viewer');await page.keyboard.press('Escape');
 await submit('old');await ready();await submit('new');await ready();pending.get('new')();await page.waitForFunction(()=>document.querySelectorAll('.ch-search-tile').length===2);pending.get('old')();await page.waitForTimeout(80);check(await page.locator('.ch-search-tile').count()===2,'Late old response cannot overwrite new results');
 mode='offline';await submit('offline');await page.waitForFunction(()=>/nicht erreichbar|not reachable/.test(document.getElementById('ch-search-status').textContent));check(await page.locator('.ch-search-tile').count()===1,'Offline inference retains text');await snap('image-offline-desktop');
 mode='empty';await submit('empty');await ready();check(await page.locator('.ch-search-tile').count()===0,'No text matches does not announce final failure while inference is pending');pending.get('empty')();await page.waitForFunction(()=>document.querySelectorAll('.ch-search-tile').length===2);check(await page.locator('.ch-search-tile').count()===2,'Semantic-only results arrive after empty lexical response');
 mode='disabled';const before=hybridCalls;await submit('disabled');await page.waitForFunction(()=>document.querySelectorAll('.ch-search-tile').length===1&&!/läuft|Searching image/.test(document.getElementById('ch-search-status').textContent));await page.waitForTimeout(80);check(hybridCalls===before,'Disabled embedding makes only the lexical request');
 mode='slow';await page.setViewportSize({width:390,height:844});await submit('mobile');await ready();await snap('text-ready-image-pending-mobile');pending.get('mobile')();await page.waitForFunction(()=>document.querySelectorAll('.ch-search-tile').length===2);await snap('hybrid-ready-mobile');
 await page.setViewportSize({width:320,height:700});await submit('leaving');await ready();await snap('text-ready-image-pending-small');await page.locator('[data-view=timeline]').first().click();pending.get('leaving')();await page.waitForTimeout(80);await page.locator('[data-view=search]').first().click();check(await page.locator('.ch-search-tile').count()===1,'Leaving search cancels background enrichment');
 // Actual default 22-second browser deadline; transport stays hung beyond it.
 await submit('hung');await ready();await page.waitForFunction(()=>/nicht erreichbar|not reachable/.test(document.getElementById('ch-search-status').textContent),null,{timeout:25000});check(await page.locator('.ch-search-tile').count()===1,'Real UI deadline retains clickable text');pending.get('hung')();
 check(!errors.length,'No browser errors');await writeFile(out+'/result.json',JSON.stringify({checks:checks.length,labels:checks,errors,shots,synthetic:true,model_delay_outage:'simulated'},null,2));console.log(JSON.stringify({checks:checks.length,screens:shots.length,errors}));
}catch(error){await page.screenshot({path:out+'/failure.png'});console.error(await page.locator('#ch-search-status').textContent().catch(()=>''));console.error(errors);throw error;}finally{for(const resolve of pending.values())resolve();await browser.close();}
