import {tr,locale} from './i18n.mjs';
import {layout,visible,localSummary,groupKey} from './layout.mjs';
import {openStore,readCollection,readMeta,readBlob,saveItem,saveMeta,clear} from './offline.mjs';
const root=document.getElementById('nextgallery');
if(root) start().catch(e=>{const m=root.querySelector('#ch-message');m.hidden=false;m.textContent=e.message||tr('NextGallery konnte nicht gestartet werden.');});
async function start(){
 const ctx=JSON.parse(root.dataset.context),$=id=>root.querySelector('#'+id),base=ctx.base.replace(/\/$/,'');
 const icons={processing:'<path d="M9 3h6l1 4 4 2v6l-4 2-1 4H9l-1-4-4-2V9l4-2Z"/><path d="m10 9 5 3-5 3Z"/>',search:'<circle cx="10" cy="10" r="6"/><path d="m15 15 6 6"/>',gallery:'<rect x="3" y="3" width="18" height="18" rx="3"/><circle cx="16" cy="8" r="1.5"/><path d="m4 18 5-7 6 7 3-4 3 4"/>',map:'<path d="m3 5 6-2 6 2 6-2v16l-6 2-6-2-6 2ZM9 3v16m6-14v16"/>',download:'<path d="M12 3v12m-5-5 5 5 5-5M4 16v5h16v-5"/>',settings:'<path d="M4 7h16M4 17h16M8 4v6m8 4v6"/>',refresh:'<path d="M20 10a8 8 0 1 0-2 8M20 3v7h-7"/>',folder:'<path d="M3 7V4h7l2 3h9v13H3Z"/>',play:'<path d="m8 4 12 8-12 8Z"/>'};
 const icon=name=>`<svg class="ch-icon" viewBox="0 0 24 24" aria-hidden="true">${icons[name]||icons.gallery}</svg>`;
 root.querySelectorAll('[data-icon]').forEach(n=>n.innerHTML=icon(n.dataset.icon));
 let settings=ctx.settings,cacheKey=ctx.cacheKey,db=null,offlineItems=[],offlineMeta=null,offlineMode=ctx.offline||!navigator.onLine;
 let summary=null,view='timeline',kind='',version=0,geometry=null,frame=0,blocks=new Map(),pending=[],inflight=0,rendered=new Map(),urls=new Map(),collectionIds=new Set(),localGroups=new Map();
 let scanning=false,pinning=false,pinCancel=false,mapHandle=null,currentItem=null,viewerList=[],folderPath='/',draftRoots=[...settings.roots];
 const scroll=$('ch-scroll'),canvas=$('ch-canvas'),rail=$('ch-rail');
 function message(text){$('ch-message').textContent=text;$('ch-message').hidden=!text;}
 function handleError(e){message(e.message||tr('Die Anfrage konnte nicht abgeschlossen werden.'));}
 const on=(id,event,fn)=>$(id).addEventListener(event,e=>Promise.resolve(fn(e)).catch(handleError));
 async function api(path,options={}){
  const r=await fetch(base+'/api/'+path,{credentials:'same-origin',...options,headers:{'Content-Type':'application/json',requesttoken:window.OC?.requestToken||'',...options.headers}});
  if(r.redirected||r.status===401||r.status===403)throw new Error(tr('Die Sitzung ist abgelaufen. Bitte Nextcloud erneut öffnen.'));
  let data;try{data=await r.json();}catch{throw new Error(tr('Keine Verbindung zu Nextcloud.'));}
  if(!r.ok)throw new Error(data.error||tr`Anfrage fehlgeschlagen (${r.status}).`);return data;
 }
 function label(key){const [y,m,d]=key.split('-').map(Number);if(!m)return String(y);return new Intl.DateTimeFormat(locale,{year:'numeric',month:'long',...(d?{day:'numeric'}:{}),timeZone:'UTC'}).format(new Date(Date.UTC(y,m-1,d||1)));}
 function count(n){return new Intl.NumberFormat(locale).format(n);}
 function mediaCount(n){return tr`${count(n)} ${n===1?tr('Medium'):tr('Medien')}`;}
 function duration(n){return n==null?tr('Video'):`${Math.floor(n/60)}:${String(Math.floor(n%60)).padStart(2,'0')}`;}
 function preview(item,size=384){return base+`/preview/${item.id}?size=${size}&v=${encodeURIComponent(item.etag)}&collection=${cacheKey}`;}
 function original(item){return base+`/original/${item.id}`;}
 async function storage(){
  if(ctx.portable){offlineItems=ctx.items;offlineMeta=ctx.meta;collectionIds=new Set(offlineItems.map(i=>i.id));offlineStatus();return;}
  try{db=await openStore(cacheKey);offlineMeta=await readMeta(db);offlineItems=await readCollection(db);collectionIds=new Set(offlineItems.map(i=>i.id));}
  catch(e){db=null;offlineItems=[];offlineMeta=null;if(offlineMode)message(tr('Der lokale Speicher ist in diesem Browser nicht verfügbar.'));}
  offlineStatus();
 }
 function offlineStatus(){$('ch-offline-count').textContent=offlineItems.length?tr`${count(offlineItems.length)} Vorschauen gespeichert`:tr('Noch keine Sammlung');$('ch-offline-size').textContent=offlineMeta?`${((offlineMeta.bytes||0)/1048576).toFixed(1)} MB · ${new Date(offlineMeta.saved).toLocaleString(locale)}`:tr('Vorschauen benötigen nur einen Teil des Speicherplatzes.');$('ch-export').disabled=!offlineItems.length||ctx.portable;$('ch-show-saved').disabled=!offlineItems.length;$('ch-clear-offline').disabled=!!ctx.portable;$('ch-open-offline').hidden=!!ctx.portable;}
 function releaseUrls(){for(const u of urls.values())URL.revokeObjectURL(u);urls.clear();}
 async function imageURL(item){
  if(ctx.portable)return ctx.blobs[item.id]||null;
  if(!offlineMode)return preview(item);
  if(urls.has(item.id))return urls.get(item.id);const b=db?await readBlob(db,item.id):null;if(!b)return null;const u=URL.createObjectURL(b);urls.set(item.id,u);return u;
 }
 async function worker(){
  if(!('serviceWorker'in navigator)||!window.isSecureContext)return null;
  try{
   const registration=await navigator.serviceWorker.register(base+'/sw.js',{scope:base+'/'});
   // A legacy /index.php URL may lie outside the canonical worker scope.
   // Wait for this registration to activate, not for the current page to be controlled.
   if(!registration.active){
    const pending=registration.installing||registration.waiting;
    if(!pending)return null;
    await new Promise((resolve,reject)=>{
     const done=()=>{clearTimeout(timer);pending.removeEventListener('statechange',changed);};
     const changed=()=>{if(pending.state==='activated'){done();resolve();}else if(pending.state==='redundant'){done();reject(new Error('worker redundant'));}};
     const timer=setTimeout(()=>{done();reject(new Error('worker activation timeout'));},5000);
     pending.addEventListener('statechange',changed);changed();
    });
   }
   return registration;
  }catch{return null;}
 }
 async function clearOld(){
  const prior=localStorage.getItem('nextgallery-current');if(prior&&prior!==cacheKey){indexedDB.deleteDatabase('nextgallery-'+prior);for(const key of await caches.keys())if(key.startsWith('nextgallery-shell-'))await caches.delete(key);}
  localStorage.setItem('nextgallery-current',cacheKey);
 }
 async function persist(patch){
  if(offlineMode){settings={...settings,...patch};await load();return;}
  const r=await api('settings',{method:'PUT',body:JSON.stringify({settings:patch})});settings=r.settings;
  if(cacheKey!==r.cacheKey){if(db){db.close();db=null;}releaseUrls();cacheKey=r.cacheKey;await clearOld();await storage();}
  updateSettings();
 }
 function updateSettings(){
  $('ch-grouping').value=settings.grouping;$('ch-order').value=settings.order;$('ch-density').value=settings.tile;$('ch-date-mode').value=settings.mode;$('ch-timezone').value=settings.timezone;$('ch-offline-limit').value=settings.offlineLimit;
  $('ch-source-count').textContent=settings.roots.length===1?tr('Ein Ordner ausgewählt'):settings.roots.length?tr`${settings.roots.length} Ordner ausgewählt`:tr('Keine Ordner ausgewählt');
  draftRoots=[...settings.roots];renderRoots();$('ch-refresh').disabled=offlineMode;for(const id of ['ch-add-root','ch-save-settings','ch-reindex','ch-pin'])$(id).disabled=offlineMode;
  if($('ch-ai-link'))$('ch-ai-link').hidden=offlineMode;
  $('ch-connection').hidden=!offlineMode;$('ch-live').hidden=!offlineMode||ctx.offline;$('ch-open-offline').href=base+'/offline';
 }
 function renderRoots(){const list=$('ch-roots');list.replaceChildren();for(const path of draftRoots){const n=document.createElement('div');n.className='ch-root';const text=document.createElement('span');text.textContent=path;const b=document.createElement('button');b.textContent='×';b.setAttribute('aria-label',tr`${path} aus Galerie entfernen`);b.disabled=offlineMode;b.onclick=()=>{draftRoots=draftRoots.filter(p=>p!==path);renderRoots();};n.append(text,b);list.append(n);}}
 function indexState(state){$('ch-index-state').textContent=state?.running?tr`${count(state.seen||0)} Medien erfasst · wird eingelesen …`:state?.completed?tr`${count(state.seen||0)} Medien · zuletzt ${new Date(state.completed*1000).toLocaleString(locale)}${state.errors?' · '+state.errors+tr(' Dateien/Ordner nicht lesbar'):''}`:tr('Noch nicht gestartet.');}
 async function load(){
  const v=++version;blocks.clear();pending=[];rendered.clear();canvas.replaceChildren();releaseUrls();
  if(offlineMode){const prefs={...offlineMeta?.settings,...settings};summary=localSummary(offlineItems,prefs,kind);settings=prefs;localGroups=new Map();for(const item of summary.items){const key=groupKey(item,settings.grouping);if(!localGroups.has(key))localGroups.set(key,[]);localGroups.get(key).push(item);}}
  else{const next=await api('summary?kind='+kind);if(v!==version)return;summary=next;settings=summary.settings;}
  updateSettings();indexState(summary.state);$('ch-count').textContent=tr`${mediaCount(summary.total)}${summary.videos?' · '+(summary.videos===1?tr('1 Video'):tr`${count(summary.videos)} Videos`):''}${offlineMode?tr(' · gespeicherte Vorschauen'):''}`;
  $('ch-empty').hidden=summary.total>0;
  if(!summary.total){$('ch-empty').querySelector('h2').textContent=offlineMode?tr('Noch keine Offline-Sammlung'):settings.roots.length?tr('Hier sind noch keine Medien'):tr('Deine Galerie beginnt mit einem Ordner');$('ch-empty').querySelector('p').textContent=offlineMode?tr('Speichere deine Vorschauen bei bestehender Verbindung.'):settings.roots.length?tr('Lies deine ausgewählten Ordner ein oder wähle einen anderen Medientyp.'):tr('Wähle die Ordner aus, die in deiner Zeitleiste erscheinen sollen.');$('ch-choose').hidden=offlineMode;}
  scroll.scrollTop=0;compute();renderRail();schedule();
  if(summary.state?.running&&!offlineMode&&!scanning)scan(false).catch(handleError);
 }
 function compute(){if(!summary)return;geometry=layout(summary.groups,Math.max(50,scroll.clientWidth),settings.tile,settings.grouping!=='rail');canvas.style.height=geometry.height+'px';}
 function renderRail(){rail.replaceChildren();let lastYear='';for(const s of geometry?.sections||[]){const year=s.key.slice(0,4);if(year===lastYear&&summary.groups.length>80)continue;lastYear=year;const b=document.createElement('button');b.textContent=s.key.length===4||s.key.endsWith('-00')?year:s.key.slice(5,7)+'. '+year;b.title=label(s.key);b.setAttribute('aria-label',tr`Zu ${label(s.key)} springen`);b.dataset.period=s.key;b.onclick=()=>{scroll.scrollTo({top:s.start,behavior:'instant'});schedule();};rail.append(b);}}
 function schedule(){if(frame)return;frame=requestAnimationFrame(()=>{frame=0;render();});}
 function blockKey(g,offset){return `${g}:${offset}`;}
 function pump(){while(inflight<3&&pending.length){const {g,offset,key,v}=pending.shift();if(v!==version){blocks.delete(key);continue;}inflight++;
  api(`items?period=${g}&kind=${kind}&offset=${offset}&limit=150`).then(r=>{if(v!==version)return;blocks.set(key,{items:r.items,status:'done'});trimBlocks();schedule();}).catch(e=>{if(v!==version)return;blocks.set(key,{items:[],status:'error'});handleError(e);}).finally(()=>{inflight--;pump();});}}
 function trimBlocks(){if(blocks.size<=24)return;for(const[k,b]of blocks){if(b.status==='done'&&!renderedHas(k)){blocks.delete(k);if(blocks.size<=24)break;}}}
 function renderedHas(k){const [g,off]=k.split(':');for(const value of rendered.values())if(value.section===g&&value.offset===Number(off))return true;return false;}
 function itemAt(section,index){
  if(offlineMode)return localGroups.get(section.key)?.[index];
  const offset=Math.floor(index/150)*150,key=blockKey(section.key,offset),block=blocks.get(key);
  if(!block){blocks.set(key,{status:'loading',items:[]});pending.push({g:section.key,offset,key,v:version});pump();return null;}
  if(block.status==='done'){blocks.delete(key);blocks.set(key,block);return block.items[index-offset];}return null;
 }
 function render(){
  if(!geometry||view!=='timeline')return;
  const rows=visible(geometry,scroll.scrollTop,scroll.clientHeight),keep=new Set(rows.map(r=>r.key));
  for(const[key,value]of rendered)if(!keep.has(key)){value.node.remove();rendered.delete(key);}
  for(const row of rows){let value=rendered.get(row.key);if(!value){const node=document.createElement('div');node.style.top=row.y+'px';node.style.height=row.height+'px';node.className=row.type==='header'?'ch-group-header':'ch-row';
    if(row.type==='header'){if(settings.grouping!=='rail'){const title=document.createElement('h2');title.textContent=label(row.section.key);const small=document.createElement('small');small.textContent=mediaCount(row.section.count);node.append(title,small);}}
    value={node,section:row.section.key,offset:Math.floor(row.row*geometry.columns/150)*150};rendered.set(row.key,value);canvas.append(node);
   }
   if(row.type==='row'){
    const first=row.row*geometry.columns,n=Math.min(geometry.columns,row.section.count-first);
    for(let j=0;j<n;j++){const item=itemAt(row.section,first+j);let tile=value.node.children[j];if(!tile){tile=document.createElement('button');tile.className='ch-tile';tile.style.width=geometry.size+'px';tile.style.height=geometry.size+'px';tile.disabled=true;tile.setAttribute('aria-hidden','true');value.node.append(tile);}
     if(item&&tile.dataset.id!==String(item.id)){tile.dataset.id=item.id;tile.disabled=false;tile.removeAttribute('aria-hidden');tile.setAttribute('aria-label',`${item.kind==='video'?tr('Video'):tr('Foto')} ${item.name}, ${label(item.date.date)}`);tile.title=item.name;tile.onclick=()=>openViewer(item);const img=document.createElement('img');img.alt='';img.decoding='async';const v=version;imageURL(item).then(url=>{if(v!==version||!tile.isConnected)return;if(url)img.src=url;else unavailable();}).catch(unavailable);
      function unavailable(){const text=document.createElement('span');text.className='ch-unavailable';text.textContent=item.kind==='video'?tr('Video · keine Vorschau'):tr('Keine Vorschau');tile.replaceChildren(text);}
      img.onerror=unavailable;tile.replaceChildren(img);if(item.kind==='video'){const badge=document.createElement('span');badge.className='ch-video-badge';badge.innerHTML=icon('play');badge.append(document.createTextNode(duration(item.metadata.duration)));tile.append(badge);}if(collectionIds.has(item.id)&&!offlineMode){const b=document.createElement('span');b.className='ch-local-badge';b.textContent='↓';tile.append(b);}
     }
    }
   }
  }
  const current=geometry.sections.find(s=>s.end>scroll.scrollTop+40);$('ch-floating-date').textContent=current?label(current.key):'';rail.querySelectorAll('button').forEach(b=>b.classList.toggle('active',b.dataset.period===current?.key));
 }
 scroll.addEventListener('scroll',schedule,{passive:true});new ResizeObserver(()=>{compute();rendered.clear();canvas.replaceChildren();schedule();}).observe(scroll);
 async function changeView(next){view=next;for(const name of ['timeline','map','offline','settings','search'])$('ch-'+name+'-view').hidden=name!==view;root.querySelectorAll('[data-view]').forEach(b=>b.classList.toggle('active',b.dataset.view===view));$('ch-title').textContent={search:tr('Suche'),timeline:tr('Zeitleiste'),map:tr('Karte'),offline:tr('Offline'),settings:tr('Einstellungen')}[view];
  if(view==='timeline'){compute();schedule();}if(view==='offline')offlineStatus();if(view==='map')await showMap();if(!ctx.embedded)location.hash=next;
 }
 root.querySelectorAll('[data-view]').forEach(b=>b.onclick=()=>changeView(b.dataset.view).catch(handleError));
 $('ch-display').onclick=()=>{$('ch-display-dialog').showModal();};
 root.querySelector('.ch-brand').onclick=e=>{e.preventDefault();changeView('timeline').catch(handleError);};
 root.querySelectorAll('[data-kind]').forEach(b=>b.onclick=()=>{kind=b.dataset.kind;root.querySelectorAll('[data-kind]').forEach(n=>n.classList.toggle('active',n===b));load().catch(handleError);});
 on('ch-grouping','change',async()=>{await persist({grouping:$('ch-grouping').value});await load();});on('ch-order','change',async()=>{await persist({order:$('ch-order').value});await load();});
 on('ch-density','input',()=>{settings.tile=Number($('ch-density').value);compute();rendered.clear();canvas.replaceChildren();schedule();});on('ch-density','change',()=>persist({tile:Number($('ch-density').value)}));
 on('ch-choose','click',()=>changeView('settings'));
 async function folders(path){folderPath=path;const r=await api('folders?path='+encodeURIComponent(path));$('ch-folder-path').textContent=path;$('ch-select-folder').disabled=path==='/';$('ch-folder-up').disabled=path==='/';const list=$('ch-folder-list');list.replaceChildren();for(const f of r.folders){const b=document.createElement('button');b.innerHTML=icon('folder');b.append(document.createTextNode(f.name));b.onclick=()=>folders(f.path).catch(handleError);list.append(b);}if(!r.folders.length){const p=document.createElement('p');p.textContent=tr('Keine weiteren Unterordner.');list.append(p);}}
 on('ch-add-root','click',async()=>{await folders('/');$('ch-folder-dialog').showModal();});on('ch-folder-up','click',()=>folders(folderPath.substring(0,folderPath.lastIndexOf('/'))||'/'));on('ch-select-folder','click',()=>{if(!draftRoots.includes(folderPath))draftRoots.push(folderPath);renderRoots();$('ch-folder-dialog').close();});
 on('ch-save-settings','click',async()=>{await persist({roots:draftRoots,mode:$('ch-date-mode').value,timezone:$('ch-timezone').value});message(tr('Einstellungen gespeichert.'));await load();if(settings.roots.length)await scan(false);});
 async function scan(restart){if(scanning||offlineMode)return;scanning=true;$('ch-refresh').classList.add('ch-spinning');try{let first=true,s;
  do{s=await api('scan',{method:'POST',body:JSON.stringify({restart:first&&restart})});first=false;indexState(s);message(s.running?tr`${count(s.seen)} Medien erfasst · Indexierung läuft …`:'');if(s.running)await new Promise(resolve=>setTimeout(resolve,80));}while(s.running&&!offlineMode);
  await load();if(s.errors)message(tr`${s.errors} Dateien oder Ordner konnten nicht eingelesen werden.`);
 }finally{scanning=false;$('ch-refresh').classList.remove('ch-spinning');}}
 on('ch-refresh','click',()=>scan(true));on('ch-reindex','click',()=>scan(true));
 on('ch-show-saved','click',async()=>{offlineMode=true;settings={...offlineMeta.settings};await load();await changeView('timeline');message(tr('Gespeicherte Vorschauen · auf diesem Gerät verfügbar.'));});
 on('ch-live','click',async()=>{offlineMode=false;try{await load();message('');}catch(e){offlineMode=true;await load();throw e;}});
 let searchOffset=0,searchMore=false,searchGeneration=0,searchHistory=[];
 async function runSearch(offset=0){
  const generation=++searchGeneration,query=$('ch-search-query').value.trim();
  $('ch-search-status').textContent=tr('Wird geladen …');$('ch-search-prev').disabled=true;$('ch-search-next').disabled=true;
  let result;
  if(offlineMode){const words=query.toLocaleLowerCase().match(/[\p{L}\p{N}_]+/gu)||[];const found=words.length?offlineItems.filter(i=>words.some(w=>(i.name+' '+i.path).toLocaleLowerCase().includes(w))):[];result={items:found.slice(offset,offset+60),more:found.length>offset+60,next:offset+60};}
  else result=await api('search?'+new URLSearchParams({query,offset,limit:60}));
  if(generation!==searchGeneration)return;
  searchOffset=offset;searchMore=result.more;
  const host=$('ch-search-results');host.replaceChildren();
  for(const item of result.items){const tile=document.createElement('button');tile.className='ch-search-tile';tile.setAttribute('aria-label',item.name);tile.onclick=()=>openViewer(item,result.items);const image=document.createElement('img');image.alt='';image.loading='lazy';const url=await imageURL(item);if(generation!==searchGeneration)return;if(url)image.src=url;const name=document.createElement('span');name.textContent=item.name;tile.append(image,name);host.append(tile);}
  $('ch-search-status').textContent=result.items.length?tr`${count(result.items.length)} Treffer auf dieser Seite`:tr('Keine Treffer. Versuche einen anderen Suchbegriff.');
  if(offlineMode)$('ch-search-status').textContent+=' · '+tr('Offline-Suche in gespeicherten Dateinamen und Ordnern.');
  $('ch-search-prev').disabled=!searchHistory.length;$('ch-search-next').disabled=!searchMore;
  $('ch-search-next').dataset.offset=String(result.next??offset+60);
 }
 on('ch-search-form','submit',async e=>{e.preventDefault();searchHistory=[];await runSearch();});
 on('ch-search-next','click',async()=>{searchHistory.push(searchOffset);await runSearch(Number($('ch-search-next').dataset.offset));});
 on('ch-search-prev','click',()=>runSearch(searchHistory.pop()??0));
 let analysisGeneration=0;
 async function showAnalysis(item){
  const generation=++analysisGeneration,details=$('ch-analysis'),body=$('ch-analysis-body');details.open=false;details.hidden=offlineMode;body.replaceChildren();
  if(offlineMode)return;
  details.ontoggle=async()=>{
   if(!details.open||body.childNodes.length)return;
   const status=document.createElement('p');status.textContent=tr('Wird geladen …');body.append(status);
   try{const r=await api('analysis/'+item.id);if(generation!==analysisGeneration)return;body.replaceChildren();
    if(!r.available){status.textContent=r.stale?tr('Die Datei wurde geändert. Eine neue Analyse steht aus.'):tr('Für dieses Medium liegt noch keine Analyse vor.');body.append(status);return;}
    const a=r.metadata.analysis;for(const text of [a.vision.description,[...(a.vision.tags||[]),...(a.vision.objects||[])].join(' · '),a.ocr.text]){if(!text)continue;const p=document.createElement('p');p.textContent=text;body.append(p);}
   }catch(e){if(generation===analysisGeneration)status.textContent=e.message;}
  };
 }
 async function openViewer(item,list){
  showAnalysis(item);currentItem=item;if(list)viewerList=list;else if(offlineMode)viewerList=summary.items;else {const cached=[...blocks.values()].flatMap(b=>b.items||[]);viewerList=[...new Map(cached.map(i=>[i.id,i])).values()].sort((a,b)=>settings.order==='asc'?a.date.sort.localeCompare(b.date.sort)||a.id-b.id:b.date.sort.localeCompare(a.date.sort)||b.id-a.id);}
  $('ch-viewer-name').textContent=item.name;$('ch-viewer-date').textContent=label(item.date.date)+(item.date.precision!=='day'?' · '+(item.date.precision==='month'?tr('Monat bekannt'):tr('Jahr bekannt')):'');
  const source={folder:tr('Ordner'),metadata:tr('Aufnahmedaten'),filename:tr('Dateiname'),mtime:tr('Dateiänderung'),'folder+metadata':tr('Ordner & Aufnahmedaten'),'folder+filename':tr('Ordner & Dateiname')}[item.date.source]||item.date.source;
  $('ch-viewer-detail').textContent=tr`${item.path} · Datum: ${source}${item.date.conflict?tr(' · abweichendes Datum in Ordner/Metadaten'):''}${offlineMode?tr(' · gespeicherte Vorschau'):''}`;
  const host=$('ch-viewer-media');host.replaceChildren();let media;
  if(item.kind==='video'&&!offlineMode){media=document.createElement('video');media.controls=true;media.playsInline=true;media.preload='metadata';media.src=original(item);media.poster=preview(item);media.onerror=()=>message(tr('Dieses Videoformat wird vom Browser nicht unterstützt. Über „Original öffnen“ kannst du es herunterladen.'));}
  else{media=document.createElement('img');media.alt=item.name;const url=offlineMode?await imageURL(item):preview(item,1024);if(url)media.src=url;media.onerror=()=>{const p=document.createElement('p');p.textContent=tr('Keine Vorschau verfügbar. Öffne das Original.');host.replaceChildren(p);};}
  host.append(media);$('ch-original').href=original(item);$('ch-original').hidden=offlineMode;
  const at=viewerList.findIndex(i=>i.id===item.id);$('ch-viewer-prev').disabled=at<=0;$('ch-viewer-next').disabled=at<0||at>=viewerList.length-1;
  if(!$('ch-viewer').open)$('ch-viewer').showModal();
 }
 function closeViewer(){analysisGeneration++;$('ch-viewer').close();$('ch-viewer-media').replaceChildren();}
 async function nextViewer(delta){const at=viewerList.findIndex(i=>i.id===currentItem?.id),next=viewerList[at+delta];if(next)await openViewer(next,viewerList);}
 on('ch-viewer-close','click',closeViewer);on('ch-viewer-prev','click',()=>nextViewer(-1));on('ch-viewer-next','click',()=>nextViewer(1));on('ch-viewer','close',()=>{$('ch-viewer-media').replaceChildren();});
 document.addEventListener('keydown',e=>{if(!$('ch-viewer').open||window.OCP?.Accessibility?.disableKeyboardShortcuts?.())return;if(e.key==='ArrowLeft')nextViewer(-1).catch(handleError);if(e.key==='ArrowRight')nextViewer(1).catch(handleError);});
 async function mapScript(){
  if(window.NextGalleryMap)return;
  if(offlineMode){
   const shell=await caches.open('nextgallery-shell-'+cacheKey),cached=await shell.match(ctx.assets+'js/map.js');
   if(cached){const s=document.createElement('script');s.nonce=document.querySelector('meta[name="csp-nonce"]')?.nonce||document.querySelector('script[nonce]')?.nonce||'';s.textContent=await cached.text();document.head.append(s);if(!window.NextGalleryMap)throw new Error(tr('Die gespeicherte Karte konnte nicht gestartet werden.'));return;}
  }
  await new Promise((resolve,reject)=>{const s=document.createElement('script');s.src=ctx.assets+'js/map.js?v='+encodeURIComponent(ctx.version||'0');s.onload=resolve;s.onerror=()=>reject(new Error(tr('Karte konnte nicht geladen werden.')));document.head.append(s);});
 }
 async function showMap(){
  await mapScript();if(mapHandle){mapHandle.remove();mapHandle=null;}
  mapHandle=window.NextGalleryMap.create($('ch-map'),(item,list)=>openViewer(item,list),imageURL);
  const v=version;let total=0;
  if(offlineMode){const items=offlineItems.filter(i=>i.metadata.gps);mapHandle.add(items);total=items.length;}
  else{let offset=0,more=true;while(more&&view==='map'&&v===version){const r=await api('map?offset='+offset);mapHandle.add(r.items);total+=r.items.length;offset=r.next;more=r.more;$('ch-map-count').textContent=mediaCount(total)+tr(' mit Standort');}}
  if(v===version)$('ch-map-count').textContent=total?mediaCount(total)+tr(' mit Standort'):tr('Keine Medien mit Standortdaten in diesen Ordnern.');
 }
 on('ch-map-reset','click',()=>mapHandle?.reset());
 async function pin(){
  if(pinning||offlineMode)return;if(!db)throw new Error(tr('Der lokale Speicher ist nicht verfügbar.'));
  const reg=await worker();
  const limit=Math.max(1,Math.min(5000,Number($('ch-offline-limit').value)||1000));await persist({offlineLimit:limit});
  pinning=true;pinCancel=false;$('ch-pin').disabled=true;$('ch-pin-cancel').hidden=false;$('ch-offline-progress').hidden=false;$('ch-offline-progress').max=limit;
  try{
   const shell=await caches.open('nextgallery-shell-'+cacheKey);const offlineURL=base+'/offline';
   const resources=[offlineURL,ctx.assets+'js/main.js',ctx.assets+'js/map.js',ctx.assets+'css/main.css'];
   for(const url of resources){const r=await fetch(url,{credentials:'same-origin',cache:'reload'});if(!r.ok||r.redirected)throw new Error(tr('Offline-Ansicht konnte nicht gespeichert werden.'));await shell.put(url,r);}
   await clear(db);collectionIds.clear();let offset=0,saved=0,failed=0,bytes=0,more=true;
   while(more&&saved<limit&&!pinCancel){
    const r=await api(`items?offset=${offset}&limit=150`);offset=r.next;more=r.more;
    for(let i=0;i<r.items.length&&saved<limit&&!pinCancel;i+=4){
     const batch=r.items.slice(i,i+Math.min(4,limit-saved));const results=await Promise.allSettled(batch.map(async item=>{const response=await fetch(preview(item),{credentials:'same-origin'});if(!response.ok||!response.headers.get('content-type')?.startsWith('image/'))throw new Error(tr('Vorschau nicht verfügbar'));return {item,blob:await response.blob()};}));
     for(const result of results){if(result.status==='rejected'){failed++;continue;}const {item,blob}=result.value;if(bytes+blob.size>300*1048576){pinCancel=true;break;}await saveItem(db,item,blob);bytes+=blob.size;saved++;collectionIds.add(item.id);}
     await saveMeta(db,{settings,bytes,saved:Date.now(),count:saved,failed});$('ch-offline-progress').value=saved;message(tr`${count(saved)} Vorschauen gespeichert · ${(bytes/1048576).toFixed(1)} MB${failed?' · '+failed+tr(' ohne Vorschau'):''}`);
    }
    if(!r.next)break;
   }
   await storage();message(tr`${count(saved)} Vorschauen ${pinCancel?tr('bis zum Stopp '):''}gespeichert${failed?' · '+failed+tr(' Vorschauen nicht verfügbar'):''}.${reg?'':tr(' Für erneutes Öffnen ohne Verbindung lade die Offline-Datei herunter oder vertraue dem Serverzertifikat auf diesem Gerät.')}`);
   navigator.storage?.persist?.().catch(()=>{});
  }catch(e){await storage();throw e;}finally{pinning=false;$('ch-pin').disabled=offlineMode;$('ch-pin-cancel').hidden=true;$('ch-offline-progress').hidden=true;}
 }
 on('ch-pin','click',pin);on('ch-pin-cancel','click',()=>{pinCancel=true;});on('ch-clear-offline','click',async()=>{if(pinning){pinCancel=true;return;}if(db)await clear(db);releaseUrls();for(const key of await caches.keys())if(key.startsWith('nextgallery-shell-'))await caches.delete(key);await storage();message(tr('Die lokale Sammlung wurde entfernt.'));if(offlineMode)await load();});
 on('ch-export','click',async()=>{
  if(!db||!offlineItems.length)return;
  $('ch-export').disabled=true;
  try{
   const blobs={};for(const item of offlineItems){const blob=await readBlob(db,item.id);if(!blob)continue;blobs[item.id]=await new Promise((resolve,reject)=>{const r=new FileReader();r.onload=()=>resolve(r.result);r.onerror=()=>reject(new Error(tr('Vorschau konnte nicht exportiert werden.')));r.readAsDataURL(blob);});}
   const shell=await caches.open('nextgallery-shell-'+cacheKey);
   const texts=await Promise.all(['css/main.css','js/main.js','js/map.js'].map(async p=>{const cached=await shell.match(ctx.assets+p);if(!cached)throw new Error(tr('Speichere die Sammlung erneut, um die Offline-Datei zu erstellen.'));return cached.text();}));
   const copy=root.cloneNode(true);const download=copy.querySelector('#ch-download-file');if(download){download.removeAttribute('href');download.hidden=true;}copy.dataset.context=JSON.stringify({...ctx,offline:true,portable:true,aiUrl:null,settings:offlineMeta.settings,items:offlineItems,blobs,meta:offlineMeta});
   for(const name of ['timeline','map','offline','settings','search'])copy.querySelector('#ch-'+name+'-view').hidden=name!=='timeline';
   copy.querySelectorAll('[data-view]').forEach(button=>button.classList.toggle('active',button.dataset.view==='timeline'));
   copy.querySelector('#ch-search-results').replaceChildren();copy.querySelector('#ch-ai-link')?.remove();copy.querySelector('#ch-settings-ai-link')?.remove();copy.querySelector('#ch-canvas').replaceChildren();copy.querySelector('#ch-rail').replaceChildren();copy.querySelector('#ch-map').replaceChildren();copy.querySelector('#ch-title').textContent=tr('Zeitleiste');copy.querySelector('#ch-message').hidden=true;
   copy.querySelectorAll('dialog').forEach(d=>d.removeAttribute('open'));
   const code=s=>s.replace(/<\/script/gi,'<\\/script');
   const html='<!doctype html><html lang="'+(locale.startsWith('de')?'de':'en')+'"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>NextGallery · Offline-Sammlung</title><style>'+texts[0]+'</style><body class="nextgallery-standalone">'+copy.outerHTML+'<script>'+code(texts[2])+'</script><script>'+code(texts[1])+'</script></body></html>';
   const url=URL.createObjectURL(new Blob([html],{type:'text/html'}));const link=$('ch-download-file');if(link.href.startsWith('blob:'))URL.revokeObjectURL(link.href);link.href=url;link.hidden=false;link.className='ch-button';link.textContent=tr('Download wiederholen');$('ch-offline-view').append(link);link.click();message(tr('Offline-Datei erstellt. Sie enthält die gespeicherten Vorschauen und die Karte.'));
  }finally{$('ch-export').disabled=false;}
 });
 document.addEventListener('click',async e=>{const link=e.target.closest('a[href]');if(link&&/\/logout(?:\?|$)/.test(link.href)){e.preventDefault();pinCancel=true;if(db)await clear(db);for(const key of await caches.keys())if(key.startsWith('nextgallery-shell-'))await caches.delete(key);location.href=link.href;}},true);
 window.addEventListener('offline',()=>{offlineMode=true;updateSettings();message(tr('Keine Verbindung. Gespeicherte Vorschauen werden angezeigt.'));load().catch(handleError);});
 window.addEventListener('online',()=>{if(ctx.offline){message(tr('Verbindung wieder verfügbar. Öffne NextGallery in Nextcloud für aktuelle Medien.'));return;}offlineMode=false;message('');load().catch(handleError);});
 if(!ctx.offline){try{const r=await api('settings');settings=r.settings;cacheKey=r.cacheKey;await clearOld();}catch(e){offlineMode=true;message(e.message+tr(' Gespeicherte Vorschauen werden geladen.'));}}
 await storage();if(ctx.offline&&offlineMeta)settings=offlineMeta.settings;updateSettings();await load();
 const initial=location.hash.slice(1);if(['timeline','map','offline','settings','search'].includes(initial))await changeView(initial);
 if(ctx.offline)message(tr('Offline-Sammlung · Zum Aktualisieren NextGallery in Nextcloud öffnen.'));
}
