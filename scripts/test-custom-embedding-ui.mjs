// Disposable Nextcloud only; synthetic model service and accounts.
import assert from 'node:assert/strict';
import {mkdir,writeFile} from 'node:fs/promises';
import {execFileSync} from 'node:child_process';
import http from 'node:http';
const {chromium}=await import(process.env.PLAYWRIGHT_MODULE||'playwright');
const container='nextgallery-merge-fresh35';
execFileSync('docker',['exec',container,'test','-f','/test-output/ISOLATED_SYNTHETIC_TEST']);
execFileSync('docker',['exec','-u','www-data',container,'php','occ','app:disable','firstrunwizard']);
const network=JSON.parse(execFileSync('docker',['inspect',container],{encoding:'utf8'}))[0].NetworkSettings.Networks;
const gateway=Object.values(network)[0].Gateway;
const model='synthetic/free-choice-model',space='a'.repeat(64);let probes=0;
const service=http.createServer(async(req,res)=>{
 res.setHeader('Content-Type','application/json');
 if(req.headers.authorization!=='Bearer synthetic-custom-key'){res.writeHead(401);res.end('{}');return;}
 if(req.url==='/v1/info'){probes++;res.end(JSON.stringify({model,family:'a-new-unrestricted-family',space,dimensions:512,modalities:['image','text']}));return;}
 res.writeHead(404);res.end('{}');
});
await new Promise(resolve=>service.listen(0,'0.0.0.0',resolve));
const endpoint=`http://${gateway}:${service.address().port}/v1`;
const out='artifacts/custom-embedding-ui';await mkdir(out,{recursive:true});
const browser=await chromium.launch({headless:true,...(process.env.PLAYWRIGHT_EXECUTABLE?{executablePath:process.env.PLAYWRIGHT_EXECUTABLE}:{})});let checks=0;const errors=[];
const check=(value,label)=>{assert(value,label);checks++;};
try{
 const context=await browser.newContext({viewport:{width:1180,height:960}}),page=await context.newPage();
 page.on('pageerror',e=>errors.push(e.message));
 const base='http://127.0.0.1:8782/index.php/apps/nextgallery';
 await page.goto('http://127.0.0.1:8782/index.php/login');
 await page.locator('#user').fill('synthetic-admin');await page.locator('#password').fill('SyntheticAdminOnly2026');
 await page.locator('form').evaluate(f=>f.requestSubmit());await page.waitForURL(u=>!u.pathname.endsWith('/login'));
 await page.goto(base+'/');
 const headers={requesttoken:await page.evaluate(()=>OC.requestToken)};
 const original=await(await context.request.get(base+'/ai/admin/config',{headers})).json();
 for(const language of ['de','en']){
  check((await context.request.post(base+'/ai/preferences',{headers,data:{language}})).ok(),'Preference update failed');
  await page.reload();await page.waitForFunction(()=>document.querySelector('input[name="embedding.model"]')?.value);
  await page.locator('#mai-embedding-settings>summary').click();
  await page.locator('#mai-embedding-provider').selectOption('external');
  const guide=page.locator('[data-embedding-guide]').first();
  check((await guide.getAttribute('href')).endsWith(language==='en'?'EXTERNAL_EMBEDDING_SERVER.en.md':'EXTERNAL_EMBEDDING_SERVER.md'),'Guide language mismatch');
  check(await guide.getAttribute('hreflang')===language,'Missing guide language');
  await page.locator('select[name="embedding.family"]').selectOption('custom');
  check(await page.locator('[data-embedding-custom]').isVisible(),'Custom guidance missing');
  check(!await page.locator('#mai-embedding-setup-settings').isVisible(),'Local installation status shown for external service');
  check(!await page.locator('[data-embedding-requirements]').isVisible(),'Local requirements shown for external service');
  check((await page.locator('[data-custom-guide]').textContent()).trim()===(language==='en'?'Custom model guide':'Anleitung für eigene Modelle'),'Custom link untranslated');
  check(await page.locator('input[name="embedding.model"]').inputValue()==='','Custom choice invented a built-in model ID');
  await page.locator('input[name="embedding.model"]').fill(model);
  await page.locator('input[name="embedding.url"]').fill(endpoint);
  await page.locator('input[name="embedding.api_key"]').fill('synthetic-custom-key');
  const response=page.waitForResponse(r=>r.url().endsWith('/ai/admin/embedding-test')&&r.request().method()==='POST');
  await page.locator('#mai-embedding-test').click();const result=await(await response).json();
  check(result.ready&&result.model===model&&result.dimensions===512,'Real admin probe rejected arbitrary family/model');
  check(result.compatibility==='different','Custom vector space bypassed preservation checks');
  // Real PHP probe must ignore invalid settings belonging to other stages.
  const draft=structuredClone(original);draft.embedding={...draft.embedding,location:'external',family:'custom',model,url:endpoint,api_key:'synthetic-custom-key'};
  draft.vision.url='invalid';draft.ocr.model='';draft.schedule_time='invalid';draft.timezone='invalid';
  const isolatedResponse=await context.request.post(base+'/ai/admin/embedding-test',{headers,data:{config:draft}});
  check(isolatedResponse.status()===200,'Unrelated invalid fields blocked the real embedding API');
  check((await isolatedResponse.json()).ready,'Valid custom service could not be probed independently');
  draft.embedding.url='ftp://invalid';
  check((await context.request.post(base+'/ai/admin/embedding-test',{headers,data:{config:draft}})).status()===400,'Invalid embedding endpoint bypassed validation');

  check(JSON.stringify(await(await context.request.get(base+'/ai/admin/config',{headers})).json())===JSON.stringify(original),'Draft probe persisted settings or vectors');
  await page.locator('#mai-embedding-provider').selectOption('full');
  check(!await page.locator('[data-embedding-custom]').isVisible(),'Custom guidance shown for local Gemma');
  check(await page.locator('select[name="embedding.family"] option[value=custom]').evaluate(e=>e.disabled),'Unsupported local custom model allowed');
  check(await page.locator('input[name="embedding.model"]').inputValue()==='embeddinggemma-2-full','Local preset retained invalid custom ID');
  await page.locator('#mai-embedding-provider').selectOption('external');await page.locator('select[name="embedding.family"]').selectOption('custom');
  await page.locator('input[name="embedding.model"]').fill(model);
  await page.locator('.mai-external-install').scrollIntoViewIfNeeded();
  await page.screenshot({path:`${out}/${language}.png`});
 }
 check(probes>=2,'No real custom service probes');check(errors.length===0,errors.join('; '));
 await writeFile(out+'/result.json',JSON.stringify({checks,errors,synthetic_only:true,real_php_controller:true,custom_http_probes:probes},null,2));
 console.log(JSON.stringify({checks,errors,custom_http_probes:probes}));
}finally{await browser.close();await new Promise(resolve=>service.close(resolve));}
