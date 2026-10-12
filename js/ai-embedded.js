(() => {
 'use strict';
 function init(){
  const shell=document.querySelector('.mai-shell[data-embedded="true"]');
  if(!shell||window.parent===window)return;
  document.addEventListener('click',event=>{
   const link=event.target.closest('a[href]');if(!link)return;
   const target=new URL(link.href,location.href);
   if(target.origin!==location.origin)return;
   if(target.pathname===location.pathname&&target.hash){
    event.preventDefault();event.stopImmediatePropagation();
    history.replaceState(null,'',target.hash);window.dispatchEvent(new Event('hashchange'));return;
   }
   const routes={gallery:'/gallery',account:'/processing',admin:'/ai'};
   const base=location.pathname.replace(/\/(?:ai|processing)\/?$/,'');
   const page=Object.keys(routes).find(page=>target.pathname===base+routes[page]);
   if(page){event.preventDefault();event.stopImmediatePropagation();window.parent.postMessage({type:'nextgallery-ai-navigation',page},location.origin);}
  },true);
 }
 if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',init);else init();
})();
