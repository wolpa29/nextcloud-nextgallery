(() => {
'use strict';
window.MediaAIWorkspace={create({root,api,tr,el,configuration}){
 const $=id=>document.getElementById(id),number=n=>Number(n||0).toLocaleString(window.MediaAIi18n.language),nav=$('app-navigation')?.classList.contains('mai-navigation')?$('app-navigation'):null;
 const views={overview:['Übersicht','Bestand, Fortschritt und nächste Ausführung.','mai-pipeline-status'],library:['Dateien','Ordner erkunden und Analysestatus prüfen.','mai-library'],processing:['Verarbeitung','Auswahl, Zeitplan und Verarbeitung.','mai-pipeline-settings'],activity:['Aktivitäten','Dateien, Ergebnisse und Laufzeiten.','mai-pipeline-logs'],settings:['Einstellungen','Modelle, Prompts und Speichergrenzen.','mai-settings'],maintenance:['Wartung','Logs und Analysedaten verwalten.','mai-pipeline-maintenance']};
 let loadedPages=false;
 let current='overview',restricted=false,path='',data=null,generation=0,busy=false,lastRefresh=0,treeData=new Map(),expanded=new Set(['']);
 const labels={done:'Bearbeitet',pending:'Offen',changed:'Geändert',error:'Fehler',publishing:'Speichern ausstehend',running:'In Bearbeitung',unselected:'Nicht ausgewählt'};
 const icon=(type)=>{const n=el('span');n.className='mai-node-icon';n.setAttribute('aria-hidden','true');n.innerHTML=type==='folder'?'<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"><path d="M3 7a2 2 0 0 1 2-2h5l2 3h7a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2Z"/></svg>':type==='video'?'<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"><rect x="4" y="3" width="16" height="18" rx="3"/><path d="m10 8 6 4-6 4Z"/></svg>':'<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"><rect x="3" y="3" width="18" height="18" rx="3"/><path d="m3 16 5-5 5 5 3-3 5 5"/><circle cx="15" cy="8" r="1"/></svg>';return n;};
 function badge(state,text){const n=el('span',text===undefined?(labels[state]||state):text);n.className='mai-file-status';n.dataset.state=state;n.setAttribute('aria-label',tr(labels[state]||state));return n;}
 function linkFor(file){const a=el('a',file.name,true);a.href=OC.generateUrl('/f/'+encodeURIComponent(file.file_id));a.title=file.path;return a;}
 function button(text,action,className){const n=el('button',text);n.type='button';if(className)n.className=className;n.onclick=action;return n;}
 function route(){const [slug,query]=location.hash.slice(1).split('?');const view=Object.keys(views).find(v=>v===slug||views[v][2]===slug)||'overview';return {view,path:new URLSearchParams(query||'').get('path')||''};}
 function show(view,options={}){
  if(!views[view])view='overview';if(restricted&&view!=='settings')view='settings';if(options.fromHistory&&root.dataset.viewReady==='1'&&current===view&&(options.path??path)===path)return;current=view;root.dataset.viewReady='1';
  if(options.path!==undefined)path=options.path;
  root.querySelectorAll('[data-view]').forEach(section=>section.hidden=section.dataset.view!==view);
  $('mai-page-title').textContent=tr(views[view][0]);$('mai-page-description').textContent=tr(views[view][1]);
  const links=nav?nav.querySelectorAll('[data-nav-target]'):root.querySelectorAll('[data-nav-target]');
  for(const link of links){const slug=Object.keys(views).find(v=>views[v][2]===link.dataset.navTarget);link.closest('li').hidden=restricted&&slug!=='settings';link.classList.toggle('is-active',slug===view);if(slug===view)link.setAttribute('aria-current','page');else link.removeAttribute('aria-current');}
  if(nav){nav.dataset.collapsed='true';$('mai-nav-toggle').setAttribute('aria-expanded','false');}
  const hash='#'+view+(view==='library'&&path?'?path='+encodeURIComponent(path):'');
  if(location.hash!==hash){if(options.fromHistory)history.replaceState(null,'',hash);else history.pushState(null,'',hash);}
  root.scrollTop=0;root.dispatchEvent(new Event('media-ai-view'));
  if(configuration()&&(view==='overview'||view==='library'))refresh();
 }
 function enterFolder(next){path=next;$('mai-library-search').value='';$('mai-library-filter').value='all';show('library',{path});}
 if(nav){window.MediaAIi18n.apply(nav);$('mai-nav-toggle').onclick=()=>{const open=nav.dataset.collapsed==='true';nav.dataset.collapsed=String(!open);$('mai-nav-toggle').setAttribute('aria-expanded',String(open));};}
 else {const inline=el('ul');inline.className='mai-inline-navigation';for(const [slug,view] of Object.entries(views)){const li=el('li'),a=el('a',view[0]);a.href='#'+slug;a.dataset.navTarget=view[2];li.append(a);inline.append(li);}root.querySelector('.mai-header').after(inline);}
 (nav||root).querySelectorAll('[data-nav-target]').forEach(link=>link.onclick=e=>{e.preventDefault();show(Object.keys(views).find(v=>views[v][2]===link.dataset.navTarget));});
 if(nav)nav.querySelector('.mai-nav-brand').onclick=e=>{e.preventDefault();show('overview');};
 root.querySelectorAll('[data-open-view]').forEach(n=>n.onclick=()=>show(n.dataset.openView));
 window.addEventListener('hashchange',()=>{const next=route();show(next.view,{path:next.path,fromHistory:true});});window.addEventListener('popstate',()=>{const next=route();show(next.view,{path:next.path,fromHistory:true});});
 const next=route();show(next.view,{path:next.path,fromHistory:true});
 function renderOverview(r){
  const c=r.counts,percent=c.total?100*c.done/c.total:0,error=c.total?100*c.error/c.total:0;
  $('mai-total').textContent=number(c.total);$('mai-stock-types').textContent=tr('{images} Bilder · {videos} Videos',{images:number(c.images),videos:number(c.videos)});
  $('mai-percent').textContent=percent.toLocaleString(window.MediaAIi18n.language,{maximumFractionDigits:1})+' %';$('mai-ring').style.setProperty('--done',percent+'%');$('mai-ring').style.setProperty('--error',(percent+error)+'%');$('mai-ring').setAttribute('aria-label',tr('{done} von {total} Medien bearbeitet',{done:number(c.done),total:number(c.total)}));
  $('mai-stock-metrics').replaceChildren();for(const [state,value,label] of [['done',c.done,'Bearbeitet'],['pending',c.total-c.done,'Noch offen'],['error',c.error,'Fehler']]){const n=button('',()=>{$('mai-library-filter').value=state;show('library',{path:''});},'mai-metric');const title=el('span',label),valueNode=el('strong',number(value),true);n.dataset.state=state;n.append(title,valueNode);$('mai-stock-metrics').append(n);}
  const schedule=r.schedule;$('mai-next-time').textContent=schedule.enabled?new Date(schedule.next_at*1000).toLocaleString(window.MediaAIi18n.language,{weekday:'short',hour:'2-digit',minute:'2-digit',timeZone:schedule.timezone}):tr('Manueller Start');
  $('mai-next-count').textContent=tr('{count} Dateien stehen an',{count:number(schedule.eligible)});$('mai-next-hint').textContent=tr(schedule.enabled?'Täglich · {timezone}. Vorschau auf Basis des aktuellen Bestands.':'Täglicher Lauf ist deaktiviert. Vorschau auf Basis des aktuellen Bestands.',{timezone:schedule.timezone});
  $('mai-folder-progress').replaceChildren();for(const f of r.roots||r.folders){const n=button('',()=>enterFolder(f.path),'mai-folder-progress-row');const info=el('span');info.append(icon('folder'),el('span',f.name,true));const ratio=el('span',number(f.counts.done)+' / '+number(f.counts.total),true),bar=el('span');bar.className='mai-mini-progress';bar.style.setProperty('--progress',(f.counts.total?100*f.counts.done/f.counts.total:0)+'%');n.append(info,ratio,badge(f.status),bar);$('mai-folder-progress').append(n);}
  if(!r.folders.length)$('mai-folder-progress').append(el('p','Keine Unterordner in der Auswahl.'));
  $('mai-recent').replaceChildren();for(const file of r.recent){const row=el('div');row.className='mai-recent-row';const info=el('div');info.append(linkFor(file),el('small',new Date(file.updated_at*1000).toLocaleString(window.MediaAIi18n.language),true));row.append(badge('done',''),info,button('Ergebnis',()=>openResult(file),'mai-text-button'));$('mai-recent').append(row);}if(!r.recent.length)$('mai-recent').append(el('p','Noch keine Dateien bearbeitet.'));
  $('mai-inventory-freshness').textContent=tr('Bestand: {time} · Neue Dateien und Modelländerungen werden beim nächsten Start geprüft.',{time:new Date(r.generated_at*1000).toLocaleTimeString(window.MediaAIi18n.language)});
 }
 async function expandFolder(folder){if(expanded.has(folder.path)){expanded.delete(folder.path);renderTree();return;}expanded.add(folder.path);renderTree();if(!treeData.has(folder.path)){try{const r=await api('library?'+new URLSearchParams({path:folder.path}));treeData.set(folder.path,r.folders);renderTree();}catch(e){messageError(e);}}}
 function renderTree(){
  $('mai-tree').replaceChildren();$('mai-library-root').classList.toggle('is-active',path==='');
  const append=(parent,container,level)=>{for(const folder of treeData.get(parent)||[]){const item=el('div');item.className='mai-tree-node';const row=el('div');row.className='mai-tree-row';row.style.setProperty('--level',level);row.classList.toggle('is-active',path===folder.path);const toggle=button(expanded.has(folder.path)?'−':'+',()=>expandFolder(folder),'mai-tree-toggle');toggle.setAttribute('aria-label',tr('Unterordner: {name}',{name:folder.name}));toggle.setAttribute('aria-expanded',String(expanded.has(folder.path)));const name=button('',()=>enterFolder(folder.path),'mai-tree-name');name.title=folder.path;name.append(icon('folder'),el('span',folder.name,true));row.append(toggle,name,badge(folder.status,''));item.append(row);container.append(item);if(expanded.has(folder.path))append(folder.path,item,level+1);}};append('',$('mai-tree'),0);
 }
 function renderLibrary(r,append=false){
  treeData.set(path,r.folders);let ancestor='';for(const part of path.split('/').filter(Boolean)){ancestor+=(ancestor?'/':'')+part;expanded.add(ancestor);}renderTree();$('mai-breadcrumbs').replaceChildren(button('Auswahl',()=>enterFolder('')));
  let built='';for(const part of path.split('/').filter(Boolean)){built+=(built?'/':'')+part;const target=built;$('mai-breadcrumbs').append(el('span','/'),button(part,()=>enterFolder(target)));}
  const c=r.folder.counts;$('mai-folder-heading').replaceChildren(el('h3',path?path.split('/').at(-1):tr('Alle ausgewählten Dateien'),true),el('p',tr('{done} von {total} bearbeitet · {pending} offen',{done:number(c.done),total:number(c.total),pending:number(c.total-c.done)})));
  if(!append)$('mai-file-list').replaceChildren();
  if(!append&&$('mai-library-filter').value==='all'&&!$('mai-library-search').value){for(const f of r.folders){const row=button('',()=>enterFolder(f.path),'mai-file-row mai-folder-row');const info=el('div');info.className='mai-file-info';info.append(el('strong',f.name,true),el('small',tr('{done} von {total} bearbeitet',{done:number(f.counts.done),total:number(f.counts.total)})));row.append(icon('folder'),info,badge(f.status),el('span','›'));$('mai-file-list').append(row);}}
  for(const f of r.files){const row=el('div');row.className='mai-file-row';row.dataset.fileId=f.file_id;const info=el('div');info.className='mai-file-info';info.append(linkFor(f),el('small',f.path,true));if(f.error){const error=el('small',f.error,true);error.className='mai-error';info.append(error);}row.append(icon(f.mime.startsWith('video/')?'video':'image'),info,badge(String(r.current_file_id)===String(f.file_id)?'running':f.status));if(f.status==='done')row.append(button('Ergebnis',()=>openResult(f),'mai-text-button'));else row.append(el('span'));$('mai-file-list').append(row);}
  if(!$('mai-file-list').childElementCount)$('mai-file-list').append(el('p','Keine passenden Dateien.'));
  $('mai-file-count').textContent=r.matched===0&&r.folders.length?tr('{folders} Ordner · {total} Medien',{folders:number(r.folders.length),total:number(c.total)}):tr('{shown} von {total} Dateien',{shown:number(Math.min(r.offset+r.files.length,r.matched)),total:number(r.matched)});$('mai-files-more').hidden=r.next_offset===null;
 }
 function messageError(error){$('mai-message').textContent=error.message;$('mai-message').classList.add('mai-error');}
 async function refresh(append=false){
  if(restricted||!configuration())return;loadedPages=append;const request=++generation;busy=true;$('mai-library-refresh').disabled=true;
  const forLibrary=current==='library',query=new URLSearchParams({path:forLibrary?path:'',filter:forLibrary?$('mai-library-filter').value:'all',query:forLibrary?$('mai-library-search').value:'',offset:String(append?data?.next_offset||0:0)});
  try{if(forLibrary&&path){let prefix='';const parents=[''];for(const part of path.split('/').slice(0,-1)){prefix+=(prefix?'/':'')+part;parents.push(prefix);}for(const parent of parents){if(!treeData.has(parent)){const level=await api('library?'+new URLSearchParams({path:parent}));treeData.set(parent,level.folders);}}}const r=await api('library?'+query);if(request!==generation)return;data=r;lastRefresh=Date.now();if(forLibrary)renderLibrary(r,append);else{treeData.set('',r.folders);renderOverview(r);}}
  catch(e){if(request===generation){messageError(e);if(forLibrary&&!$('mai-file-list').childElementCount)$('mai-file-list').append(el('p','Dateien konnten nicht geladen werden.'));}}
  finally{if(request===generation){busy=false;$('mai-library-refresh').disabled=false;}}
 }
 $('mai-library-root').onclick=()=>enterFolder('');$('mai-library-filter').onchange=()=>refresh();$('mai-library-refresh').onclick=()=>refresh();$('mai-files-more').onclick=()=>refresh(true);
 let searchTimer; $('mai-library-search').oninput=()=>{clearTimeout(searchTimer);searchTimer=setTimeout(()=>refresh(),250);};
 const resultDialog=el('dialog');resultDialog.id='mai-file-detail';root.append(resultDialog);
 let resultGeneration=0;resultDialog.addEventListener('close',()=>resultGeneration++);
 async function openResult(file){const resultRequest=++resultGeneration;resultDialog.replaceChildren();const header=el('div');header.className='mai-title';header.append(el('h3',file.name,true),button('Schließen',()=>resultDialog.close()));const body=el('div','KI-Metadaten laden …');resultDialog.append(header,body);resultDialog.showModal();try{const r=await api('result?'+new URLSearchParams({file_id:file.file_id}));if(!resultDialog.open||resultRequest!==resultGeneration)return;body.replaceChildren();if(!r.available){body.append(el('p','Für dieses Bild liegt noch keine Analyse vor.'));return;}const a=r.metadata.analysis;body.append(el('h4','Bildbeschreibung'),el('p',a.vision.description,true),el('h4','Typ und Suchbegriffe'),el('p',[a.vision.type,...window.MediaAIi18n.keywords(a.vision)].join(' · '),true),el('h4','Erkannter Text'),el('pre',a.ocr.source==='skipped_video'?tr('OCR bei Videos ausgelassen.'):a.ocr.text||tr('Kein Text erkannt.'),true));if(a.vision.uncertain_details?.length)body.append(el('h4','Unsichere Angaben'),el('p',a.vision.uncertain_details.join('\n'),true));body.append(linkFor(file));}catch(e){body.textContent=e.message;}}
 const timer=setInterval(()=>{if(!document.contains(root)){clearInterval(timer);return;}if(!document.hidden&&!busy&&(current==='overview'||current==='library'&&!loadedPages)&&!resultDialog.open&&Date.now()-lastRefresh>=15000)refresh();},5000);
 return {show,view:()=>current,start:()=>{if(current==='overview'||current==='library')refresh();},restrict:()=>{restricted=true;show('settings');},status:s=>{if($('mai-nav-status')){$('mai-nav-status').textContent=tr(s.online?(s.state==='running'?'Analyse läuft':'Worker online'):'Worker offline');$('mai-nav-status').dataset.state=s.online?s.state:'offline';}const counts=$('mai-counts');counts.setAttribute('aria-label',tr('Warteschlange des Workers'));}};
}};
})();
