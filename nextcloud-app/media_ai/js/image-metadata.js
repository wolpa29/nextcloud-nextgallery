(() => {
'use strict';
let registered=false;
function register(){
 if(registered)return true; const sidebar=window.OCA?.Files?.Sidebar;if(!sidebar?.Tab||!window.MediaAIi18n)return false;const tr=(key,values)=>window.MediaAIi18n.t(key,values);
 let element, generation=0, update;
 const text=(tag,value)=>{const el=document.createElement(tag);el.textContent=value;return el;};
 sidebar.registerTab(new sidebar.Tab({id:'media-ai',name:'Media AI',icon:'icon-search',
  mount(el,fileInfo){element=el;element.classList.add('mai-meta');return update(fileInfo);},
  update: update = async function(fileInfo){const g=++generation;if(!element)return;element.replaceChildren(text('p',tr('KI-Metadaten laden …')));const id=fileInfo.id??fileInfo.get?.('id');if(!id){element.replaceChildren(text('p',tr('Keine Datei ausgewählt.')));return;}
   try{const pref=await fetch(OC.generateUrl('/apps/media_ai/preferences'),{credentials:'same-origin'});if(pref.ok)window.MediaAIi18n.language=(await pref.json()).language;const url=OC.linkToOCS('apps/media_ai/api/v1',2)+'metadata'+'?format=json&file_id='+encodeURIComponent(id);const response=await fetch(url,{credentials:'same-origin',headers:{'OCS-APIRequest':'true','requesttoken':OC.requestToken,'Accept':'application/json'}});if(!response.ok)throw Error('HTTP '+response.status);const envelope=await response.json();if(g!==generation)return;const r=envelope.ocs.data;element.replaceChildren();if(!r.available){element.append(text('p',r.stale?tr('Die Datei wurde geändert. Eine neue Analyse steht aus.'):tr('Für dieses Bild liegt noch keine Analyse vor.')));return;}
    const m=r.metadata.analysis,v=m.vision;element.append(text('h3',tr('Bildbeschreibung')),text('p',v.description),text('h3',tr('Typ und Suchbegriffe')),text('p',[v.type,...v.tags,...v.objects].join(' · ')),text('h3',tr('Erkannter Text')),text('pre',m.ocr.text||tr('Kein Text erkannt.')));if(v.uncertain_details.length)element.append(text('h3',tr('Unsichere Angaben')),text('p',v.uncertain_details.join('\n')));element.append(text('h3',tr('Analyse')),text('p',new Date(Number(r.updated_at)*1000).toLocaleString()));const details=text('details','');details.append(text('summary',tr('Alle Metadaten und Quelldaten')),text('pre',JSON.stringify(r.metadata,null,2)));element.append(details);
   }catch(e){if(g===generation)element.replaceChildren(text('p',tr('Metadaten konnten nicht geladen werden ({error}).',{error:e.message})));}
  },destroy(){++generation;element=null;},enabled(fileInfo){return (fileInfo.mimetype??fileInfo.get?.('mimetype')??'').startsWith('image/');}
 }));registered=true;return true;
}
if(!register()){let tries=0;const t=setInterval(()=>{if(register()||++tries>=60)clearInterval(t);},500);}
})();
