// Real admin template/scripts with synthetic settings and no production service.
import assert from 'node:assert/strict';
import {execFileSync} from 'node:child_process';
import {readFile,mkdir} from 'node:fs/promises';
import http from 'node:http';
const {chromium}=await import(process.env.PLAYWRIGHT_MODULE||'playwright');
const fixture=JSON.parse(execFileSync('docker',['run','--rm','--network','none','-v',`${process.cwd()}:/work:ro`,'-w','/work','nextcloud:35.0.1-apache','php','-r',
 'function script(...$args){} function style(...$args){} function p($value){echo htmlspecialchars($value,ENT_QUOTES);} require "lib/AI/Service/Settings.php"; $s=(new ReflectionClass("OCA\\Nextgallery\\AI\\Service\\Settings"))->newInstanceWithoutConstructor(); ob_start();include "templates/ai-admin.php"; $html=ob_get_clean();echo json_encode(["template"=>$html,"config"=>$s->defaults()]);'],{encoding:'utf8'}));
let setting={...fixture.config,can_configure_models:true};setting.embedding.location='external';setting.embedding.local_auto_setup=false;
const saves=[];const unexpected=[];
const server=http.createServer(async(req,res)=>{
 const path=req.url.split('?')[0];res.setHeader('Content-Type','application/json');
 if(path==='/'){
  res.setHeader('Content-Type','text/html; charset=utf-8');
  res.end(`<!doctype html><html lang="de"><head><link rel="stylesheet" href="/css/ai-admin.css"><link rel="stylesheet" href="/css/ai-branding.css"></head><body><div class="mai-shell" data-language="de"><nav id="app-navigation" data-collapsed="true"><a class="mai-nav-brand" href="#settings">NextGallery</a><button id="mai-nav-toggle">Bereiche</button><a data-nav-target="mai-settings">Einstellungen</a><span id="mai-nav-status"></span></nav>${fixture.template}</div><script>window.OC={requestToken:'synthetic',generateUrl:path=>path};</script><script src="/js/ai-i18n.js"></script><script src="/js/ai-workspace.js"></script><script src="/js/ai-admin.js"></script></body></html>`);return;
 }
 if(path.startsWith('/js/')||path.startsWith('/css/')){
  res.setHeader('Content-Type',path.endsWith('.js')?'application/javascript':'text/css');res.end(await readFile('.'+path));return;
 }
 if(path.endsWith('/config')){
  if(req.method==='POST'){let wire='';for await(const part of req)wire+=part;const body=JSON.parse(wire);saves.push(body);setting={...body.config,can_configure_models:true};}
  res.end(JSON.stringify(setting));return;
 }
 if(path.endsWith('/processing')){res.end(JSON.stringify({paused:false,running:false,accounts:[]}));return;}
 if(path.endsWith('/status')){res.end(JSON.stringify({online:true,state:'paused'}));return;}
 if(path.endsWith('/faces-status')){res.end(JSON.stringify({installed:false}));return;}
 if(path==='/favicon.ico'){res.writeHead(404);res.end('{}');return;}
 unexpected.push(path);res.writeHead(404);res.end('{}');
});
await new Promise(resolve=>server.listen(0,'127.0.0.1',resolve));
const browser=await chromium.launch({headless:true,...(process.env.PLAYWRIGHT_EXECUTABLE?{executablePath:process.env.PLAYWRIGHT_EXECUTABLE}:{})});
let checks=0;const errors=[];const check=(value)=>{assert(value);checks++;};
try{
 for(const language of ['de','en']){
  const context=await browser.newContext({viewport:{width:1100,height:950}});const page=await context.newPage();page.on('pageerror',e=>errors.push(e.message));
  await context.route('**/',async route=>{const response=await route.fetch();await route.fulfill({response,body:(await response.text()).replace('lang="de"',`lang="${language}"`).replace('data-language="de"',`data-language="${language}"`)});});
  await page.goto(`http://127.0.0.1:${server.address().port}/`);
  const input=page.locator('[name="embedding.batch_size"]');await page.waitForFunction(()=>document.querySelector('[name="embedding.batch_size"]')?.value);
  await page.locator('#mai-embedding-settings>summary').click();
  check(await input.isVisible());check(await input.getAttribute('type')==='number');
  check((await input.locator('..').textContent()).startsWith(language==='de'?'Bilder/Frames pro Batch':'Images/frames per batch'));
  const videoBudget=page.locator('[name="embedding.video_timeout_seconds"]');
  check(await videoBudget.inputValue()==='3600');
  check((await videoBudget.locator('..').textContent()).startsWith(language==='de'?'Max. Zeit für Video-Aufbereitung':'Maximum video preparation time'));
  await videoBudget.locator('xpath=ancestor::details[1]').locator('summary').click();
  await videoBudget.fill('59');check(!await videoBudget.evaluate(i=>i.checkValidity()));
  await videoBudget.fill('7200');check(await videoBudget.evaluate(i=>i.checkValidity()));
  await page.locator('#mai-embedding-provider').selectOption('native');check(!await input.isVisible());
  await page.locator('#mai-embedding-provider').selectOption('external');check(await input.isVisible());
  await input.fill('0');check(!await input.evaluate(i=>i.checkValidity()));
  await input.fill('10');check(await input.evaluate(i=>i.checkValidity()));
  assert.deepEqual(await page.locator('#mai-form').evaluate(f=>[...f.elements].filter(i=>i.name&&!i.checkValidity()).map(i=>({name:i.name,value:i.value,error:i.validationMessage}))),[]);
  const previous=saves.length;
  await page.locator('#mai-form button[type="submit"]').click();await page.waitForFunction(()=>[...document.querySelectorAll('[data-save-status]')].some(i=>i.dataset.state==='saved'));
  check(saves.length===previous+1);check(saves.at(-1).config.embedding.batch_size===10);
  check(saves.at(-1).config.embedding.video_timeout_seconds===7200);
  check(!saves.at(-1).embedding_change_id);check(!await page.locator('#mai-embedding-change').evaluate(d=>d.open));
  await page.reload();await page.waitForFunction(()=>document.querySelector('[name="embedding.batch_size"]')?.value==='10');checks++;
  await page.locator('#mai-embedding-settings>summary').click();
  await page.locator('[name="embedding.video_timeout_seconds"]').locator('xpath=ancestor::details[1]').locator('summary').click();
  await mkdir('artifacts/embedding-batch-ui',{recursive:true});await page.locator('#mai-embedding-settings').screenshot({path:`artifacts/embedding-batch-ui/${language}.png`});
  // Prepare a distinct next save for the second language.
  setting.embedding.batch_size=1;setting.embedding.video_timeout_seconds=3600;await context.close();
 }
 assert.deepEqual(errors,[]);assert.deepEqual(unexpected,[]);console.log(`${checks} synthetic admin batch UI checks passed`);
}catch(error){console.error({errors,unexpected,saves:saves.length});throw error;}finally{await browser.close();await new Promise(resolve=>server.close(resolve));}
