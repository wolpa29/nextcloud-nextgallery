(() => {
'use strict';
window.MediaAIWorkspace={create({root,tr}){
 const $=id=>document.getElementById(id),nav=$('app-navigation');
 const views={settings:['Server-Einstellungen','Modelle und Dienste für alle Benutzer.','mai-settings']};
 let current='settings';
 function show(view,options={}){
  if(!views[view])view='settings';current=view;
  root.querySelectorAll('[data-view]').forEach(section=>section.hidden=section.dataset.view!==view);
  $('mai-page-title').textContent=tr(views[view][0]);$('mai-page-description').textContent=tr(views[view][1]);
  nav.querySelectorAll('[data-nav-target]').forEach(link=>{
   const active=link.dataset.navTarget===views[view][2];link.classList.toggle('is-active',active);
   if(active)link.setAttribute('aria-current','page');else link.removeAttribute('aria-current');
  });
  nav.dataset.collapsed='true';$('mai-nav-toggle').setAttribute('aria-expanded','false');
  if(location.hash!=='#'+view)history[options.fromHistory?'replaceState':'pushState'](null,'','#'+view);
  root.scrollTop=0;root.dispatchEvent(new Event('media-ai-view'));
 }
 function route(){const slug=location.hash.slice(1);show(Object.keys(views).find(view=>view===slug||views[view][2]===slug),{fromHistory:true});}
 nav.querySelectorAll('[data-nav-target]').forEach(link=>link.onclick=event=>{event.preventDefault();show(Object.keys(views).find(view=>views[view][2]===link.dataset.navTarget));});
 nav.querySelector('.mai-nav-brand').onclick=event=>{event.preventDefault();show('settings');};
 $('mai-nav-toggle').onclick=()=>{const open=nav.dataset.collapsed==='true';nav.dataset.collapsed=String(!open);$('mai-nav-toggle').setAttribute('aria-expanded',String(open));};
 window.addEventListener('hashchange',route);window.addEventListener('popstate',route);route();
 return {show,view:()=>current,start:()=>{},status:value=>{
  $('mai-nav-status').textContent=tr(!value.online?'Worker offline':value.worker_busy||value.state==='running'?'Verarbeitung läuft':'Worker online');
  $('mai-nav-status').dataset.state=value.online?'online':'offline';
 }};
}};
})();
