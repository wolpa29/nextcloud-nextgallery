// Run after scripts/test-integration.py --case fresh35 --keep, with firstrunwizard disabled in that container. Synthetic account only.
import assert from 'node:assert/strict';
import {mkdir,writeFile,readFile} from 'node:fs/promises';
const {chromium}=await import(process.env.PLAYWRIGHT_MODULE||'playwright');
const base='http://127.0.0.1:8782',out='artifacts/settings-check';await mkdir(out,{recursive:true});
const browser=await chromium.launch({headless:true,...(process.env.PLAYWRIGHT_EXECUTABLE?{executablePath:process.env.PLAYWRIGHT_EXECUTABLE}:{})});
const context=await browser.newContext({viewport:{width:1360,height:900}}),page=await context.newPage();
const errors=[];page.on('pageerror',e=>errors.push(e.message));let checks=0;
function check(v,label){assert(v,label);checks++;}
async function saved(){await page.waitForFunction(()=>document.getElementById('ch-settings-status')?.textContent==='Einstellungen gespeichert.');}
async function preferences(){return page.evaluate(async()=>{const ctx=JSON.parse(document.getElementById('nextgallery').dataset.context);return (await (await fetch(ctx.base+'/api/settings')).json()).settings;});}
async function palette(selector='#nextgallery'){return page.locator(selector).evaluate(el=>({background:getComputedStyle(el).backgroundColor,text:getComputedStyle(el).color,theme:el.dataset.appearance}));}
try{
 await page.goto(base+'/login');await page.locator('#user').fill('merge-synthetic');await page.locator('#password').fill('SyntheticMergeOnly2026!');await page.locator('form').evaluate(form=>form.requestSubmit());await page.waitForURL(url=>!url.pathname.endsWith('/login'));
 await page.goto(base+'/apps/nextgallery/');await page.locator('#ch-count').filter({hasText:'Medien'}).waitFor();
 check(await page.locator('#ch-ai-link').isVisible(),'AI main navigation visible');check(await page.locator('#ch-ai-feature').isVisible(),'AI feature introduction visible');
 await page.screenshot({path:out+'/desktop-gallery.png'});
 await page.locator('[data-view=settings]').click();
 await page.locator('[data-control=ch-appearance] [data-value=dark]').click();await saved();check((await palette()).background==='rgb(16, 23, 19)','Dark theme immediately applied');
 await page.locator('[data-control=ch-appearance] [data-value=light]').click();await saved();check((await palette()).background==='rgb(244, 246, 242)','Light theme immediately applied');
 await page.emulateMedia({colorScheme:'dark'});check((await palette()).background==='rgb(244, 246, 242)','Explicit light wins over dark system');
 await page.locator('[data-control=ch-appearance] [data-value=dark]').click();await saved();await page.emulateMedia({colorScheme:'light'});check((await palette()).background==='rgb(16, 23, 19)','Explicit dark wins over light system');
 // Delayed responses must not reset a newer selection or another field.
 await page.route('**/api/settings*',async route=>{if(route.request().method()==='PUT')await new Promise(r=>setTimeout(r,180));await route.continue();});
 await page.locator('[data-control=ch-appearance] [data-value=light]').click();await page.locator('#ch-date-mode').selectOption('folder');await page.locator('#ch-timezone').selectOption('Asia/Tokyo');await page.locator('[data-control=ch-appearance] [data-value=dark]').click();await saved();
 let p=await preferences();check(p.appearance==='dark'&&p.mode==='folder'&&p.timezone==='Asia/Tokyo','Rapid changes all persist: '+JSON.stringify(p));await page.unroute('**/api/settings*');
 for(const [control,value,key] of [['ch-order','desc','order'],['ch-grouping','day','grouping'],['ch-density','70','tile']]){
  await page.locator(`#ch-settings-view [data-control=${control}] [data-value="${value}"]`).click();await saved();check(String((await preferences())[key])===value,control+' persisted');check(await page.locator(`#ch-display-dialog [data-control=${control}] [data-value="${value}"]`).getAttribute('aria-checked')==='true',control+' dialog synced');
 }
 await page.screenshot({path:out+'/desktop-settings-dark.png'});
 await page.reload();await page.locator('#ch-appearance').waitFor({state:'attached'});await page.waitForFunction(()=>document.getElementById('ch-timezone').value==='Asia/Tokyo');check((await palette()).background==='rgb(16, 23, 19)','Theme survives reload');
 // A server failure rolls back the preview, reports failure, and allows retry.
 await page.route('**/api/settings*',route=>route.request().method()==='PUT'?route.fulfill({status:500,contentType:'application/json',body:JSON.stringify({error:'Synthetic save failure'})}):route.continue());
 await page.locator('[data-control=ch-appearance] [data-value=light]').click();await page.locator('#ch-settings-status').filter({hasText:'Nicht gespeichert'}).waitFor();check((await palette()).background==='rgb(16, 23, 19)','Failed save rolls back theme');await page.unroute('**/api/settings*');
 await page.locator('[data-control=ch-appearance] [data-value=light]').click();await saved();check((await preferences()).appearance==='light','Retry persists after failure');
 await page.locator('[data-control=ch-language] [data-value=en]').click();await page.waitForFunction(()=>JSON.parse(document.getElementById('nextgallery').dataset.context).language==='en');check(await page.locator('#ch-settings-view').getByText('Changes are saved automatically.').count()===1,'English settings rendered after save');
 await page.locator('[data-control=ch-language] [data-value=de]').click();await page.waitForFunction(()=>JSON.parse(document.getElementById('nextgallery').dataset.context).language==='de');
 await page.locator('[data-view=timeline]').click();
 for(const width of [320,390,768]){
  await page.setViewportSize({width,height:844});await page.locator('#ch-ai-feature').waitFor();check(await page.locator('#ch-ai-link').isVisible(),'AI visible at '+width);const displayRect=await page.locator('#ch-display').boundingBox();check(displayRect.x+displayRect.width<=width+1,'Display button fits at '+width);const rect=await page.locator('#ch-ai-link').boundingBox();check(rect.x>=0&&rect.x+rect.width<=width+1,'AI dock fits at '+width);check(await page.locator('#nextgallery').evaluate(el=>el.scrollWidth<=el.clientWidth),'No horizontal overflow at '+width);
  await page.screenshot({path:out+`/gallery-${width}.png`});await page.locator('[data-view=settings]').click();check(await page.locator('#ch-settings-view').evaluate(el=>el.scrollWidth<=el.clientWidth),'Settings fit at '+width);await page.screenshot({path:out+`/settings-${width}.png`});await page.locator('[data-view=timeline]').click();
 }
 await page.setViewportSize({width:1360,height:900});await page.locator('#ch-ai-link').click();await page.locator('#media-ai-admin').waitFor();check((await palette('#media-ai-admin')).background==='rgb(244, 246, 242)','AI workspace uses saved light theme');await page.screenshot({path:out+'/ai-workspace-light.png'});
 await page.goto(base+'/apps/nextgallery/#settings');await page.locator('[data-control=ch-appearance] [data-value=dark]').click();await saved();await page.locator('#ch-ai-link').click();await page.locator('#media-ai-admin').waitFor();check((await palette('#media-ai-admin')).background==='rgb(16, 23, 19)','AI workspace uses saved dark theme');
 // Cover the complete CSS cascade, including Nextcloud attributes and standalone offline shells.
 const css=await readFile('css/main.css','utf8'),fixture=await context.newPage();
 for(const standalone of [false,true])for(const os of ['light','dark'])for(const host of ['','light','dark'])for(const appearance of ['system','light','dark']){
  await fixture.emulateMedia({colorScheme:os});
  await fixture.setContent(`<html ${host?`data-themes="${host}"`:''}><style>${css}</style><body class="${standalone?'nextgallery-standalone':''}"><div id="nextgallery" data-appearance="${appearance}"><main id="app-content" class="ch-main"></main></div><div class="mai-shell" data-appearance="${appearance}"><div id="media-ai-admin"></div></div></body></html>`);
  const expected=(appearance==='system'?(host||os):appearance)==='dark'?'rgb(16, 23, 19)':'rgb(244, 246, 242)';
  for(const selector of ['#nextgallery','#nextgallery #app-content','#media-ai-admin'])check(await fixture.locator(selector).evaluate(el=>getComputedStyle(el).backgroundColor)===expected,`${selector}: standalone=${standalone} OS=${os} Nextcloud=${host} app=${appearance}`);
 }
 await fixture.close();
 check(errors.length===0,'No browser errors: '+errors.join('; '));await writeFile(out+'/result.json',JSON.stringify({checks,errors,synthetic:true},null,2));console.log(JSON.stringify({checks,errors,synthetic:true}));
}catch(e){console.error(await page.evaluate(()=>({message:document.getElementById('ch-message')?.textContent,status:document.getElementById('ch-settings-status')?.textContent,controls:[...document.querySelectorAll('.ch-segmented')].map(el=>({id:el.dataset.control,buttons:el.querySelectorAll('button').length})),targets:['ch-order','ch-grouping','ch-density'].map(id=>({id,count:document.querySelectorAll('#'+id).length}))})));console.error(errors);throw e;}finally{await browser.close();}
