// Synthetic browser regression for activity, saved counts, timing and batches.

import assert from 'node:assert/strict';
import { mkdir, writeFile, readFile } from 'node:fs/promises';
import { existsSync } from 'node:fs';
import http from 'node:http';

const playwrightPath = process.env.PLAYWRIGHT_MODULE || 'playwright';
const { chromium } = await import(playwrightPath);

const outDir = 'artifacts/activity-cache-fix';
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


let version='0.4.15';const oldCode=await readFile('artifacts/activity-cache-fix/ai-user-old.js','utf8');
const live=JSON.parse(await readFile('artifacts/activity-cache-fix/live-template-verification.json','utf8'));
const hashes={'0.4.15':live.old_js_hash+'-1','0.4.16':live.new_js_hash+'-1'};const requested=[];
const config={description_count:0,ocr_count:0,embedding_count:14176,embedding_current_count:4610,analysis_enabled:true,ocr_enabled:true,embedding_enabled:true,folders:['/'],exclude_folders:[],schedule:{enabled:false,time:'03:00',timezone:'Europe/Vienna'}};
const status={server_time:Date.now()/1000,online:true,state:'running',control:{paused:false},pipelines:Object.fromEntries(['descriptions','ocr','embeddings'].map(kind=>[kind,{online:true,state:'running',stats:{done:4610,pending:20002,error:24},control:{paused:false},current:{path:'Synthetic/batch.png',started_at:Date.now()/1000-10}}]))};
const server=http.createServer(async(req,res)=>{const url=new URL(req.url,'http://localhost');
 if(url.pathname==='/'){res.setHeader('Cache-Control','no-store');res.setHeader('Content-Type','text/html');return res.end(fixtureHtml.replace(/(src|href)="\/(js|css)\/([^"?]+)"/g,`$1="/$2/$3?v=${hashes[version]}"`));}
 if(url.pathname.startsWith('/apps/nextgallery/ai/account/')){const end=url.pathname.split('/').pop();res.setHeader('Content-Type','application/json');return res.end(JSON.stringify(end==='config'?config:end==='status'?status:end==='logs'?{entries:[],cursor:null}:{}));}
 if(url.pathname.startsWith('/js/')||url.pathname.startsWith('/css/')){requested.push(url.pathname+url.search);res.setHeader('Cache-Control','public,max-age=15778463');res.setHeader('Content-Type',url.pathname.endsWith('.js')?'application/javascript':'text/css');return res.end(url.pathname==='/js/ai-user.js'&&url.searchParams.get('v')===hashes['0.4.15']?oldCode:await readFile('.'+url.pathname));}
 res.writeHead(404);res.end();
});
await new Promise(resolve=>server.listen(0,'127.0.0.1',resolve));
const browser=await chromium.launch({headless:true,executablePath:'/usr/bin/google-chrome'});const page=await browser.newPage();const errors=[];page.on('pageerror',e=>errors.push(e.message));
try{const origin=`http://127.0.0.1:${server.address().port}`;await page.goto(origin);await page.waitForFunction(()=>document.querySelector('#mai-vector-count').textContent.replace(/\D/g,'')==='14176');
 version='0.4.16';await page.reload();await page.waitForFunction(()=>document.querySelector('#mai-vector-count').textContent.replace(/\D/g,'')==='4610');
 assert(requested.includes('/js/ai-user.js?v='+hashes['0.4.15']));assert(requested.includes('/js/ai-user.js?v='+hashes['0.4.16']));assert(await page.locator('#mai-vector-progress button').count()===1);assert((await page.locator('#mai-embedding-current small').textContent()).includes('Laufzeit'));assert.equal(errors.length,0);
 const result={old_display:14176,new_display:4610,ordinary_reload:true,same_browser_context:true,cache_max_age:15778463,old_hash:hashes['0.4.15'],new_hash:hashes['0.4.16'],errors};await writeFile(outDir+'/browser-cache-regression.json',JSON.stringify(result,null,2));console.log(JSON.stringify(result));
}finally{await browser.close();server.close();}
