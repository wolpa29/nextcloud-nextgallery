import {readFileSync} from 'node:fs';
import {spawnSync} from 'node:child_process';
import {resolve} from 'node:path';
import {build} from 'esbuild';

export const ROOT = resolve(new URL('../..', import.meta.url).pathname);
let playwright, chromium, firefox, webkit;
try {
 playwright = await import(process.env.PLAYWRIGHT_MODULE || 'playwright');
 ({chromium, firefox, webkit} = playwright);
} catch (error) {
 if (process.env.REQUIRE_BROWSER_TESTS === '1') throw error;
}
const bundle = (await build({absWorkingDir:ROOT,entryPoints:['frontend/main.js'],bundle:true,write:false,format:'iife'})).outputFiles[0].text;
const mapBundle = (await build({absWorkingDir:ROOT,entryPoints:['frontend/map.js'],bundle:true,write:false,format:'iife'})).outputFiles[0].text;
const offlineModule = 'data:text/javascript;base64,' + Buffer.from(readFileSync(ROOT+'/frontend/offline.mjs')).toString('base64');

let shell;
function renderShell() {
 if (shell) return shell;
 const phpString = text => "'" + text.replaceAll('\\', '\\\\').replaceAll("'", "\\'") + "'";
 const source = readFileSync(ROOT+'/templates/shell.php','utf8')
  .replace("file_get_contents(__DIR__.'/en.json')",phpString(readFileSync(ROOT+'/frontend/en.json','utf8')))
  .replaceAll("include __DIR__.'/display-options.php';",'?>'+readFileSync(ROOT+'/templates/display-options.php','utf8')+'<?php ');
 const preamble = `<?php function p($text){echo htmlspecialchars((string)$text,ENT_QUOTES,'UTF-8');} $_=['context'=>['language'=>'de','version'=>'test','settings'=>['appearance'=>'light'],'aiUrl'=>'/apps/nextgallery/processing']]; ?>`;
 const command = JSON.parse(process.env.NEXTGALLERY_TEST_PHP || '["php"]');
 const result = spawnSync(command[0],command.slice(1),{input:preamble+source,encoding:'utf8'});
 if (result.error || result.status !== 0) throw result.error || Error(result.stderr || result.stdout);
 if (/Warning:|Fatal error:/.test(result.stdout)) throw Error(result.stdout);
 return shell = result.stdout;
}

export async function galleryFixture(t,{total=9,width=1100,height=850,items=null,browserName=process.env.PLAYWRIGHT_BROWSER||'chromium'}={}) {
 const engine = browserName === 'firefox' ? firefox : (browserName === 'webkit' ? webkit : chromium);
 if (!engine) { t.skip(`${browserName} unavailable; the required browser CI job installs it`); return; }
 const launchOptions = {headless:true};
 if (browserName === 'chromium') {
  launchOptions.executablePath = process.env.PLAYWRIGHT_EXECUTABLE || undefined;
  launchOptions.args = ['--no-sandbox'];
 } else if (browserName === 'firefox') {
  launchOptions.executablePath = process.env.PLAYWRIGHT_FIREFOX_EXECUTABLE || undefined;
 } else if (browserName === 'webkit') {
  launchOptions.executablePath = process.env.PLAYWRIGHT_WEBKIT_EXECUTABLE || undefined;
 }
 let browser;
 try {
  browser = await engine.launch(launchOptions);
 } catch (error) {
  if (process.env.REQUIRE_BROWSER_TESTS === '1') throw error;
  t.skip(`${browserName} launch failed: ${error.message}`);
  return;
 }
 t.after(()=>browser.close());
 const page = await browser.newPage({viewport:{width,height}});
 page.setDefaultTimeout(6000);
 const errors=[];page.on('pageerror',error=>errors.push(error.message));
 t.after(()=>{if(errors.length)throw Error(errors.join('\n'));});
 const settings={roots:['/Synthetic'],mode:'folder',grouping:'month',order:'desc',tile:180,timezone:'Europe/Vienna',offlineLimit:1000,appearance:'light',language:'de',activeGallery:'default',galleries:[{id:'default',name:'Synthetische Galerie',roots:['/Synthetic']},{id:'other',name:'Andere Galerie',roots:['/Other']}]};
 const state={settings,failSummary:false,failItems:false,failPreview:false,holdPreview:null,holdSummary:null,requests:[],items:items||Array.from({length:total},(_,i)=>({id:i+1,name:`Synthetic ${i+1}.png`,path:`/Synthetic/${i+1}.png`,etag:'synthetic',kind:'image',mtime:1,date:{date:'2026-10-20',sort:'2026-10-20',period:'2026-10-20',source:'filename',precision:'day'},metadata:{}}))};
 const context={base:'/apps/nextgallery',assets:'/apps/nextgallery/',cacheKey:'synthetic-tests',accountKey:'synthetic-account',version:'test',settings,language:'de',offline:false,aiUrl:'/apps/nextgallery/processing'};
 const css=readFileSync(ROOT+'/css/main.css','utf8');
 const html='<!doctype html><html lang="de"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><style>html,body{margin:0;height:100%}'+css+'</style><body class="nextgallery-standalone">'+renderShell()+
  '<script>document.getElementById("nextgallery").dataset.context='+JSON.stringify(JSON.stringify(context))+';</script><script>'+bundle.replace(/<\/script/gi,'<\\/script')+'</script></body></html>';
 const svg='<svg xmlns="http://www.w3.org/2000/svg" width="400" height="300"><rect width="400" height="300" fill="#c9d6c2"/><circle cx="280" cy="80" r="34" fill="#eab974"/><path d="M0 290L120 100L250 290L330 150L400 290" fill="#36564d"/></svg>';
 await page.route('https://gallery.test/**',async route=>{
  const url=new URL(route.request().url());state.requests.push(url.pathname+url.search);
  if(url.pathname==='/')return route.fulfill({contentType:'text/html',body:html});
  if(url.pathname.endsWith('/api/settings')){
   if(route.request().method()==='PUT')Object.assign(settings,route.request().postDataJSON().settings);
   return route.fulfill({json:{settings,cacheKey:context.cacheKey}});
  }
  if(url.pathname.endsWith('/api/summary')){
   if(state.holdSummary)await state.holdSummary();
   if(state.failSummary)return route.fulfill({status:503,json:{error:'Synthetic summary failure'}});
   const groups=new Map();for(const item of state.items){const key=item.date.period.slice(0,7);groups.set(key,(groups.get(key)||0)+1);}
   return route.fulfill({json:{settings,total:state.items.length,videos:0,groups:[...groups].sort((a,b)=>b[0].localeCompare(a[0])).map(([key,count])=>({key,count})),state:{completed:1,seen:state.items.length}}});
  }
  if(url.pathname.endsWith('/api/items')){
   if(state.failItems)return route.fulfill({status:503,json:{error:'Synthetic items failure'}});
   const offset=Number(url.searchParams.get('offset')||0),limit=Number(url.searchParams.get('limit')||150),period=url.searchParams.get('period');
   const items=state.items.filter(item=>!period||item.date.period.startsWith(period)).sort((a,b)=>b.date.sort.localeCompare(a.date.sort)||b.id-a.id);
   return route.fulfill({json:{items:items.slice(offset,offset+limit),more:offset+limit<items.length,next:offset+limit<items.length?offset+limit:null}});
  }
  if(url.pathname.endsWith('/api/scan')){const next=state.scanResponses?.shift();if(next)return route.fulfill(next);return route.fulfill({json:{running:false,completed:1,seen:state.items.length}});}
  if(url.pathname.endsWith('/api/map')){
   if(state.holdMap)await state.holdMap();
   const offset=Number(url.searchParams.get('offset')||0),items=state.items.filter(item=>item.metadata.gps),limit=200;
   return route.fulfill({json:{items:items.slice(offset,offset+limit),more:offset+limit<items.length,next:offset+limit<items.length?offset+limit:null}});
  }
  if(url.pathname.includes('/api/v1/subjects'))return route.fulfill({json:{items:[],rules_revision:1}});
  if(url.pathname.includes('/preview/')){
   if(state.holdPreview)await state.holdPreview();
   return state.failPreview?route.fulfill({status:503,body:''}):route.fulfill({contentType:'image/svg+xml',body:svg});
  }
  if(url.pathname.endsWith('/sw.js'))return route.fulfill({status:404,body:''});
  if(url.pathname.endsWith('/offline'))return route.fulfill({contentType:'text/html',body:html});
  if(url.pathname.endsWith('/js/main.js'))return route.fulfill({contentType:'text/javascript',body:bundle});
  if(url.pathname.endsWith('/css/main.css'))return route.fulfill({contentType:'text/css',body:css});
  if(url.pathname.endsWith('/js/map.js'))return route.fulfill({contentType:'text/javascript',body:mapBundle});
  return route.fulfill({json:{}});
 });
 await page.goto('https://gallery.test/');
 await page.locator('.ch-tile:not([disabled])').first().waitFor();
 await page.waitForFunction(()=>[...document.querySelectorAll('.ch-tile img')].every(image=>image.complete&&image.naturalWidth>0));
 return {page,state,context,offlineModule};
}

export async function savedCollection(page,offlineModule) {
 return page.evaluate(async module=>{
  const store=await import(module),db=await store.openStore('synthetic-tests');
  const items=await store.readCollection(db),meta=await store.readMeta(db);
  const blobs=await Promise.all(items.map(async item=>(await store.readBlob(db,item.id))?.size));db.close();
  return {ids:items.map(item=>item.id),meta,blobs};
 },offlineModule);
}

export async function seedCollection(page,offlineModule) {
 await page.evaluate(async module=>{
  const store=await import(module),db=await store.openStore('synthetic-tests');
  await store.saveItem(db,{id:999,name:'Saved original preview',path:'/Synthetic/saved.png',kind:'image',etag:'old',date:{date:'2025-01-01',period:'2025-01-01',sort:'2025-01-01'},metadata:{}},new Blob(['previous-preview'],{type:'image/png'}));
  await store.saveMeta(db,{count:1,bytes:16,saved:1,settings:JSON.parse(document.getElementById('nextgallery').dataset.context).settings});db.close();
 },offlineModule);
}
