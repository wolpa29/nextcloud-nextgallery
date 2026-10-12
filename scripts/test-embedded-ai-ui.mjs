// Isolated Nextcloud fixture from test-integration.py --case fresh35 --keep.
import assert from 'node:assert/strict';
import {mkdir,writeFile} from 'node:fs/promises';
const {chromium}=await import(process.env.PLAYWRIGHT_MODULE||'playwright');
const base=process.env.TEST_BASE||'http://127.0.0.1:8782',out='artifacts/embedded-ai';
await mkdir(out,{recursive:true});
const browser=await chromium.launch({headless:true,...(process.env.PLAYWRIGHT_EXECUTABLE?{executablePath:process.env.PLAYWRIGHT_EXECUTABLE}:{})});
let checks=0;const errors=[];
const check=(v,label)=>{assert(v,label);checks++;};
async function login(page,user,password){
 await page.goto(base+'/login');await page.locator('#user').fill(user);await page.locator('#password').fill(password);
 await page.locator('form').evaluate(form=>form.requestSubmit());await page.waitForURL(url=>!url.pathname.endsWith('/login'));
}
try{
 for(const admin of [false,true]){
  const context=await browser.newContext({viewport:{width:390,height:844},userAgent:'Mozilla/5.0 (Android) Nextcloud-android/3.35.0'});
  const page=await context.newPage();page.on('pageerror',e=>errors.push(e.message));
  // Authentication uses a normal browser; the embedded view uses the unmodified client UA.
  const authContext=await browser.newContext();const authPage=await authContext.newPage();
  await login(authPage,admin?'synthetic-admin':'merge-synthetic',admin?'SyntheticAdminOnly2026':'SyntheticMergeOnly2026!');
  await authPage.goto(base+(admin?'/apps/nextgallery/ai':'/apps/nextgallery/processing'));
  await authPage.locator(admin?'#media-ai-admin':'#media-ai-user').waitFor();
  check(await authPage.locator('#header').count()===1,'Ordinary browser retains core shell');
  await context.addCookies(await authContext.cookies());await authContext.close();
  await page.goto(base+'/apps/nextgallery/');await page.locator('#ch-count').filter({hasText:/\d/}).waitFor();
  const initial=page.url();let navigations=0,popups=0;
  check(await page.evaluate(()=>navigator.userAgent)==='Mozilla/5.0 (Android) Nextcloud-android/3.35.0','Unmodified Nextcloud Android UA');
  check(await page.locator('script[src*=core-unsupported-browser]').count()===0,'No core browser redirect on startup');
  await page.waitForTimeout(1200);check(page.url()===initial,'Gallery startup remains in its document');
  page.on('framenavigated',frame=>{if(frame===page.mainFrame())navigations++;});page.on('popup',()=>popups++);
  check(await page.locator('#ch-ai-view').isHidden(),'AI does not cover gallery before selection');
  const saved=await page.evaluate(async()=>{const ctx=JSON.parse(document.querySelector('#nextgallery').dataset.context);const r=await fetch(ctx.base.replace(/\/$/,'')+'/api/settings',{method:'PUT',headers:{'Content-Type':'application/json',requesttoken:NextGallerySession.requestToken},body:JSON.stringify({settings:{appearance:'light'}})});return r.status;});
  check(saved===200,'Standalone gallery preserves CSRF-protected settings');
  await page.locator('#ch-ai-link').click();
  let frame=page.frameLocator('#ch-ai-frame');
  await frame.locator(admin?'#media-ai-admin':'#media-ai-user').waitFor();
  await frame.locator(admin?'#mai-form button[type=submit]':'#mai-description-count').waitFor();
  if(admin)await frame.locator('#mai-form button[type=submit]').waitFor({state:'visible'});
  else await frame.locator('#mai-description-count').filter({hasText:/\d/}).waitFor();
  check(page.url()===initial&&navigations===0&&popups===0,'AI preserves parent document and login '+JSON.stringify({initial,url:page.url(),navigations,popups,embedded:await page.locator('#nextgallery').evaluate(el=>JSON.parse(el.dataset.context).embedded)}));
  check(await page.locator('#ch-message').isHidden(),'No false session-expired message');
  check(await frame.locator('#header').count()===0,'No duplicate Nextcloud header');
  check(await frame.locator('script[src*=core-unsupported-browser]').count()===0,'No core redirect inside AI');
  check(await frame.locator('.mai-shell').getAttribute('data-embedded')==='true','Embedded response used');
  const csrf=await page.frames().find(f=>f.url().includes('embedded=1')).evaluate(async()=>{const r=await fetch(NextGallerySession.generateUrl('/apps/nextgallery/ai/preferences'),{method:'POST',headers:{'Content-Type':'application/json',requesttoken:NextGallerySession.requestToken},body:JSON.stringify({language:'de'})});return r.status;});check(csrf===200,'AI standalone retains CSRF-protected writes');
  const deniedWrite=await context.request.put(base+'/apps/nextgallery/api/settings',{data:{settings:{appearance:'dark'}}});check(deniedWrite.status()===412||deniedWrite.status()===403,'Writes without CSRF token denied');
  for(const width of [320,390,768,1360]){
   await page.setViewportSize({width,height:844});
   check(await frame.locator('body').evaluate(el=>el.scrollWidth<=el.clientWidth+1),'AI fits '+width);
   check(await page.locator('#ch-ai-frame').evaluate(el=>el.clientHeight>250),'AI has usable height '+width);
  }
  await page.setViewportSize({width:390,height:844});
  if(admin){
   await frame.getByRole('link',{name:'Meine Verarbeitung',exact:true}).click();
   await frame.locator('#media-ai-user').waitFor();
  }
  await frame.locator('#mai-user-edit-selection').click();
  await frame.locator('[data-user-view=selection]').waitFor();
  await frame.locator('#mai-nav-toggle').click();await frame.locator('[data-nav-target=mai-library]').click();
  await frame.locator('[data-user-view=library]').waitFor();
  await frame.locator('#mai-nav-toggle').click();await frame.locator('[data-nav-target=mai-pipeline-status]').click();
  await frame.locator('[data-user-view=overview]').waitFor();
  check(navigations===0&&popups===0,'AI subviews stay inside gallery');
  if(admin){await frame.getByRole('link',{name:'Administration',exact:true}).click();await frame.locator('#media-ai-admin').waitFor();}
  await page.screenshot({path:out+`/${admin?'admin':'user'}-mobile.png`});
  await frame.getByRole('link',{name:'← Galerie',exact:true}).click();await page.locator('#ch-timeline-view').waitFor();
  await page.locator('[data-view=map]').click();await page.locator('#ch-map-view').waitFor();
  await page.locator('[data-view=offline]').click();await page.locator('#ch-offline-view').waitFor();
  await page.locator('[data-view=settings]').click();await page.locator('#ch-settings-view').waitFor();
  await page.locator('#ch-settings-ai-link').click();await page.locator('#ch-ai-view').waitFor();
  check(page.url()===initial&&navigations===0&&popups===0,'Return and all gallery tabs keep parent session');
  const policy=await context.request.get(base+'/apps/nextgallery/processing?embedded=1');
  check(policy.ok()&&policy.headers()['content-security-policy'].includes("frame-ancestors 'self'"),'Framing allowed only for same origin');
  await context.setOffline(true);await page.locator('#ch-timeline-view').waitFor();check(await page.locator('#ch-ai-link').isHidden(),'AI hidden without connectivity');
  await context.close();
 }
 const anonymous=await browser.newContext();const denied=await anonymous.request.get(base+'/apps/nextgallery/processing?embedded=1',{maxRedirects:0});
 check(!denied.ok(),'Embedded flag does not bypass authentication');await anonymous.close();
 check(errors.length===0,'No browser errors: '+errors.join('; '));
 await writeFile(out+'/result.json',JSON.stringify({checks,errors,synthetic:true},null,2));console.log(JSON.stringify({checks,errors,synthetic:true}));
}finally{await browser.close();}
