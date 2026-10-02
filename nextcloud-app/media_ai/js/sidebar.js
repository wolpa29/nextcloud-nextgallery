(() => {
'use strict';
let registered=false;
function register(){
 if(registered)return true; const sidebar=window.OCA?.Files?.Sidebar;if(!sidebar?.Tab)return false;
 let element, generation=0;
 const text=(tag,value)=>{const el=document.createElement(tag);el.textContent=value;return el;};
 sidebar.registerTab(new sidebar.Tab({id:'media-ai',name:'Media AI',icon:'icon-search',
  mount(el){element=el;element.classList.add('mai-meta');},
  async update(fileInfo){const g=++generation;if(!element)return;element.replaceChildren(text('p','KI-Metadaten laden …'));const id=fileInfo.id??fileInfo.get?.('id');if(!id){element.replaceChildren(text('p','Keine Datei ausgewählt.'));return;}
   try{const url=OC.generateUrl('/ocs/v2.php/apps/media_ai/api/v1/metadata')+'?format=json&file_id='+encodeURIComponent(id);const response=await fetch(url,{credentials:'same-origin',headers:{'OCS-APIRequest':'true','requesttoken':OC.requestToken,'Accept':'application/json'}});if(!response.ok)throw Error('HTTP '+response.status);const envelope=await response.json();if(g!==generation)return;const r=envelope.ocs.data;element.replaceChildren();if(!r.available){element.append(text('p',r.stale?'Die Datei wurde geändert. Eine neue Analyse steht aus.':'Für dieses Bild liegt noch keine Analyse vor.'));return;}
    const m=r.metadata.analysis,v=m.vision;element.append(text('h3','Bildbeschreibung'),text('p',v.description),text('h3','Typ und Suchbegriffe'),text('p',[v.type,...v.tags,...v.objects].join(' · ')),text('h3','Erkannter Text'),text('pre',m.ocr.text||'Kein Text erkannt.'));if(v.uncertain_details.length)element.append(text('h3','Unsichere Angaben'),text('p',v.uncertain_details.join('\n')));element.append(text('h3','Analyse'),text('p',new Date(Number(r.updated_at)*1000).toLocaleString()));const details=text('details','');details.append(text('summary','Alle Metadaten und Quelldaten'),text('pre',JSON.stringify(r.metadata,null,2)));element.append(details);
   }catch(e){if(g===generation)element.replaceChildren(text('p','Metadaten konnten nicht geladen werden ('+e.message+').'));}
  },destroy(){++generation;element=null;},enabled(fileInfo){return (fileInfo.mimetype??fileInfo.get?.('mimetype')??'').startsWith('image/');}
 }));registered=true;return true;
}
if(!register()){let tries=0;const t=setInterval(()=>{if(register()||++tries>=60)clearInterval(t);},500);}
})();
