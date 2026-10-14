// Synthetic browser regression for activity, saved counts, timing and batches.

import assert from 'node:assert/strict';
import { mkdir, writeFile, readFile } from 'node:fs/promises';
import { existsSync } from 'node:fs';
import http from 'node:http';

const playwrightPath = process.env.PLAYWRIGHT_MODULE || 'playwright';
const { chromium } = await import(playwrightPath);

const outDir = 'artifacts/activity-ui';
await mkdir(outDir, { recursive: true });

// Prepare HTML fixture: stripped templates/ai-user.php wrapped in full app shell
const templateContent = await readFile('templates/ai-user.php', 'utf8');
const aiUserHtml = templateContent.replace(/^<\?php[\s\S]*?\?>\s*/, '');

const fixtureHtml = `<!DOCTYPE html>
<html lang="de">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>NextGallery KI-Verarbeitung Synthetic Test</title>
  <link rel="stylesheet" href="/css/app-theme.css">
  <link rel="stylesheet" href="/css/ai-admin.css">
  <link rel="stylesheet" href="/css/ai-branding.css">
</head>
<body>
  <div class="mai-shell" data-language="de" data-appearance="light">
    <nav id="app-navigation" class="mai-navigation" aria-label="NextGallery KI-Verarbeitung" data-collapsed="true">
      <div class="mai-nav-header">
        <a href="#mai-pipeline-status" class="mai-nav-brand">
          <span class="mai-brand-mark" style="width:30px;height:30px" aria-hidden="true"></span>
          <span class="mai-brand-copy">NextGallery<small>Meine KI-Daten</small></span>
        </a>
        <button type="button" id="mai-nav-toggle" aria-expanded="false" aria-controls="mai-navigation-list">Bereiche</button>
      </div>
      <a class="mai-gallery-link" href="#gallery">← Galerie</a>
      <ul id="mai-navigation-list" class="mai-nav-links">
        <li><a href="#mai-pipeline-status" data-nav-target="mai-pipeline-status" class="is-active" aria-current="page"><span>Übersicht</span></a></li>
        <li><a href="#mai-library" data-nav-target="mai-library"><span>Dateien</span></a></li>
        <li><a href="#mai-pipeline-settings" data-nav-target="mai-pipeline-settings"><span>Ordner und Zeitplan</span></a></li>
        <li><a href="#mai-pipeline-logs" data-nav-target="mai-pipeline-logs"><span>Aktivitäten</span></a></li>
      </ul>
      <div class="mai-nav-footer">
        <span id="mai-nav-status" class="mai-status-dot">Verbinde …</span>
        <small>NextGallery · KI-Verarbeitung</small>
      </div>
    </nav>
    <div id="app-content" class="mai-app-content">
      ${aiUserHtml}
    </div>
  </div>
  <script>
    window.OC = {
      requestToken: 'synthetic-token-user-isolation',
      generateUrl: function(path) { return path; }
    };
  </script>
  <script src="/js/ai-i18n.js"></script>
  <script src="/js/ai-user.js"></script>
</body>
</html>`;

// Local HTTP static server for assets
const server = http.createServer(async (req, res) => {
  const url = req.url.split('?')[0];
  if (url === '/' || url === '/index.html') {
    res.setHeader('Content-Type', 'text/html; charset=utf-8');
    return res.end(fixtureHtml);
  }
  if (url.startsWith('/css/') || url.startsWith('/js/')) {
    try {
      const data = await readFile(url === '/js/ai-user.js' && process.env.CONTROL_SCRIPT_PATH ? process.env.CONTROL_SCRIPT_PATH : '.' + url);
      res.setHeader('Content-Type', url.endsWith('.css') ? 'text/css' : 'application/javascript');
      return res.end(data);
    } catch {
      res.writeHead(404);
      return res.end();
    }
  }
  if (url.endsWith('.svg')) {
    res.setHeader('Content-Type', 'image/svg+xml');
    return res.end('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"></svg>');
  }
  res.writeHead(404);
  res.end();
});

await new Promise(resolve => server.listen(0, '127.0.0.1', resolve));

const origin=`http://127.0.0.1:${server.address().port}`;
const executablePath=process.env.PLAYWRIGHT_EXECUTABLE||(existsSync('/usr/bin/google-chrome')?'/usr/bin/google-chrome':undefined);
const browser=await chromium.launch({headless:true,...(executablePath?{executablePath}:{})});
let checks=0;const errors=[];const check=(value,message)=>{assert(value,message);checks++;};
const config={description_count:12,ocr_count:7,embedding_count:150,embedding_current_count:15,analysis_enabled:true,ocr_enabled:true,embedding_enabled:true,folders:['/'],exclude_folders:[],schedule:{enabled:false,time:'03:00',timezone:'Europe/Vienna'}};
const now=Date.now()/1000+3600;
const status={server_time:now,online:true,state:'running',control:{paused:false},pipelines:Object.fromEntries(['descriptions','ocr','embeddings'].map(kind=>[kind,{online:true,state:'running',stats:{done:7,pending:4,error:14},control:{paused:false},current:{path:'Synthetic/'+kind+'.png',started_at:now-10}}]))};
status.pipelines.embeddings.current={stage:'embedding',started_at:now-10,files:[1,2,3,4].map(id=>({file_id:id,path:'Synthetic/batch-'+id+'.png'}))};
const entries=[{event:'embedding_error',kind:'embeddings',time:new Date().toISOString(),error:'Synthetic HTTP 503: model unavailable',path:'Synthetic/bad.png'},{event:'file_finished',kind:'ocr',time:new Date().toISOString(),path:'Synthetic/good.png'},{event:'worker_error',kind:'ocr',time:new Date().toISOString(),error:'<img src=x onerror=alert(1)>'},{event:'file_error',kind:'descriptions',time:new Date().toISOString(),path:'Synthetic/legacy.png'}];
let staleResolve=null,delayLogs=false;const logRequests=[];
try{
 const page=await browser.newPage({viewport:{width:1360,height:1000}});page.on('pageerror',e=>errors.push(e.message));
 await page.route('**/apps/nextgallery/ai/account/**',async route=>{
  const url=new URL(route.request().url()),endpoint=url.pathname.split('/').pop();let data;
  if(endpoint==='config')data=config;
  else if(endpoint==='status')data={...status,server_time:Date.now()/1000+3600};
  else if(endpoint==='logs'){
   logRequests.push(url.searchParams.toString());const level=url.searchParams.get('level'),kind=url.searchParams.get('kind');
   data={entries:entries.filter(row=>(level!=='error'||row.event.endsWith('_error'))&&(kind==='all'||row.kind===kind)),cursor:null};
   if(delayLogs){delayLogs=false;await new Promise(resolve=>{staleResolve=resolve;});}
  }else if(endpoint==='library')data={folder:{counts:{total:1,done:0,changed:0,pending:0,publishing:0,error:1,ocr_done:0,ocr_pending:0,ocr_error:0,embedded:0,embedding_pending:0,embedding_error:1}},folders:[],files:[],matched:0,next_offset:null};
  else data={};
  await route.fulfill({status:200,contentType:'application/json',body:JSON.stringify(data)});
 });
 await page.goto(origin);await page.locator('#mai-vector-count').getByText('15',{exact:true}).waitFor();
 for(const id of [1,2,3,4])check((await page.locator('#mai-embedding-current').textContent()).includes('batch-'+id+'.png'),'Batch file '+id+' missing');
 check((await page.locator('#mai-embedding-current').textContent()).includes('Batch · 4 Dateien'),'Actual batch size missing');
 const before=await page.locator('#mai-embedding-current small').textContent();check(/Laufzeit: 1[0-2] s/.test(before),'Elapsed seconds depend on client clock: '+before);await page.waitForTimeout(1150);check(before!==await page.locator('#mai-embedding-current small').textContent(),'Live seconds did not advance');
 config.embedding_current_count=19;await page.waitForFunction(()=>document.querySelector('#mai-vector-count').textContent==='19');check((await page.locator('#mai-vector-count-hint').textContent()).includes('150 Dateien insgesamt'),'Total and current model counts are not distinguished');
 await page.locator('#mai-vector-progress button').click();await page.locator('#mai-library').waitFor({state:'visible'});check(await page.locator('#mai-user-embedding-filter').inputValue()==='error','Error count did not open affected files');
 await page.goto(origin+'#activity');await page.locator('.mai-activity-error').first().waitFor();
 check((await page.locator('#mai-user-runs').textContent()).includes('Synthetic HTTP 503'),'Concrete error message missing');
 check(await page.locator('#mai-user-runs img').count()===0,'Error message treated as HTML');
 check((await page.locator('#mai-user-runs').textContent()).includes('kein Fehlergrund gespeichert'),'Legacy error reason fallback missing');
 const red=await page.locator('.mai-error-detail').first().evaluate(el=>getComputedStyle(el).color);const [r,g,b]=red.match(/\d+/g).map(Number);check(r>g*2&&r>b*2,'Error is not red: '+red);
 await page.locator('#mai-user-log-level').selectOption('error');await page.waitForFunction(()=>!document.querySelector('[data-event=file_finished]'));check(logRequests.at(-1).includes('level=error'),'Error filter not sent to backend');
 await page.locator('#mai-user-log-kind').selectOption('embeddings');await page.waitForFunction(()=>document.querySelectorAll('.mai-activity-error').length===1);check(logRequests.at(-1).includes('kind=embeddings'),'Pipeline filter not sent');
 // A late result for an earlier filter must not replace the selected one.
 delayLogs=true;await page.locator('#mai-user-log-kind').selectOption('ocr');await page.waitForFunction(()=>true);while(!staleResolve)await new Promise(r=>setTimeout(r,10));await page.locator('#mai-user-log-kind').selectOption('embeddings');await page.waitForFunction(()=>document.querySelector('#mai-user-runs').textContent.includes('Synthetic HTTP 503'));staleResolve();await page.waitForTimeout(100);check((await page.locator('#mai-user-runs').textContent()).includes('Synthetic HTTP 503'),'Old filter overwrote newer results');
 for(const width of [1360,390,320]){await page.setViewportSize({width,height:1000});check(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1),'Activity overflow '+width);await page.screenshot({path:outDir+'/activity-'+width+'.png',fullPage:true});}
 await page.goto(origin);await page.locator('#mai-embedding-current small').waitFor();await page.setViewportSize({width:390,height:1000});await page.screenshot({path:outDir+'/batch-mobile.png',fullPage:true});check(errors.length===0,'Browser exceptions: '+errors.join(', '));await writeFile(outDir+'/result.json',JSON.stringify({checks,errors,synthetic_only:true},null,2));console.log(JSON.stringify({checks,errors}));
}finally{staleResolve?.();await browser.close();server.close();}
