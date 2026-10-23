import { registerSidebarTab } from '@nextcloud/files-v4'
import icon from '../img/media-ai.svg'
const tr=(key,values)=>window.MediaAIi18n?.t(key,values)||key
class AnalysisTab extends HTMLElement {
  _node=null
  _active=false
  generation=0
  set node(value){this._node=value;this.render()}
  get node(){return this._node}
  set active(value){this._active=value;this.render()}
  get active(){return this._active}
  connectedCallback(){this.classList.add('mai-meta');this.render()}
  disconnectedCallback(){this.generation++}
  async render(){
    const generation=++this.generation,node=this._node
    if(!this.isConnected||!this._active||!node?.fileid)return
    const text=(tag,value)=>{const el=document.createElement(tag);el.textContent=value;return el}
    this.replaceChildren(text('p',tr('KI-Metadaten laden …')))
    try{
      const pref=await fetch(OC.generateUrl('/apps/nextgallery/ai/preferences'),{credentials:'same-origin'})
      if(pref.ok&&window.MediaAIi18n)window.MediaAIi18n.language=(await pref.json()).language
      const response=await fetch(OC.linkToOCS('apps/nextgallery/ai/api/v1',2)+'metadata?format=json&file_id='+encodeURIComponent(node.fileid),{credentials:'same-origin',headers:{'OCS-APIRequest':'true',requesttoken:OC.requestToken,'Accept':'application/json'}})
      if(!response.ok)throw Error('HTTP '+response.status)
      const r=(await response.json()).ocs.data
      if(generation!==this.generation)return
      this.replaceChildren()
      if(!r.available){this.append(text('p',tr(r.stale?'Die Datei wurde geändert. Eine neue Analyse steht aus.':'Für dieses Bild liegt noch keine Analyse vor.')));return}
      const a=r.metadata.analysis,v=a.vision
      if(v)this.append(text('h3',tr('Bildbeschreibung')),text('p',v.description),text('h3',tr('Typ und Suchbegriffe')),text('p',[v.type,...(window.MediaAIi18n?.keywords(v)||v.tags||[])].join(' · ')))
      if(a.ocr)this.append(text('h3',tr('Erkannter Text')),text('pre',a.ocr.source==='skipped_video'?tr('OCR bei Videos ausgelassen.'):a.ocr.text||tr('Kein Text erkannt.')))
      if(v?.uncertain_details?.length)this.append(text('h3',tr('Unsichere Angaben')),text('p',v.uncertain_details.join('\n')))
      if(a.audio){
        this.append(text('h3',tr('Videoton · Gesprochene Inhalte')))
        if(a.audio.no_audio)this.append(text('p',tr('Keine Sprache in der Audiospur erkannt.')))
        else if(a.audio.text){
          this.append(text('p',a.audio.text))
          if(a.audio.segments?.length){
            const details=text('details','')
            details.append(text('summary',tr('Zeitabschnitte anzeigen ({count})',{count:a.audio.segments.length})))
            const fmt=sec=>{const m=Math.floor(sec/60),s=Math.floor(sec%60);return `${String(m).padStart(2,'0')}:${String(s).padStart(2,'0')}`}
            const list=document.createElement('div')
            for(const seg of a.audio.segments){
              list.append(text('p',`[${fmt(seg.start)} - ${fmt(seg.end)}] ${seg.text}`))
            }
            details.append(list)
            this.append(details)
          }
        }
      }
      this.append(text('h3',tr('Analyse')),text('p',new Date(Number(r.updated_at)*1000).toLocaleString()))
      const details=text('details','');details.append(text('summary',tr('Alle Metadaten und Quelldaten')),text('pre',JSON.stringify(r.metadata,null,2)));this.append(details)
    }catch(e){if(generation===this.generation)this.replaceChildren(text('p',tr('Metadaten konnten nicht geladen werden ({error}).',{error:e.message})))}
  }
}
registerSidebarTab({id:'media-ai',displayName:'Media AI',iconSvgInline:icon,order:50,tagName:'nextgallery-ai-sidebar',enabled:({node})=>/^(image|video)\//.test(node.mime||''),onInit:async()=>{if(!customElements.get('nextgallery-ai-sidebar'))customElements.define('nextgallery-ai-sidebar',AnalysisTab)}})
