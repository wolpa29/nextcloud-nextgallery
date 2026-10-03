/* Offline is explicit: only the standalone /offline page and its static assets.
   Authenticated APIs, originals, navigation and previews are never cached here. */
self.addEventListener('install',()=>self.skipWaiting());
self.addEventListener('activate',event=>event.waitUntil(self.clients.claim()));
self.addEventListener('fetch',event=>{
 const url=new URL(event.request.url);if(event.request.method!=='GET'||url.origin!==self.location.origin)return;
 const offlinePath=new URL('./offline',self.location).pathname;
 const staticAsset=/\/apps\/chronicle\/(?:js\/(?:main|map)\.js|css\/main\.css)$/.test(url.pathname);
 if(url.pathname!==offlinePath&&!staticAsset)return;
 event.respondWith((async()=>{
  try{return await fetch(event.request);}catch(error){
   const names=(await caches.keys()).filter(k=>k.startsWith('chronicle-shell-'));
   for(const name of names){const response=await(await caches.open(name)).match(event.request,{ignoreSearch:true});if(response)return response;}
   return new Response('Keine Offline-Sammlung gespeichert.',{status:503,headers:{'Content-Type':'text/plain; charset=utf-8'}});
  }
 })());
});
