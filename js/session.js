/* Only the session helpers needed by the standalone app, without core UI scripts. */
(() => {
 const base=document.querySelector('meta[name=nextgallery-route-root]').content;
 const routeRoot=base.replace(/\/apps\/nextgallery$/, '');
 window.NextGallerySession={
  requestToken:document.querySelector('meta[name=nextgallery-requesttoken]').content,
  generateUrl:path=>routeRoot+path,
 };
})();
