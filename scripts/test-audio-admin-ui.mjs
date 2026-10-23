// Actual disposable Nextcloud, synthetic administrator only.
import assert from 'node:assert/strict';
import {mkdir,writeFile} from 'node:fs/promises';
import {execFileSync} from 'node:child_process';
import {chromium} from 'playwright';
const container=process.env.NEXTGALLERY_AUDIO_CONTAINER||'nextgallery-audio-fresh35';
const origin=process.env.NEXTGALLERY_AUDIO_ORIGIN||'http://127.0.0.1:8792',base=origin+'/index.php/apps/nextgallery';
execFileSync('docker',['exec',container,'test','-f','/test-output/ISOLATED_SYNTHETIC_TEST']);
execFileSync('docker',['exec','-u','www-data',container,'php','occ','app:disable','firstrunwizard']);
const out='artifacts/audio-pipeline-20261023/admin-ui';await mkdir(out,{recursive:true});
const browser=await chromium.launch({headless:true,args:['--no-sandbox']});
const context=await browser.newContext({viewport:{width:1360,height:1000}}),page=await context.newPage(),errors=[];
const responses=[];page.on('response',async r=>{if(r.request().method()==='POST'&&r.url().includes('/ai/admin/')){let data;try{data=await r.json();}catch{}responses.push({status:r.status(),error:data?.error,url:r.url()});}});
page.on('pageerror',e=>errors.push(e.stack||e.message));let previous,headers,checks=0;
const check=(value,message)=>{assert(value,message);checks++;};
try{
 await page.goto(origin+'/index.php/login');await page.locator('#user').fill('synthetic-admin');await page.locator('#password').fill('SyntheticAdminOnly2026');await page.locator('form').evaluate(f=>f.requestSubmit());await page.waitForURL(u=>!u.pathname.endsWith('/login'));
 await page.route('**/ai/admin/processing',route=>route.request().method()==='GET'?route.fulfill({json:{pipelines:Object.fromEntries(['descriptions','ocr','embeddings','faces','audio'].map(kind=>[kind,{paused:false,running:kind!=='audio',accounts:[]}]))}}):route.continue());
 await page.goto(base+'/');await page.waitForFunction(()=>document.querySelector('#mai-form [data-save-status]')?.textContent==='Gespeichert');
 headers={requesttoken:await page.evaluate(()=>(window.NextGallerySession||window.OC).requestToken)};
 previous=await(await context.request.get(base+'/ai/admin/config',{headers})).json();
 await page.locator('#mai-audio-settings>summary').click();
 check(await page.locator('[name="audio.model"] option').count()===6,'All six model sizes available');
 check(await page.locator('[name="audio.model"] option[value=large-v3]').count()===1,'Large quality model available');
 await page.locator('[name="audio.model"]').selectOption(previous.audio.model==='large-v3'?'medium':'large-v3');
 await page.locator('[name="audio.timeout_seconds"]').fill('86400');
 const submit=page.locator('#mai-savebar button[type=submit]');await submit.waitFor();
 check(await submit.isEnabled(),'Other running lanes blocked independent audio settings');
 await submit.click();await page.waitForFunction(()=>document.querySelector('#mai-form [data-save-status]')?.textContent==='Gespeichert');
 const saved=await(await context.request.get(base+'/ai/admin/config',{headers})).json();
 check(saved.audio.timeout_seconds===86400,'24-hour budget persisted');
 check(saved.audio.model!==previous.audio.model,'Model selection persisted');
 for(const key of ['vision','ocr','embedding','faces'])check(JSON.stringify(saved[key])===JSON.stringify(previous[key]),key+' settings changed');
 await page.screenshot({path:out+'/desktop.png',fullPage:true});
 await page.setViewportSize({width:390,height:844});
 check(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),'Mobile horizontal overflow');
 await page.locator('#mai-audio-settings').scrollIntoViewIfNeeded();
 await page.screenshot({path:out+'/mobile.png',fullPage:true});
 check(errors.length===0,'Browser errors: '+errors.join('; '));
 await writeFile(out+'/result.json',JSON.stringify({checks,errors,synthetic:true},null,2));console.log(JSON.stringify({checks,errors,synthetic:true}));
}catch(error){console.error(JSON.stringify({responses,errors,state:await page.evaluate(()=>({message:document.querySelector('#mai-message')?.textContent,status:[...document.querySelectorAll('[data-save-status]')].map(n=>n.textContent),invalid:[...document.querySelector('#mai-form').elements].filter(n=>n.name&&!n.checkValidity()).map(n=>({name:n.name,value:n.value,message:n.validationMessage}))}))}));throw error;}finally{
 if(previous&&headers)await context.request.post(base+'/ai/admin/config',{headers,data:{config:previous}});
 await browser.close();
}
