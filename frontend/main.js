import {tr,locale} from './i18n.mjs';
import {layout,visible,localSummary,groupKey} from './layout.mjs';
import {progressiveSearch} from './search.mjs';
import {openStore,readCollection,readMeta,readBlob,beginCollection,stageItem,commitCollection,discardCollection,clear} from './offline.mjs';
import {createPeopleController} from './faces.mjs';
import {createTimelinePager} from './viewer.mjs';
const root=document.getElementById('nextgallery');
if(root) start().catch(e=>{const m=root.querySelector('#ch-message');m.hidden=false;m.textContent=e.message||tr('NextGallery konnte nicht gestartet werden.');});
async function start(){
 const ctx=JSON.parse(root.dataset.context),$=id=>root.querySelector('#'+id),base=ctx.base.replace(/\/$/,'');
 root.addEventListener('keydown',event=>{
  if(event.key!=='Tab')return;
  const dialog=event.target.closest('dialog[open]');if(!dialog)return;
  const controls=[...dialog.querySelectorAll('button,a[href],input,select,textarea,summary,[tabindex]')]
   .filter(control=>!control.disabled&&control.tabIndex>=0&&control.getClientRects().length&&!control.closest('[hidden],[inert]'));
  const first=controls[0],last=controls.at(-1);if(!first)return;
  if(event.shiftKey&&document.activeElement===first){event.preventDefault();last.focus();}
  else if(!event.shiftKey&&document.activeElement===last){event.preventDefault();first.focus();}
 });
 const icons={people:'<circle cx="12" cy="8" r="4"/><path d="M6 21v-2a6 6 0 0 1 12 0v2"/>',processing:'<path d="M9 3h6l1 4 4 2v6l-4 2-1 4H9l-1-4-4-2V9l4-2Z"/><path d="m10 9 5 3-5 3Z"/>',search:'<circle cx="10" cy="10" r="6"/><path d="m15 15 6 6"/>',gallery:'<rect x="3" y="3" width="18" height="18" rx="3"/><circle cx="16" cy="8" r="1.5"/><path d="m4 18 5-7 6 7 3-4 3 4"/>',map:'<path d="m3 5 6-2 6 2 6-2v16l-6 2-6-2-6 2ZM9 3v16m6-14v16"/>',download:'<path d="M12 3v12m-5-5 5 5 5-5M4 16v5h16v-5"/>',settings:'<path d="M4 7h16M4 17h16M8 4v6m8 4v6"/>',refresh:'<path d="M20 10a8 8 0 1 0-2 8M20 3v7h-7"/>',folder:'<path d="M3 7V4h7l2 3h9v13H3Z"/>',play:'<path d="m8 4 12 8-12 8Z"/>'};
 const icon=name=>`<svg class="ch-icon" viewBox="0 0 24 24" aria-hidden="true">${icons[name]||icons.gallery}</svg>`;
 root.querySelectorAll('[data-icon]').forEach(n=>n.innerHTML=icon(n.dataset.icon));
 let preferenceWork=Promise.resolve(),settingsRevision=0;const pendingPreferences=new Map();
 let settings=ctx.settings,cacheKey=ctx.cacheKey,db=null,offlineItems=[],offlineMeta=null,offlineMode=ctx.offline||!navigator.onLine;
 let summary=null,view='timeline',kind='',version=0,geometry=null,frame=0,blocks=new Map(),pending=[],inflight=0,rendered=new Map(),urls=new Map(),collectionIds=new Set(),localGroups=new Map();
 const positions=new Map(),mapViews=new Map();let switching=false,creatingGallery=false;
 let aiFrame=null,mapGeneration=0,mapLoading=false,mapOffset=0,mapMore=true;
 const hintKey='nextgallery-ai-hint-'+(ctx.accountKey||ctx.cacheKey);let hintDismissed=false;
 try{hintDismissed=localStorage.getItem(hintKey)==='hidden';}catch{}
 $('ch-ai-hint-dismiss')?.addEventListener('click',()=>{hintDismissed=true;$('ch-ai-feature').hidden=true;try{localStorage.setItem(hintKey,'hidden');}catch{}});
 let loadGeneration=0,displayContext=null,viewerPager=null,viewerGeneration=0,viewerBusy=false;
 let scanning=false,pinning=false,pinCancel=false,mapHandle=null,currentItem=null,viewerList=[],folderPath='/',draftRoots=[...settings.roots];
 const peopleController=createPeopleController({root:$('ch-people-view'),dialogRoot:root,api:async(endpoint,options={})=>{const rootUrl=(window.NextGallerySession||window.OC)?.generateUrl?(window.NextGallerySession||window.OC).generateUrl('/apps/nextgallery/api/v1/'):(base+'/api/v1/');const url=rootUrl.replace(/\/+$/,'')+'/'+endpoint.replace(/^\/+/,'');const r=await fetch(url,{credentials:'same-origin',signal:options.signal,...options,headers:{'Content-Type':'application/json',requesttoken:(window.NextGallerySession||window.OC)?.requestToken||'',...options.headers}});if(r.redirected||r.status===401||r.status===403)throw new Error(tr('Die Sitzung ist abgelaufen. Bitte Nextcloud erneut öffnen.'));let data;try{data=await r.json();}catch{throw new Error(tr('Keine Verbindung zu Nextcloud.'));}if(!r.ok){const error=new Error(data.error||tr`Anfrage fehlgeschlagen (${r.status}).`);error.status=r.status;error.retryable=r.status===409;throw error;}return data;},base,getGallery:()=>settings.activeGallery||'default',tr,locale,openViewer:(item,list)=>openViewer(item,list),imageURL:item=>imageURL(item),previewUrl:detectionId=>{const rootUrl=(window.NextGallerySession||window.OC)?.generateUrl?(window.NextGallerySession||window.OC).generateUrl('/apps/nextgallery/api/v1/'):(base+'/api/v1/');return rootUrl.replace(/\/+$/,'')+'/subjects/preview/'+encodeURIComponent(detectionId)+'?gallery='+encodeURIComponent(settings.activeGallery||'default');}});
 const scroll=$('ch-scroll'),canvas=$('ch-canvas'),rail=$('ch-rail');
 function message(text,retry=null){
  const host=$('ch-message');host.replaceChildren(document.createTextNode(text));host.hidden=!text;
  if(retry){const button=document.createElement('button');button.type='button';button.textContent=tr('Erneut versuchen');button.onclick=()=>Promise.resolve(retry()).catch(handleError);host.append(button);}
 }
 function handleError(e){
  if($('ch-viewer').open){const status=$('ch-viewer-status');status.textContent=e.message||tr('Die Anfrage konnte nicht abgeschlossen werden.');status.hidden=false;}
  else message(e.message||tr('Die Anfrage konnte nicht abgeschlossen werden.'),e.retry);
 }
 const on=(id,event,fn)=>$(id).addEventListener(event,e=>Promise.resolve(fn(e)).catch(handleError));
 async function api(path,options={}){
  const sep=path.includes('?')?'&':'?';path+=sep+'gallery='+encodeURIComponent(settings.activeGallery||'default');
  const r=await fetch(base+'/api/'+path,{credentials:'same-origin',...options,headers:{'Content-Type':'application/json',requesttoken:(window.NextGallerySession||window.OC)?.requestToken||'',...options.headers}});
  if(r.redirected||r.status===401||r.status===403)throw new Error(tr('Die Sitzung ist abgelaufen. Bitte Nextcloud erneut öffnen.'));
  let data;try{data=await r.json();}catch{throw new Error(tr('Keine Verbindung zu Nextcloud.'));}
  if(!r.ok){const error=new Error(data.error||tr`Anfrage fehlgeschlagen (${r.status}).`);error.retryable=r.status===409&&data.retryable===true;throw error;}return data;
 }
 function label(key){const [y,m,d]=key.split('-').map(Number);if(!m)return String(y);return new Intl.DateTimeFormat(locale,{year:'numeric',month:'long',...(d?{day:'numeric'}:{}),timeZone:'UTC'}).format(new Date(Date.UTC(y,m-1,d||1)));}
 function count(n){return new Intl.NumberFormat(locale).format(n);}
 function mediaCount(n){return tr`${count(n)} ${n===1?tr('Medium'):tr('Medien')}`;}
 function duration(n){return n==null?tr('Video'):`${Math.floor(n/60)}:${String(Math.floor(n%60)).padStart(2,'0')}`;}
 function preview(item,size=384){return base+`/preview/${item.id}?size=${size}&v=${encodeURIComponent(item.etag)}&collection=${cacheKey}`;}
 function original(item){return base+`/original/${item.id}`;}
 async function storage(){
  if(ctx.portable){offlineItems=ctx.items;offlineMeta=ctx.meta;collectionIds=new Set(offlineItems.map(i=>i.id));offlineStatus();return;}
  try{if(db)db.close();db=await openStore(cacheKey);offlineMeta=await readMeta(db);offlineItems=await readCollection(db);collectionIds=new Set(offlineItems.map(i=>i.id));}
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
 const registryKey='nextgallery-collections-'+(ctx.accountKey||ctx.cacheKey);
 function registered(){try{return JSON.parse(localStorage.getItem(registryKey)||'[]');}catch{return [];}}
 async function clearOld(){
  // Named galleries retain their saved collections when another gallery is opened.
  const keys=new Set(registered());keys.add(cacheKey);try{localStorage.setItem(registryKey,JSON.stringify([...keys]));}catch{}
 }
 async function persist(patch){
  if(offlineMode){settings={...settings,...patch};await load();return;}
  let r;for(let attempt=0;attempt<10;attempt++){try{r=await api('settings',{method:'PUT',body:JSON.stringify({settings:patch})});break;}catch(error){if(!error.retryable||attempt===9)throw error;await new Promise(resolve=>setTimeout(resolve,Math.min(750,150*(attempt+1))));}}
  settings=r.settings;settingsRevision++;
  if(displayContext&&displayContext!==galleryContext()){clearPresentation();summary=null;geometry=null;displayContext=null;}
  if(cacheKey!==r.cacheKey){if(db){db.close();db=null;}releaseUrls();cacheKey=r.cacheKey;await clearOld();await storage();}
  updateSettings();
 }
 function changePreference(key,value,{refresh=false,reload=false}={}){
  let stored=false;const token=Symbol(key);pendingPreferences.set(key,{value,token});updateSettings();
  $('ch-settings-status').textContent=tr('Wird gespeichert …');
  const task=preferenceWork.catch(()=>{}).then(async()=>{
   try{await persist({[key]:value});stored=true;if(refresh){searchGeneration++;searchRunner.cancel();searchToken=null;searchHistory=[];await load();}}
   finally{if(pendingPreferences.get(key)?.token===token)pendingPreferences.delete(key);updateSettings();}
  });
  preferenceWork=task;
  return task.then(async()=>{
   if(reload){await preferenceWork;location.reload();return;}
   if(!pendingPreferences.size)$('ch-settings-status').textContent=tr('Einstellungen gespeichert.');
  },e=>{$('ch-settings-status').textContent=tr(stored?'Einstellungen gespeichert. Galerie konnte nicht aktualisiert werden.':'Nicht gespeichert. Bitte erneut versuchen.');throw e;});
 }
 function updateSettings(){
  const display={...settings,...Object.fromEntries([...pendingPreferences].map(([key,pending])=>[key,pending.value]))};
  $('ch-gallery-name').textContent=galleryName();$('ch-appearance').value=display.appearance||'system';$('ch-language').value=display.language||'system';root.dataset.appearance=display.appearance||'system';
  $('ch-offline-gallery').replaceChildren();for(const g of settings.galleries||[]){const o=document.createElement('option');o.value=g.id;o.textContent=g.name==='Galerie'?tr('Galerie'):g.name;$('ch-offline-gallery').append(o);}$('ch-offline-gallery').value=settings.activeGallery;
  function syncSegmented(controlId,val){const seg=root.querySelectorAll(`.ch-segmented[data-control="${controlId}"]`);seg.forEach(group=>group.querySelectorAll('.ch-seg-btn').forEach(btn=>{const a=String(btn.dataset.value)===String(val);btn.classList.toggle('active',a);btn.setAttribute('role','radio');btn.setAttribute('aria-checked',a?'true':'false');btn.tabIndex=a?0:-1;}));}
  $('ch-grouping').value=display.grouping;$('ch-order').value=display.order;$('ch-density').value=display.tile;$('ch-date-mode').value=display.mode;if(![...$('ch-timezone').options].some(o=>o.value===display.timezone)){const option=document.createElement('option');option.value=option.textContent=display.timezone;$('ch-timezone').append(option);}$('ch-timezone').value=display.timezone;$('ch-offline-limit').value=settings.offlineLimit;
  syncSegmented('ch-order',display.order);syncSegmented('ch-grouping',display.grouping);syncSegmented('ch-density',display.tile);syncSegmented('ch-appearance',display.appearance);syncSegmented('ch-language',display.language);
  $('ch-source-count').textContent=settings.roots.length===1?tr('Ein Ordner ausgewählt'):settings.roots.length?tr`${settings.roots.length} Ordner ausgewählt`:tr('Keine Ordner ausgewählt');
  draftRoots=[...settings.roots];renderRoots();$('ch-refresh').disabled=offlineMode;for(const id of ['ch-add-root','ch-reindex','ch-pin','ch-new-gallery','ch-save-gallery','ch-people-refresh','ch-people-reset']){const el=$(id);if(el)el.disabled=offlineMode||pinning;}
  root.querySelectorAll('[data-ai-entry]').forEach(entry=>entry.hidden=offlineMode||(entry.id==='ch-ai-feature'&&hintDismissed)||(entry.id==='ch-ai-view'&&view!=='ai'));
  for(const id of ['ch-appearance','ch-language','ch-date-mode','ch-timezone'])$(id).disabled=offlineMode;
  root.querySelectorAll('.ch-segmented[data-control=ch-language] button').forEach(button=>button.disabled=offlineMode);root.querySelectorAll('.ch-segmented[data-control=ch-appearance] button').forEach(button=>button.disabled=offlineMode);
  $('ch-connection').hidden=!offlineMode;$('ch-live').hidden=!offlineMode||ctx.offline;$('ch-open-offline').href=base+'/offline';
 }
 function renderRoots(){const list=$('ch-roots');list.replaceChildren();for(const path of draftRoots){const n=document.createElement('div');n.className='ch-root';const text=document.createElement('span');text.textContent=path;const b=document.createElement('button');b.innerHTML='<svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>';b.setAttribute('aria-label',tr`${path} aus Galerie entfernen`);b.disabled=offlineMode;b.onclick=()=>{draftRoots=draftRoots.filter(p=>p!==path);renderRoots();};n.append(text,b);list.append(n);}}
 function indexState(state){$('ch-index-state').textContent=state?.running?tr`${count(state.seen||0)} Medien erfasst · wird eingelesen …`:state?.completed?(state.partial?tr('Teilweise aktualisiert · nicht erreichbare Ordner bleiben erhalten')+' · ':'')+tr`${count(state.seen||0)} Medien · zuletzt ${new Date(state.completed*1000).toLocaleString(locale)}${state.errors?' · '+state.errors+tr(' Dateien/Ordner nicht lesbar'):''}`:tr('Noch nicht gestartet.');}
 function galleryContext(){return JSON.stringify([settings.activeGallery,settings.roots,settings.mode,settings.timezone,settings.order,settings.grouping,settings.tile,kind,cacheKey]);}
 function clearPresentation(){
  version++;closeViewer();blocks.clear();pending=[];rendered.clear();canvas.replaceChildren();releaseUrls();
  if(mapHandle){mapViews.set(settings.activeGallery,mapHandle.position());mapHandle.remove();mapHandle=null;}
  mapGeneration++;mapLoading=false;mapOffset=0;mapMore=true;
 }
 async function load(){
  const own=++loadGeneration,revision=settingsRevision,context=galleryContext();
  const preserve=summary&&displayContext===context,position=preserve?scroll.scrollTop:(positions.get(settings.activeGallery)||0);
  if(!preserve){clearPresentation();summary=null;geometry=null;closeViewer();}
  scroll.setAttribute('aria-busy','true');
  try{
   let next;
   if(offlineMode){const prefs={...offlineMeta?.settings,...settings};next=localSummary(offlineItems,prefs,kind);next.settings=prefs;}
   else next=await api('summary?kind='+kind);
   if(own!==loadGeneration||context!==galleryContext())return;
   if(revision!==settingsRevision)return load();
   if(preserve)clearPresentation();
   summary=next;settings=next.settings;displayContext=galleryContext();if(preserve)message('');
   localGroups=new Map();if(offlineMode)for(const item of summary.items){const key=groupKey(item,settings.grouping);if(!localGroups.has(key))localGroups.set(key,[]);localGroups.get(key).push(item);}
   updateSettings();indexState(summary.state);$('ch-count').textContent=tr`${mediaCount(summary.total)}${summary.videos?' · '+(summary.videos===1?tr('1 Video'):tr`${count(summary.videos)} Videos`):''}${offlineMode?tr(' · gespeicherte Vorschauen'):''}`;
   $('ch-empty').hidden=summary.total>0;
   if(!summary.total){$('ch-empty').querySelector('h2').textContent=offlineMode?tr('Noch keine Offline-Sammlung'):settings.roots.length?tr('Hier sind noch keine Medien'):tr('Deine Galerie beginnt mit einem Ordner');$('ch-empty').querySelector('p').textContent=offlineMode?tr('Speichere deine Vorschauen bei bestehender Verbindung.'):settings.roots.length?tr('Lies deine ausgewählten Ordner ein oder wähle einen anderen Medientyp.'):tr('Wähle die Ordner aus, die in deiner Zeitleiste erscheinen sollen.');$('ch-choose').hidden=offlineMode;}
   compute();scroll.scrollTop=position;renderRail();schedule();if(view==='map')await showMap();
   if(summary.state?.running&&!offlineMode&&!scanning)scan(false).catch(handleError);
  }catch(error){
   if(own!==loadGeneration)return;
   if(preserve)error.message=tr('Aktualisierung fehlgeschlagen. Bisheriger Stand bleibt sichtbar.')+' '+error.message;
   error.retry=()=>load();throw error;
  }finally{if(own===loadGeneration)scroll.setAttribute('aria-busy','false');}
 }
 function compute(){if(!summary)return;geometry=layout(summary.groups,Math.max(50,scroll.clientWidth),settings.tile,settings.grouping!=='rail',settings.tile>=250?2:settings.tile>=170?3:settings.tile>=130?4:settings.tile>=90?5:6);canvas.style.height=geometry.height+'px';}
 function renderRail(){rail.replaceChildren();let lastYear='';for(const s of geometry?.sections||[]){const year=s.key.slice(0,4);if(year===lastYear&&summary.groups.length>80)continue;lastYear=year;const b=document.createElement('button');b.textContent=s.key.length===4||s.key.endsWith('-00')?year:s.key.slice(5,7)+'. '+year;b.title=label(s.key);b.setAttribute('aria-label',b.textContent+' · '+tr`Zu ${label(s.key)} springen`);b.dataset.period=s.key;b.onclick=()=>{$('ch-period-dialog').close();scroll.scrollTo({top:s.start,behavior:'instant'});schedule();};rail.append(b);}}
 function schedule(){if(frame)return;frame=requestAnimationFrame(()=>{frame=0;render();});}
 function blockKey(g,offset){return `${g}:${offset}`;}
 function pump(){while(inflight<3&&pending.length){const {g,offset,key,v}=pending.shift();if(v!==version){blocks.delete(key);continue;}inflight++;
  api(`items?period=${g}&kind=${kind}&offset=${offset}&limit=150`).then(r=>{if(v!==version)return;blocks.set(key,{items:r.items,status:'done'});trimBlocks();schedule();}).catch(e=>{if(v!==version)return;blocks.set(key,{items:[],status:'error'});e.retry=()=>retryBlock(key);handleError(e);schedule();}).finally(()=>{inflight--;pump();});}}
 function retryBlock(key){if(blocks.get(key)?.status!=='error')return;blocks.delete(key);schedule();}
 function retryFailedBlocks(){for(const[key,block]of blocks)if(block.status==='error')blocks.delete(key);schedule();}
 function trimBlocks(){if(blocks.size<=24)return;for(const[k,b]of blocks){if(b.status!=='loading'&&!renderedHas(k)){blocks.delete(k);if(blocks.size<=24)break;}}}
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
  const needed=new Set();for(const row of rows)if(row.type==='row'){
   const first=row.row*geometry.columns,last=Math.min(row.section.count-1,first+geometry.columns-1);
   needed.add(blockKey(row.section.key,Math.floor(first/150)*150));needed.add(blockKey(row.section.key,Math.floor(last/150)*150));
  }
  pending=pending.filter(job=>{if(needed.has(job.key))return true;blocks.delete(job.key);return false;});
  for(const[key,value]of rendered)if(!keep.has(key)){value.node.remove();rendered.delete(key);}
  for(const row of rows){let value=rendered.get(row.key);if(!value){const node=document.createElement('div');node.style.top=row.y+'px';node.style.height=row.height+'px';node.className=row.type==='header'?'ch-group-header':'ch-row';
    if(row.type==='header'){if(settings.grouping!=='rail'){const title=document.createElement('h2');title.textContent=label(row.section.key);const small=document.createElement('small');small.textContent=mediaCount(row.section.count);node.append(title,small);}}
    value={node,section:row.section.key,offset:Math.floor(row.row*geometry.columns/150)*150};rendered.set(row.key,value);canvas.append(node);
   }
   if(row.type==='row'){
    const first=row.row*geometry.columns,n=Math.min(geometry.columns,row.section.count-first);
    for(let j=0;j<n;j++){const item=itemAt(row.section,first+j);let tile=value.node.children[j];if(!tile){tile=document.createElement('button');tile.className='ch-tile';tile.style.width=geometry.size+'px';tile.style.height=geometry.size+'px';tile.disabled=true;tile.setAttribute('aria-hidden','true');value.node.append(tile);}
     const failed=blocks.get(blockKey(row.section.key,Math.floor((first+j)/150)*150))?.status==='error';
     if(!item&&failed){tile.disabled=false;tile.removeAttribute('aria-hidden');tile.dataset.error='true';tile.textContent=tr('Erneut versuchen');tile.setAttribute('aria-label',tr('Erneut versuchen')+' · '+tr('Vorschauen erneut laden'));tile.onclick=()=>retryBlock(blockKey(row.section.key,Math.floor((first+j)/150)*150));}
     else if(!item&&tile.dataset.error){tile.disabled=true;tile.setAttribute('aria-hidden','true');tile.replaceChildren();delete tile.dataset.error;}
     if(item&&tile.dataset.id!==String(item.id)){delete tile.dataset.error;tile.dataset.id=item.id;tile.disabled=false;tile.removeAttribute('aria-hidden');tile.setAttribute('aria-label',`${item.kind==='video'?tr('Video'):tr('Foto')} ${item.name}, ${label(item.date.date)}`);tile.title=item.name;tile.onclick=()=>openViewer(item,null,{period:row.section.key,offset:Math.floor((first+j)/150)*150});const img=document.createElement('img');img.alt='';img.decoding='async';const v=version;imageURL(item).then(url=>{if(v!==version||!tile.isConnected)return;if(url)img.src=url;else unavailable();}).catch(unavailable);
      function unavailable(){const text=document.createElement('span');text.className='ch-unavailable';text.textContent=item.kind==='video'?tr('Video · keine Vorschau'):tr('Keine Vorschau');tile.replaceChildren(text);}
      img.onerror=unavailable;tile.replaceChildren(img);if(item.kind==='video'){const badge=document.createElement('span');badge.className='ch-video-badge';badge.innerHTML=icon('play');badge.append(document.createTextNode(duration(item.metadata.duration)));tile.append(badge);}if(collectionIds.has(item.id)&&!offlineMode){const b=document.createElement('span');b.className='ch-local-badge';b.textContent='↓';tile.append(b);}
     }
    }
   }
  }
  const current=geometry.sections.find(s=>s.end>scroll.scrollTop+40);$('ch-floating-date').textContent=current?label(current.key):'';rail.querySelectorAll('button').forEach(b=>b.classList.toggle('active',b.dataset.period===current?.key));updateFastScroll(current);
 }
 scroll.addEventListener('scroll',schedule,{passive:true});new ResizeObserver(()=>{
  const previous=geometry;compute();
  if(previous?.size!==geometry?.size||previous?.columns!==geometry?.columns){rendered.clear();canvas.replaceChildren();}
  schedule();
 }).observe(scroll);
 async function changeView(next){if(view==='search'&&next!=='search'){searchGeneration++;searchRunner.cancel();}view=next;for(const name of ['timeline','map','people','offline','settings','search','ai']){const section=$('ch-'+name+'-view');if(section)section.hidden=name!==view;};root.querySelectorAll('[data-view]').forEach(b=>b.classList.toggle('active',b.dataset.view===view));$('ch-title').textContent={search:tr('Suche'),timeline:tr('Galerie'),map:tr('Karte'),people:tr('Personen'),offline:tr('Offline'),settings:tr('Einstellungen'),ai:tr('KI-Verarbeitung')}[view];
  const globalView=['settings','offline','ai'].includes(view);if($('ch-top'))$('ch-top').hidden=view==='ai';$('ch-gallery-switch').hidden=globalView;$('ch-count').hidden=globalView||view==='people';$('ch-refresh').hidden=globalView||view==='people';$('ch-header-search').hidden=globalView||view==='search'||view==='people';if(view==='timeline'){retryFailedBlocks();compute();schedule();}if(view==='offline')offlineStatus();if(view==='map')await showMap();if(view==='people')peopleController?.show();else peopleController?.hide();if(view==='ai')showAi();if(!ctx.embedded)location.hash=next;
 }
 root.querySelectorAll('[data-view]').forEach(b=>b.onclick=e=>{e.preventDefault();changeView(b.dataset.view).catch(handleError);});
 function showAi(url=ctx.aiUrl){
  if(offlineMode||!ctx.aiUrl){changeView('timeline').catch(handleError);return;}
  const target=new URL(url,location.href),allowed=new URL(ctx.aiUrl,location.href);
  if(target.origin!==location.origin||![allowed.pathname,base+'/processing'].includes(target.pathname))return;
  target.searchParams.set('embedded','1');
  if(!aiFrame){
   aiFrame=document.createElement('iframe');aiFrame.id='ch-ai-frame';aiFrame.title=tr('KI-Verarbeitung');
   aiFrame.addEventListener('load',()=>{
    try{const doc=aiFrame.contentDocument;if(!doc||doc.URL==='about:blank')return;const shell=doc.querySelector('.mai-shell');if(shell)shell.dataset.appearance=settings.appearance||'system';else message(tr('Die Sitzung ist abgelaufen. Bitte Nextcloud erneut öffnen.'));}catch{handleError(new Error(tr('Keine Verbindung zu Nextcloud.')));}
   });
   $('ch-ai-view').append(aiFrame);
  }
  if(aiFrame.src!==target.href)aiFrame.src=target.href;
  try{const shell=aiFrame.contentDocument?.querySelector('.mai-shell');if(shell)shell.dataset.appearance=settings.appearance||'system';}catch{}
 }
 root.addEventListener('click',event=>{
  const link=event.target.closest('a[href]');if(!link||!ctx.aiUrl||new URL(link.href).pathname!==new URL(ctx.aiUrl,location.href).pathname)return;
  event.preventDefault();changeView('ai').catch(handleError);
 });
 window.addEventListener('message',event=>{
  if(!aiFrame||event.origin!==location.origin||event.source!==aiFrame.contentWindow||event.data?.type!=='nextgallery-ai-navigation')return;
  if(event.data.page==='gallery')changeView('timeline').catch(handleError);
  else if(event.data.page==='account')showAi(base+'/processing');
  else if(event.data.page==='admin')showAi(ctx.aiUrl);
  else if(event.data.page==='people')changeView('people').catch(handleError);
 });
 $('ch-display').onclick=()=>{$('ch-display-dialog').showModal();};
 $('ch-period').onclick=()=>{$('ch-period-dialog').showModal();};
 root.querySelector('.ch-brand').onclick=e=>{e.preventDefault();changeView('timeline').catch(handleError);};
 root.querySelectorAll('[data-kind]').forEach(b=>b.onclick=()=>{kind=b.dataset.kind;searchGeneration++;searchRunner.cancel();searchToken=null;searchHistory=[];if(view==='search'){$('ch-search-status').textContent=tr('Wird geladen …');$('ch-search-prev').disabled=true;$('ch-search-next').disabled=true;}root.querySelectorAll('[data-kind]').forEach(n=>n.classList.toggle('active',n.dataset.kind===kind));load().then(()=>{if(view==='search'&&$('ch-search-query').value.trim())return runSearch();}).catch(handleError);});
 on('ch-grouping','change',()=>changePreference('grouping',$('ch-grouping').value,{refresh:true}));
 on('ch-order','change',()=>changePreference('order',$('ch-order').value,{refresh:true}));
 on('ch-density','change',()=>changePreference('tile',Number($('ch-density').value),{refresh:true}));
 on('ch-appearance','change',()=>changePreference('appearance',$('ch-appearance').value));
 on('ch-language','change',()=>changePreference('language',$('ch-language').value,{reload:true}));
 on('ch-date-mode','change',()=>changePreference('mode',$('ch-date-mode').value,{refresh:true}));
 on('ch-timezone','change',()=>changePreference('timezone',$('ch-timezone').value,{refresh:true}));
 root.querySelectorAll('.ch-segmented[data-control]').forEach(seg=>{const target=$(seg.dataset.control);seg.querySelectorAll('.ch-seg-btn').forEach(btn=>{btn.onclick=()=>{const val=btn.dataset.value;seg.querySelectorAll('.ch-seg-btn').forEach(b=>{const a=b===btn;b.classList.toggle('active',a);b.setAttribute('aria-checked',a?'true':'false');});if(target){target.value=val;target.dispatchEvent(new Event('input',{bubbles:true}));target.dispatchEvent(new Event('change',{bubbles:true}));}};});});
 root.querySelectorAll('.ch-segmented').forEach(group=>group.addEventListener('keydown',event=>{if(!['ArrowLeft','ArrowRight','ArrowUp','ArrowDown','Home','End'].includes(event.key))return;const buttons=[...group.querySelectorAll('button')].filter(button=>!button.disabled),index=buttons.indexOf(document.activeElement);if(index<0)return;event.preventDefault();const next=event.key==='Home'?0:event.key==='End'?buttons.length-1:(index+(['ArrowLeft','ArrowUp'].includes(event.key)?-1:1)+buttons.length)%buttons.length;buttons[next].focus();buttons[next].click();}));
 root.querySelectorAll('dialog').forEach(d=>{d.addEventListener('keydown',event=>{if(event.key!=='Escape')return;event.preventDefault();event.stopPropagation();const cancel=new Event('cancel',{cancelable:true});d.dispatchEvent(cancel);if(!cancel.defaultPrevented)d.close();});d.addEventListener('click',e=>{if(e.target===d)d.close();});});
 on('ch-choose','click',()=>editGallery(false));
 function galleryName(){const g=settings.galleries?.find(g=>g.id===settings.activeGallery);return g?.name==='Galerie'?tr('Galerie'):g?.name||tr('Galerie');}
 async function switchGallery(id){
  if(switching||pinning)return;if(id===settings.activeGallery){$('ch-galleries-dialog').close();return;}
  searchToken=null;switching=true;positions.set(settings.activeGallery,scroll.scrollTop);clearPresentation();summary=null;geometry=null;displayContext=null;searchGeneration++;searchRunner.cancel();peopleController?.onGalleryChange(id);
  if(mapHandle){mapViews.set(settings.activeGallery,mapHandle.position());mapHandle.remove();mapHandle=null;}
  closeViewer();$('ch-galleries-dialog').close();message('');
  try{if(offlineMode){const g=settings.galleries.find(g=>g.id===id);if(!g)return;settings={...settings,activeGallery:id,roots:g.roots};if(db)db.close();db=null;releaseUrls();cacheKey=g.cacheKey;await storage();}else await persist({activeGallery:id});await load();if(view==='people')peopleController?.refresh();$('ch-search-results').replaceChildren();$('ch-search-status').textContent='';if($('ch-search-pages'))$('ch-search-pages').hidden=true;}
  finally{switching=false;}
 }
 function galleryMenu(){
  const host=$('ch-gallery-list');host.replaceChildren();
  for(const g of settings.galleries||[]){const row=document.createElement('div');row.className='ch-gallery-choice';const select=document.createElement('button');select.className='ch-gallery-select';select.disabled=pinning;select.setAttribute('aria-current',g.id===settings.activeGallery?'true':'false');const name=document.createElement('strong');name.textContent=g.name==='Galerie'?tr('Galerie'):g.name;const detail=document.createElement('small');detail.textContent=g.roots.length===1?tr('Ein Ordner ausgewählt'):g.roots.length?tr`${g.roots.length} Ordner ausgewählt`:tr('Keine Ordner ausgewählt');select.append(name,detail);select.onclick=()=>switchGallery(g.id).catch(handleError);const edit=document.createElement('button');edit.textContent=tr('Bearbeiten');edit.disabled=offlineMode||pinning;edit.onclick=async()=>{try{await switchGallery(g.id);editGallery(false);}catch(e){handleError(e);}};row.append(select,edit);host.append(row);}
  $('ch-new-gallery').disabled=offlineMode||pinning;$('ch-galleries-dialog').showModal();
 }
 function editGallery(create){
  creatingGallery=create;$('ch-galleries-dialog').close();draftRoots=create?[]:[...settings.roots];renderRoots();$('ch-gallery-edit-name').value=create?'':galleryName();$('ch-gallery-editor-title').textContent=tr(create?'Neue Galerie':'Galerie bearbeiten');$('ch-gallery-editor').showModal();
 }
 $('ch-gallery-switch').onclick=galleryMenu;$('ch-manage-galleries').onclick=galleryMenu;$('ch-new-gallery').onclick=()=>editGallery(true);
 on('ch-save-gallery','click',async()=>{
  const name=$('ch-gallery-edit-name').value.trim();if(!name){$('ch-gallery-edit-name').reportValidity();return;}
  await persist(creatingGallery?{createGallery:name,roots:draftRoots}:{galleryName:name,roots:draftRoots});$('ch-gallery-editor').close();await load();
 });
 on('ch-offline-gallery','change',()=>switchGallery($('ch-offline-gallery').value));
 let dragging=false,fastTimer;
 function updateFastScroll(current){
  const control=$('ch-fast-scroll'),max=Math.max(0,scroll.scrollHeight-scroll.clientHeight),fraction=max?scroll.scrollTop/max:0;
  control.hidden=(summary?.total||0)<=30;control.setAttribute('aria-valuenow',String(Math.round(fraction*100)));control.setAttribute('aria-valuetext',current?label(current.key):'');
  const y=Math.max(0,control.clientHeight-48)*fraction;$('ch-fast-thumb').style.top=y+'px';$('ch-fast-label').style.top=Math.min(Math.max(0,y-8),Math.max(0,control.clientHeight-76))+'px';
  $('ch-fast-date').textContent=current?label(current.key):'';
  const row=visible(geometry,scroll.scrollTop,1,0).find(r=>r.type==='row'),item=row?itemAt(row.section,row.row*geometry.columns):null;$('ch-fast-folder').textContent=item?.path?.slice(0,item.path.lastIndexOf('/'))||'';
 }
 function revealPosition(){clearTimeout(fastTimer);$('ch-fast-scroll').classList.add('show-position');if(!dragging)fastTimer=setTimeout(()=>$('ch-fast-scroll').classList.remove('show-position'),500);}
 const fast=$('ch-fast-scroll');
 function seek(y){const track=fast.getBoundingClientRect(),fraction=Math.max(0,Math.min(1,(y-track.top-24)/Math.max(1,track.height-48)));scroll.scrollTop=fraction*Math.max(0,scroll.scrollHeight-scroll.clientHeight);schedule();revealPosition();}
 fast.addEventListener('pointerdown',e=>{if(e.button!==0)return;e.preventDefault();dragging=true;fast.setPointerCapture(e.pointerId);seek(e.clientY);});
 fast.addEventListener('pointermove',e=>{if(dragging){e.preventDefault();seek(e.clientY);}});
 const endDrag=()=>{dragging=false;revealPosition();};fast.addEventListener('pointerup',endDrag);fast.addEventListener('pointercancel',endDrag);fast.addEventListener('lostpointercapture',()=>{if(dragging)endDrag();});
 fast.addEventListener('keydown',e=>{const delta={ArrowDown:.05,ArrowUp:-.05,PageDown:.2,PageUp:-.2}[e.key],max=Math.max(0,scroll.scrollHeight-scroll.clientHeight);if(delta!==undefined||['Home','End'].includes(e.key)){e.preventDefault();scroll.scrollTop=e.key==='Home'?0:e.key==='End'?max:scroll.scrollTop+max*delta;schedule();revealPosition();}});
 scroll.addEventListener('scroll',()=>{positions.set(settings.activeGallery,scroll.scrollTop);revealPosition();},{passive:true});
 async function folders(path){folderPath=path;const r=await api('folders?path='+encodeURIComponent(path));$('ch-folder-path').textContent=path;$('ch-select-folder').disabled=path==='/';$('ch-folder-up').disabled=path==='/';const list=$('ch-folder-list');list.replaceChildren();for(const f of r.folders){const b=document.createElement('button');b.innerHTML=icon('folder');b.append(document.createTextNode(f.name));b.onclick=()=>folders(f.path).catch(handleError);list.append(b);}if(!r.folders.length){const p=document.createElement('p');p.textContent=tr('Keine weiteren Unterordner.');list.append(p);}}
 on('ch-add-root','click',async()=>{await folders('/');$('ch-folder-dialog').showModal();});on('ch-folder-up','click',()=>folders(folderPath.substring(0,folderPath.lastIndexOf('/'))||'/'));on('ch-select-folder','click',()=>{if(!draftRoots.includes(folderPath))draftRoots.push(folderPath);renderRoots();$('ch-folder-dialog').close();});
 async function scan(restart){if(scanning||offlineMode)return;scanning=true;$('ch-refresh').classList.add('ch-spinning');try{let first=true,s;
  do{s=await api('scan',{method:'POST',body:JSON.stringify({restart:first&&restart})});first=false;indexState(s);message(s.running?tr`${count(s.seen)} Medien erfasst · Indexierung läuft …`:'');if(s.running)await new Promise(resolve=>setTimeout(resolve,80));}while(s.running&&!offlineMode);
  await load();if(s.errors)message(tr`${s.errors} Dateien oder Ordner konnten nicht eingelesen werden.`);
 }finally{scanning=false;$('ch-refresh').classList.remove('ch-spinning');}}
 on('ch-refresh','click',()=>scan(true));on('ch-reindex','click',()=>scan(true));
 on('ch-show-saved','click',async()=>{if(!offlineMeta)return;offlineMode=true;settings={...offlineMeta.settings};await load();await changeView('timeline');message(tr('Gespeicherte Vorschauen · auf diesem Gerät verfügbar.'));});
 on('ch-live','click',async()=>{offlineMode=false;try{await load();message('');}catch(e){offlineMode=true;await load();throw e;}});
 let searchOffset=0,searchMore=false,searchGeneration=0,searchHistory=[];
 const searchRunner=progressiveSearch({
  request:(params,signal)=>{
   const queryParams={...params,kind};
   const audioOnly=Boolean($('ch-search-audio-only')?.checked);
   if(audioOnly)queryParams.source='audio';
   return api('search?'+new URLSearchParams(queryParams),{signal});
  },
  show:showSearch
 });
 let searchToken=null,searchTokenPhase='text';
 async function showSearch(result,{stage,current}){
  if(!current())return;
  if(stage!=='fallback'){searchToken=result.search_token??null;searchTokenPhase=stage==='hybrid'?'hybrid':'text';}
  const host=$('ch-search-results');
  // Keep existing buttons, focus and open viewers through background enrichment.
  if(stage!=='fallback'){
   const existing=new Map([...host.children].map(tile=>[Number(tile.dataset.id),tile])),tiles=[];
   for(const item of result.items){
    let tile=existing.get(item.id);
    if(!tile){
     tile=document.createElement('button');tile.className='ch-search-tile';tile.dataset.id=String(item.id);
     const isAudioMatch=item.search_match?.sources?.includes('audio');
     tile.setAttribute('aria-label',item.name+(isAudioMatch?' · '+tr('Treffer im Videoton'):''));
     const image=document.createElement('img');image.alt='';image.loading='lazy';const unavailable=()=>{const placeholder=document.createElement('span');placeholder.className='ch-unavailable';placeholder.textContent=tr(item.kind==='video'?'Video · keine Vorschau':'Keine Vorschau');image.replaceWith(placeholder);};image.onerror=unavailable;const url=await imageURL(item);if(!current())return;if(url)image.src=url;const name=document.createElement('span');name.textContent=item.name;tile.append(image,name);
     if(isAudioMatch){const badge=document.createElement('span');badge.className='ch-match-audio';badge.textContent=tr('Videoton');tile.append(badge);}
     if(!url)unavailable();
    }
    tile.onclick=()=>openViewer(item,result.items);tiles.push(tile);
   }
   if(!current())return;
   const focused=host.contains(document.activeElement)?document.activeElement:null;
   const wanted=new Set(tiles);for(const tile of [...host.children])if(!wanted.has(tile))tile.remove();
   // Insert only out-of-place nodes, so unchanged results retain keyboard focus.
   tiles.forEach((tile,index)=>{if(host.children[index]!==tile)host.insertBefore(tile,host.children[index]||null);});
   if(focused?.isConnected&&document.activeElement!==focused)focused.focus({preventScroll:true});
  }
  searchMore=result.more;
  $('ch-search-status').textContent=result.index_state==='preparing'?tr('Die Suche wird vorbereitet. Bitte in Kürze erneut suchen.'):result.items.length?tr`${count(result.items.length)} Treffer auf dieser Seite`:result.embedding_state==='pending'?tr('Noch keine Texttreffer.'):tr('Keine Treffer. Versuche einen anderen Suchbegriff.');
  if(!offlineMode&&result.embedding_state==='pending')$('ch-search-status').textContent+=' · '+tr('Bildsuche läuft …');
  if(!offlineMode&&result.embedding_state==='unavailable')$('ch-search-status').textContent+=' · '+tr('Bildsuche nicht erreichbar. Ergebnisse aus der Textsuche.');
  if(!offlineMode&&['not_indexed','model_changed'].includes(result.embedding_state))$('ch-search-status').textContent+=' · '+tr('Bildsuche noch nicht vorbereitet. Ergebnisse aus der Textsuche.');
  if(!offlineMode&&result.index_state==='updating')$('ch-search-status').textContent+=' · '+tr('Suchindex wird im Hintergrund aktualisiert.');
  if(!offlineMode&&result.mode==='hybrid')$('ch-search-status').textContent+=' · '+tr('Textsuche und ähnliche Bildinhalte.');
  if(offlineMode)$('ch-search-status').textContent+=' · '+tr('Offline-Suche in gespeicherten Dateinamen und Ordnern.');
  $('ch-search-prev').disabled=!searchHistory.length;$('ch-search-next').disabled=!searchMore;
  if($('ch-search-pages'))$('ch-search-pages').hidden=!searchHistory.length&&!searchMore;
  $('ch-search-next').dataset.offset=String(result.next??searchOffset+60);
 }
 async function runSearch(offset=0){
  const generation=++searchGeneration,query=$('ch-search-query').value.trim();searchOffset=offset;
  searchRunner.cancel();$('ch-search-status').textContent=tr('Wird geladen …');$('ch-search-prev').disabled=true;$('ch-search-next').disabled=true;
  const audioOnly=Boolean($('ch-search-audio-only')?.checked),source=audioOnly?'audio':'';
  if(offlineMode){const words=query.toLocaleLowerCase().match(/[\p{L}\p{N}_]+/gu)||[];const found=words.length?offlineItems.filter(i=>words.some(w=>(i.name+' '+i.path).toLocaleLowerCase().includes(w))):[];await showSearch({items:found.slice(offset,offset+60),more:found.length>offset+60,next:offset+60},{stage:'text',current:()=>generation===searchGeneration});}
  else if(searchToken){const queryParams={query,kind,offset,limit:60,phase:searchTokenPhase,search_token:searchToken};if(source)queryParams.source=source;const result=await api('search?'+new URLSearchParams(queryParams));await showSearch(result,{stage:searchTokenPhase,current:()=>generation===searchGeneration});}
  else {searchToken=null;const queryParams={query,offset,limit:60};if(source)queryParams.source=source;const job=await searchRunner.run(queryParams);job?.finished.catch(handleError);}
 }
 on('ch-search-form','submit',async e=>{e.preventDefault();searchHistory=[];searchToken=null;await runSearch();});
 on('ch-search-audio-only','change',async()=>{searchHistory=[];searchToken=null;await runSearch();});
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
    if(r.stale){const warning=document.createElement('p');warning.textContent=tr('Diese Analyse gehört zu einem älteren Dateistand. Sie bleibt bis zum ausdrücklichen Löschen erhalten.');body.append(warning);}
    const a=r.metadata.analysis;
    for(const text of [a.vision?.description,[...(a.vision?.tags||[]),...(a.vision?.objects||[])].join(' · '),a.ocr?.text]){if(!text)continue;const p=document.createElement('p');p.textContent=text;body.append(p);}
    if(a.audio){
     const au=a.audio;
     if(au.no_audio){const p=document.createElement('p');p.textContent=tr('Keine Sprache in der Audiospur erkannt.');body.append(p);}
     else if(au.text){
      const p=document.createElement('p');p.className='ch-analysis-audio';
      const strong=document.createElement('strong');strong.textContent=tr('Videoton')+': ';
      p.append(strong,document.createTextNode(au.text));body.append(p);
      if(au.segments?.length){
       const segDetails=document.createElement('details');segDetails.className='ch-analysis-segments';
       const summary=document.createElement('summary');summary.textContent=tr('Zeitabschnitte anzeigen ({count})').replace('{count}',count(au.segments.length));
       const list=document.createElement('div');list.className='ch-analysis-segments-list';
       const fmt=sec=>{const m=Math.floor(sec/60),s=Math.floor(sec%60);return `${String(m).padStart(2,'0')}:${String(s).padStart(2,'0')}`;};
       for(const s of au.segments){const seg=document.createElement('p');const time=document.createElement('span');time.className='ch-analysis-segment-time';time.textContent=`[${fmt(s.start)} - ${fmt(s.end)}] `;seg.append(time,document.createTextNode(s.text));list.append(seg);}
       segDetails.append(summary,list);body.append(segDetails);
      }
     }
    }
   }catch(e){if(generation===analysisGeneration)status.textContent=e.message;}
  };
 }
 async function openViewer(item,list,options={}){
  const viewerVersion=version,own=++viewerGeneration;if($('ch-viewer-status'))$('ch-viewer-status').hidden=true;showAnalysis(item);currentItem=item;
  if(options.period&&!offlineMode){
   const snapshot=version,viewerContext=galleryContext(),initialId=item.id;
   const seed=blocks.get(blockKey(options.period,options.offset))?.items||[item];
   viewerPager=createTimelinePager({groups:summary.groups,period:options.period,offset:options.offset,itemId:initialId,seed,
    current:()=>snapshot===version&&viewerContext===galleryContext(),
    request:params=>api('items?'+new URLSearchParams({...params,kind}))});
  }else if(!options.keepPager)viewerPager=null;
  if(list)viewerList=list;else if(offlineMode)viewerList=summary.items;else {const cached=[...blocks.values()].flatMap(b=>b.items||[]);viewerList=[...new Map(cached.map(i=>[i.id,i])).values()].sort((a,b)=>settings.order==='asc'?a.date.sort.localeCompare(b.date.sort)||a.id-b.id:b.date.sort.localeCompare(a.date.sort)||b.id-a.id);}
  $('ch-viewer-name').textContent=item.name;$('ch-viewer-date').textContent=label(item.date.date)+(item.date.precision!=='day'?' · '+(item.date.precision==='month'?tr('Monat bekannt'):tr('Jahr bekannt')):'');
  const source={folder:tr('Ordner'),metadata:tr('Aufnahmedaten'),filename:tr('Dateiname'),mtime:tr('Dateiänderung'),'folder+metadata':tr('Ordner & Aufnahmedaten'),'folder+filename':tr('Ordner & Dateiname')}[item.date.source]||item.date.source;
  const facesDetail=item.face_detection_ids?.length?` · ${item.face_detection_ids.length===1?tr('1 Gesicht erkannt'):tr('{count} Gesichter erkannt').replace('{count}',count(item.face_detection_ids.length))}`:'';
  $('ch-viewer-detail').textContent=tr`${item.path} · Datum: ${source}${item.date.conflict?tr(' · abweichendes Datum in Ordner/Metadaten'):''}${facesDetail}${offlineMode?tr(' · gespeicherte Vorschau'):''}`;
  const host=$('ch-viewer-media');host.replaceChildren();let media;
  if(item.kind==='video'&&!offlineMode){media=document.createElement('video');media.controls=true;media.playsInline=true;media.preload='metadata';media.src=original(item);media.poster=preview(item);media.onerror=()=>message(tr('Dieses Videoformat wird vom Browser nicht unterstützt. Über „Original öffnen“ kannst du es herunterladen.'));}
  else{media=document.createElement('img');media.alt=item.name;const url=offlineMode?await imageURL(item):preview(item,1024);if(viewerVersion!==version||own!==viewerGeneration)return;if(url)media.src=url;media.onerror=()=>{const p=document.createElement('p');p.textContent=tr('Keine Vorschau verfügbar. Öffne das Original.');host.replaceChildren(p);};}
  host.append(media);$('ch-original').href=original(item);$('ch-original').hidden=offlineMode;
  updateViewerButtons();
  if(!$('ch-viewer').open)$('ch-viewer').showModal();
 }
 function updateViewerButtons(){
  const at=viewerList.findIndex(i=>i.id===currentItem?.id);
  $('ch-viewer-prev').disabled=viewerBusy||(viewerPager?!viewerPager.canMove(-1):at<=0);
  $('ch-viewer-next').disabled=viewerBusy||(viewerPager?!viewerPager.canMove(1):at<0||at>=viewerList.length-1);
 }
 function closeViewer(){viewerGeneration++;analysisGeneration++;viewerPager=null;$('ch-viewer').close();$('ch-viewer-media').replaceChildren();}
 async function nextViewer(delta){
  if(viewerBusy)return;const pager=viewerPager,own=viewerGeneration;
  viewerBusy=true;updateViewerButtons();$('ch-viewer-media').setAttribute('aria-busy','true');$('ch-viewer-status').textContent=tr('Wird geladen …');$('ch-viewer-status').hidden=false;
  try{
   const at=viewerList.findIndex(i=>i.id===currentItem?.id);
   const next=pager?await pager.move(delta):viewerList[at+delta];
   if(own!==viewerGeneration||!$('ch-viewer').open)return;
   if(next)await openViewer(next,pager?null:viewerList,{keepPager:!!pager});
  }finally{viewerBusy=false;$('ch-viewer-media').setAttribute('aria-busy','false');if($('ch-viewer-status').textContent===tr('Wird geladen …'))$('ch-viewer-status').hidden=true;updateViewerButtons();}
 }
 on('ch-viewer-close','click',closeViewer);on('ch-viewer-prev','click',()=>nextViewer(-1));on('ch-viewer-next','click',()=>nextViewer(1));on('ch-viewer','close',()=>{viewerGeneration++;viewerPager=null;$('ch-viewer-media').replaceChildren();});
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
  const mapVersion=version;await mapScript();if(mapVersion!==version||view!=='map')return;
  if(mapHandle)mapHandle.resize();
  else{
   mapHandle=window.NextGalleryMap.create($('ch-map'),(item,list)=>openViewer(item,list),imageURL);
   const saved=mapViews.get(settings.activeGallery);if(saved)mapHandle.restore(saved);
   mapOffset=0;mapMore=true;
   if(offlineMode){const items=offlineItems.filter(i=>i.metadata.gps);mapHandle.add(items);mapMore=false;$('ch-map-count').textContent=mediaCount(items.length)+tr(' mit Standort');return;}
  }
  if(mapLoading||!mapMore)return;
  const own=mapGeneration,handle=mapHandle;mapLoading=true;
  try{
   while(mapMore&&mapVersion===version&&view==='map'){
    const result=await api('map?offset='+mapOffset);
    if(own!==mapGeneration||mapVersion!==version)return;
    if(result.more&&(!Number.isInteger(result.next)||result.next<=mapOffset))throw new Error(tr('Die Galerie wurde geändert. Bitte erneut versuchen.'));
    handle.add(result.items);mapOffset=result.next??(mapOffset+result.items.length);mapMore=result.more;
    $('ch-map-count').textContent=mediaCount(mapOffset)+tr(' mit Standort');
   }
   if(!mapMore&&!mapOffset)$('ch-map-count').textContent=tr('Keine Medien mit Standortdaten in diesen Ordnern.');
  }finally{if(own===mapGeneration)mapLoading=false;}
 }
 on('ch-map-reset','click',()=>mapHandle?.reset());
 async function pin(){
  if(pinning||offlineMode)return;
  if(!db)throw new Error(tr('Der lokale Speicher ist nicht verfügbar.'));
  const limit=Math.max(1,Math.min(5000,Number($('ch-offline-limit').value)||1000));
  pinning=true;pinCancel=false;updateSettings();
  $('ch-pin-cancel').hidden=false;$('ch-offline-progress').hidden=false;
  let targetDb=db,generation=null,committed=false;
  try{
   const reg=await worker();
   await persist({offlineLimit:limit});targetDb=db;
   const savedSettings=structuredClone(settings),targetKey=cacheKey;
   $('ch-offline-progress').max=limit;
   generation=await beginCollection(targetDb);
   const offlineURL=base+'/offline';
   const resources=[offlineURL,ctx.assets+'js/main.js',ctx.assets+'js/map.js',ctx.assets+'css/main.css'];
   // Small shell assets stay in memory; the old shell is retained during download.
   const shellResources=[];
   for(const url of resources){
    const response=await fetch(url,{credentials:'same-origin',cache:'reload'});
    if(!response.ok||response.redirected)throw new Error(tr('Offline-Ansicht konnte nicht gespeichert werden.'));
    shellResources.push([url,response]);
   }
   let offset=0,saved=0,bytes=0,more=true;
   while(more&&saved<limit&&!pinCancel){
    const result=await api(`items?offset=${offset}&limit=150`);
    more=result.more;
    if(more&&(!Number.isInteger(result.next)||result.next<=offset))throw new Error(tr('Die Galerie wurde geändert. Bitte erneut versuchen.'));
    offset=result.next;
    for(let i=0;i<result.items.length&&saved<limit&&!pinCancel;i+=4){
     const batch=result.items.slice(i,i+Math.min(4,limit-saved));
     const responses=await Promise.all(batch.map(async item=>{
      const response=await fetch(preview(item),{credentials:'same-origin'});
      if(!response.ok||!response.headers.get('content-type')?.startsWith('image/'))throw new Error(tr('Vorschau nicht verfügbar'));
      const blob=await response.blob();if(!blob.size)throw new Error(tr('Vorschau nicht verfügbar'));
      return {item,blob};
     }));
     for(const {item,blob}of responses){
      if(pinCancel)break;
      if(bytes+blob.size>300*1048576)throw new Error(tr('Die Sammlung überschreitet 300 MB. Wähle weniger Vorschauen.'));
      await stageItem(targetDb,generation,item,blob);bytes+=blob.size;saved++;
     }
     $('ch-offline-progress').value=saved;
     message(tr`${count(saved)} Vorschauen vorbereitet · ${(bytes/1048576).toFixed(1)} MB`);
    }
   }
   if(pinCancel){message(tr('Download gestoppt. Die bisherige Sammlung bleibt erhalten.'));return;}
   if(targetKey!==cacheKey)throw new Error(tr('Die Galerie wurde geändert. Bitte erneut versuchen.'));
   const shell=await caches.open('nextgallery-shell-'+targetKey);
   for(const[url,response]of shellResources)await shell.put(url,response);
   await commitCollection(targetDb,generation,{settings:savedSettings,bytes,saved:Date.now(),count:saved,failed:0});
   committed=true;releaseUrls();await storage();
   message(tr`${count(saved)} Vorschauen gespeichert.`+(reg?'':tr(' Für erneutes Öffnen ohne Verbindung lade die Offline-Datei herunter oder vertraue dem Serverzertifikat auf diesem Gerät.')));
   navigator.storage?.persist?.().catch(()=>{});
  }catch(error){
   error.message=tr('Nicht aktualisiert. Die bisherige Sammlung bleibt erhalten.')+' '+error.message;
   error.retry=()=>pin();throw error;
  }finally{
   if(generation&&!committed)await discardCollection(targetDb,generation).catch(()=>{});
   pinning=false;$('ch-pin-cancel').hidden=true;$('ch-offline-progress').hidden=true;updateSettings();
  }
 }
 function confirmOfflineRemoval(){const dialog=$('ch-offline-confirm');return new Promise(resolve=>{let settled=false;const done=value=>{if(settled)return;settled=true;dialog.close();resolve(value);};$('ch-offline-confirm-cancel').onclick=()=>done(false);$('ch-offline-confirm-ok').onclick=()=>done(true);dialog.oncancel=event=>{event.preventDefault();done(false);};dialog.onclose=()=>done(false);dialog.showModal();$('ch-offline-confirm-cancel').focus();});}
 on('ch-pin','click',pin);on('ch-pin-cancel','click',()=>{pinCancel=true;});on('ch-clear-offline','click',async()=>{if(pinning)pinCancel=true;if(!await confirmOfflineRemoval())return;if(db)await clear(db);releaseUrls();for(const key of await caches.keys())if(key==='nextgallery-shell-'+cacheKey)await caches.delete(key);await storage();message(tr('Die lokale Sammlung wurde entfernt.'));if(offlineMode)await load();});
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
   copy.querySelector('#ch-search-results').replaceChildren();copy.querySelectorAll('[data-ai-entry]').forEach(entry=>entry.remove());copy.querySelector('#ch-canvas').replaceChildren();copy.querySelector('#ch-rail').replaceChildren();copy.querySelector('#ch-map').replaceChildren();copy.querySelector('#ch-title').textContent=tr('Galerie');copy.querySelector('#ch-message').hidden=true;
   copy.querySelectorAll('dialog').forEach(d=>d.removeAttribute('open'));
   const code=s=>s.replace(/<\/script/gi,'<\\/script');
   const html='<!doctype html><html lang="'+(locale.startsWith('de')?'de':'en')+'"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>NextGallery · Offline-Sammlung</title><style>'+texts[0]+'</style><body class="nextgallery-standalone">'+copy.outerHTML+'<script>'+code(texts[2])+'</script><script>'+code(texts[1])+'</script></body></html>';
   const url=URL.createObjectURL(new Blob([html],{type:'text/html'}));const link=$('ch-download-file');if(link.href.startsWith('blob:'))URL.revokeObjectURL(link.href);link.href=url;link.hidden=false;link.className='ch-button';link.textContent=tr('Download wiederholen');$('ch-offline-view').append(link);link.click();message(tr('Offline-Datei erstellt. Sie enthält die gespeicherten Vorschauen und die Karte.'));
  }finally{$('ch-export').disabled=false;}
 });
 document.addEventListener('click',async e=>{const link=e.target.closest('a[href]');if(link&&/\/logout(?:\?|$)/.test(link.href)){e.preventDefault();pinCancel=true;if(db)await clear(db);for(const key of registered()){indexedDB.deleteDatabase('nextgallery-'+key);await caches.delete('nextgallery-shell-'+key);}localStorage.removeItem(registryKey);location.href=link.href;}},true);
 window.addEventListener('offline',()=>{offlineMode=true;if(view==='ai')changeView('timeline').catch(handleError);updateSettings();message(tr('Keine Verbindung. Gespeicherte Vorschauen werden angezeigt.'));load().catch(handleError);});
 window.addEventListener('online',()=>{if(ctx.offline){message(tr('Verbindung wieder verfügbar. Öffne NextGallery in Nextcloud für aktuelle Medien.'));return;}offlineMode=false;message('');load().catch(handleError);});
 if(!ctx.offline){try{const r=await api('settings');settings=r.settings;cacheKey=r.cacheKey;await clearOld();}catch(e){offlineMode=true;message(e.message+tr(' Gespeicherte Vorschauen werden geladen.'));}}
 await storage();if(ctx.offline&&offlineMeta)settings=offlineMeta.settings;updateSettings();await load();
 const initial=location.hash.slice(1);if(['timeline','map','people','offline','settings','search','ai'].includes(initial))await changeView(initial);
 if(ctx.offline)message(tr('Offline-Sammlung · Zum Aktualisieren NextGallery in Nextcloud öffnen.'));
}
