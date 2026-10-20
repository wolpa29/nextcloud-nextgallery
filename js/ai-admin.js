(() => {
'use strict';
async function init() {
 const root=document.getElementById('media-ai-admin'); if(!root || root.dataset.ready)return; root.dataset.ready='1';
 const i18n=window.MediaAIi18n;i18n.language=root.closest('.mai-shell')?.dataset.language||document.documentElement.lang;i18n.apply(root);root.lang=i18n.language;const tr=(key,values)=>i18n.t(key,values);
 const guide='https://github.com/wolpa29/nextcloud-nextgallery/blob/main/docs/EXTERNAL_EMBEDDING_SERVER'+(i18n.language==='en'?'.en':'')+'.md';
 root.querySelectorAll('[data-embedding-guide]').forEach(link=>{link.href=guide+(link.hasAttribute('data-custom-guide')?'#custom-models':'');link.hreflang=i18n.language;});
 root.querySelectorAll('dialog').forEach(dialog=>dialog.addEventListener('keydown',event=>{if(event.key!=='Escape')return;event.preventDefault();event.stopPropagation();const cancel=new Event('cancel',{cancelable:true});dialog.dispatchEvent(cancel);if(!cancel.defaultPrevented)dialog.close();}));
 const $=id=>document.getElementById(id); let config=null,entries=[],cursor=null,logBusy=false,lastStatus=null,canManage=false,modelProbe=null,installBusy=false,processingState=null;
 const api=async(path,body)=>{const r=await fetch((window.NextGallerySession||window.OC).generateUrl('/apps/nextgallery/ai/admin/'+path),{method:body?'POST':'GET',credentials:'same-origin',headers:{'requesttoken':(window.NextGallerySession||window.OC).requestToken,'Content-Type':'application/json','Accept':'application/json'},body:body?JSON.stringify(body):undefined}); if(!r.ok){let detail;try{detail=(await r.json()).error;}catch{}const error=Error(detail?tr(detail):tr('Anfrage fehlgeschlagen (HTTP {code}).',{code:r.status}));error.status=r.status;throw error;}return r.json();};
 const message=(text,error=false)=>{$('mai-message').textContent=tr(text);$('mai-message').classList.toggle('mai-error',error);};
 const el=(tag,text,raw=false)=>{const n=document.createElement(tag);if(text!==undefined)n.textContent=raw?text:tr(text);return n;};
 function promptField(stage,key,label,rows){const l=el('label',label),t=el('textarea');t.name=stage+'.'+key;t.rows=rows;t.maxLength=20000;t.dataset.defaultPrompt='1';l.append(t);const actions=el('div');actions.className='mai-actions mai-prompt-actions';const reset=el('button','Standardprompt wiederherstellen');reset.type='button';reset.dataset.promptReset='1';reset.setAttribute('aria-label',tr('Standardprompt wiederherstellen')+' · '+tr(label)+' · '+stageNameForPrompt(stage));const note=el('span');note.className='mai-muted';const standard=()=>config?.[stage]?.['default_'+key]||'';const update=()=>note.textContent=tr(!t.value||t.value===standard()?'Standardprompt aktiv':'Eigener Prompt');t.addEventListener('input',update);reset.onclick=()=>{t.value=standard();t.dispatchEvent(new Event('input',{bubbles:true}));};actions.append(reset,note);return [l,actions];}
 function stageNameForPrompt(stage){return tr(stage==='vision'?'Bildanalyse':'Texterkennung');}
 function fields(stage,label){const section=el('details');section.className='mai-model';section.dataset.modelStage=stage;section.append(el('summary',label));const grid=el('div');grid.className='mai-grid';const advanced=el('details'),advancedGrid=el('div');advanced.append(el('summary','Erweiterte Modelleinstellungen'));advancedGrid.className='mai-grid';advanced.append(advancedGrid);
  for(const [key,title,type] of [['url','API-Adresse','url'],['model','Modell-ID','text'],['model_revision','Modellstand (zur Dokumentation)','text'],['max_edge','Zusätzliche Kantengrenze (Pixel)','number'],['model_max_pixels','Bildbudget des Encoders (Pixel, 0 = nur Kantengrenze)','number'],['max_tokens','Max. Antwort-Tokens (0 = kein App-Limit)','number'],['timeout_seconds','Netzwerk-Wartezeit pro Anfrage (Sekunden)','number'],['temperature','Temperatur','number'],['retry_temperature','Temperatur bei Korrektur','number'],['loop_repetitions','Gleiche Textblöcke bis Abbruch','number'],['numeric_loop_repetitions','Fortlaufende Zahlen-Labels bis Abbruch','number'],['loop_retries','Korrekturversuche (0–3)','number'],['api_key','API-Key (leer = beibehalten)','password']]){const l=el('label',title),i=el('input');i.name=stage+'.'+key;i.type=type;i.autocomplete=type==='password'?'new-password':'off';if(type==='number')i.step=key.endsWith('temperature')?'0.1':'1';const bounds={model_max_pixels:[0,67108864],max_edge:[256,8192],max_tokens:[0,32768],timeout_seconds:[15,7200],temperature:[0,2],retry_temperature:[0,2],loop_repetitions:[3,20],numeric_loop_repetitions:[20,200],loop_retries:[0,3]};if(bounds[key]){i.min=bounds[key][0];i.max=bounds[key][1];}if(key!=='api_key')i.required=true;l.append(i);if(key==='model_max_pixels'){const hint=el('span');hint.className='mai-muted';i.dataset.imageEdge='1';const update=()=>hint.textContent=tr('Bis zu {mp} MP je Bild unabhängig vom Seitenverhältnis. Vollständiger Rahmen; Rasterausgleich mit kleinen weißen Rändern.',{mp:(Number(i.value)/1000000).toLocaleString(i18n.language,{maximumFractionDigits:2})});i.addEventListener('input',update);l.append(hint);}(['url','model','api_key'].includes(key)?grid:advancedGrid).append(l);}
  {const l=el('label','Modellzugang'),i=el('select');i.name=stage+'.transport';for(const [value,title] of [['lmstudio','LM Studio SDK'],['openai','OpenAI-kompatible HTTP-API']]){const option=el('option',title);option.value=value;i.append(option);}l.append(i);grid.append(l);}
  if(stage==='ocr'){
   for(const [key,title,min,max] of [['tile_overlap_percent','Überlappung der OCR-Ausschnitte (%)',1,25],['max_batch_images','Max. Bilder pro OCR-Anfrage (inklusive Gesamtbild)',2,64]]){const l=el('label',title),i=el('input');i.type='number';i.name=stage+'.'+key;i.min=min;i.max=max;i.step=1;i.required=true;l.append(i);grid.append(l);}
   advanced.append(el('p','OCR sendet Gesamtbild und überlappende Originalausschnitte gemeinsam. Ausschnitte werden nicht verkleinert; die Bildanzahl muss zum Modellkontext passen.'));
  }
  section.append(grid,advanced);advanced.append(el('p','Das Pixelbudget muss zur gemessenen Encodergrenze passen. LM Studio SDK umgeht die zusätzliche Kantenverkleinerung; das Encoderlimit bleibt wirksam.'),...promptField(stage,'prompt','Prompt (leer = bewährter Standard)',8));
  const clear=el('label','Gespeicherten API-Key entfernen'),check=el('input');clear.className='mai-check';check.type='checkbox';check.name=stage+'.clear_api_key';clear.prepend(check);advanced.append(clear);
  {const l=el('label','Antwort streamen und Schleifen früh abbrechen'),i=el('input');l.className='mai-check';i.type='checkbox';i.name=stage+'.stream';l.prepend(i);advanced.append(l);}
  advanced.append(...promptField(stage,'retry_prompt','Korrektur-Prompt (leer = Standard)',4));
  advanced.append(el('p','Leer oder unveränderter Standardtext verwendet den Standardprompt. Eigene Änderungen ersetzen ihn ab dem nächsten Lauf.'));
  advanced.append(el('p','Die Netzwerk-Wartezeit gilt pro Anfrage ohne empfangene Daten. Sie begrenzt weder die gesamte Zeit pro Bild noch gesundes Streaming-Reasoning.'));
  advanced.append(el('p','Ohne Streaming werden Schleifen erst nach der Antwort erkannt. Gesunde Denkprozesse haben kein App-Tokenlimit bei 0.'));
  {const l=el('label','Qwen / LM Studio: schema-valides JSON aus Reasoning-Feld erlauben'),i=el('input');l.className='mai-check';i.type='checkbox';i.name=stage+'.allow_reasoning_json';l.prepend(i);advanced.append(l);}
  $('mai-model-settings').append(section);
 }
 fields('vision','Beschreibungen und Objekte');fields('ocr','Texterkennung (OCR)');
 for(const [stage,key] of [['vision','analysis_enabled'],['ocr','ocr_enabled']]){const label=el('label','Für Benutzer aktivieren'),check=el('input');label.className='mai-check';check.type='checkbox';check.name=key;label.prepend(check);$('mai-model-settings').querySelector('[data-model-stage="'+stage+'"]>summary').after(label);}

 function processingExplanation(){
  const form=$('mai-form'),analysis=form.elements.analysis_enabled.checked,dense=form.elements['embedding.enabled'].checked,local=form.elements['embedding.location'].value==='local';
  const gemma=form.elements['embedding.family'].value==='gemma2',automatic=local&&gemma&&form.elements['embedding.local_auto_setup'].checked;
  const custom=form.elements['embedding.family'].value==='custom';
  form.elements['embedding.family'].querySelector('[value="custom"]').disabled=local;
  root.querySelectorAll('[data-embedding-custom]').forEach(node=>node.hidden=local||!custom);
  const parts=['Dateinamen und Pfade'];if(analysis)parts.push('Beschreibungen, Tags und Objekte');if(form.elements.ocr_enabled.checked)parts.push('erkannter Text');if(dense)parts.push('ähnliche Bildinhalte');
   root.querySelectorAll('[data-embedding-local]').forEach(n=>n.hidden=!local);
  root.querySelectorAll('[data-embedding-auto]').forEach(n=>n.hidden=!local||!gemma);
  root.querySelectorAll('[data-embedding-auto-help]').forEach(n=>n.hidden=!automatic);
  root.querySelectorAll('[data-embedding-family]').forEach(n=>n.hidden=automatic);
  root.querySelectorAll('[data-embedding-manual]').forEach(n=>{n.hidden=!local||automatic;for(const i of n.querySelectorAll('input'))i.required=local&&!automatic;});
  root.querySelectorAll('[data-embedding-model-id]').forEach(n=>n.hidden=automatic);
  root.querySelectorAll('[data-embedding-external]').forEach(n=>{n.hidden=local;for(const i of n.querySelectorAll('input[type=url]'))i.required=!local;});
  root.querySelectorAll('[data-embedding-qwen]').forEach(n=>n.hidden=!local||form.elements['embedding.family'].value!=='qwen3_vl');
  renderRequirements(automatic,local);
  const vectorFields=['location','family','model','local_auto_setup','local_profile','local_model_path','local_qwen_source','model_revision','url','max_edge','video_frames'];const changed=config&&vectorFields.some(key=>{const input=form.elements['embedding.'+key];return input&&(input.type==='checkbox'?input.checked:input.type==='number'?Number(input.value):input.value)!==config.embedding[key];});
  root.querySelectorAll('[data-embedding-rebuild-notice]').forEach(n=>{n.hidden=!(dense&&(lastStatus?.embedding_rebuild?.required||changed&&Number(config?.embedding_total_count)>0));n.textContent=tr('Vor dem Speichern kannst du den neuen Dienst prüfen. Bei gleichem Vektorraum bleiben vorhandene Bildvektoren automatisch erhalten.');});
  const provider=!local?'external':(form.elements['embedding.local_profile'].value==='full'?'full':'native');root.querySelectorAll('[data-embedding-provider]').forEach(n=>n.value=provider);
 }
 root.querySelectorAll('[data-embedding-provider]').forEach(select=>select.addEventListener('change',()=>{
  const provider=select.value,form=$('mai-form');form.elements['embedding.location'].value=provider==='external'?'external':'local';form.elements['embedding.local_auto_setup'].checked=['native','full'].includes(provider);form.elements['embedding.transport'].value='multimodal';
  if(provider==='external'&&form.elements['embedding.family'].value==='gemma2')form.elements['embedding.model'].value=form.elements['embedding.local_profile'].value==='full'?'embeddinggemma-2-full':'embeddinggemma-2';
  if(['native','full'].includes(provider)){form.elements['embedding.local_profile'].value=provider==='full'?'full':'lite';form.elements['embedding.family'].value='gemma2';form.elements['embedding.model'].value=provider==='full'?'embeddinggemma-2-full':'embeddinggemma-2';}
  updateSaveState();if(lastStatus)embeddingSetupStatus(lastStatus);
 }));
 for(const name of ['analysis_enabled','ocr_enabled','embedding.enabled','embedding.location','embedding.family','embedding.local_auto_setup'])$('mai-form').elements[name].addEventListener('change',processingExplanation);
 $('mai-form').elements['embedding.family'].addEventListener('change',()=>{const family=form.elements['embedding.family'].value;form.elements['embedding.model'].value=family==='gemma2'?(form.elements['embedding.local_profile'].value==='full'?'embeddinggemma-2-full':'embeddinggemma-2'):family==='qwen3_vl'?'Qwen/Qwen3-VL-Embedding-2B':config?.embedding.family==='custom'?config.embedding.model:'';updateSaveState();});
 function reportEmbeddingValidity(){
  for(const input of $('mai-form').elements){
   if(!input.name?.startsWith('embedding.')||input.disabled||input.checkValidity())continue;
   for(let parent=input.parentElement;parent;parent=parent.parentElement)if(parent.tagName==='DETAILS')parent.open=true;
   input.reportValidity();return false;
  }
  return true;
 }
 $('mai-embedding-test').onclick=async()=>{
  if(!config||embeddingProbeBusy||saving||choosingModel||!reportEmbeddingValidity())return;
  const snapshot=formSnapshot('embedding'),target=$('mai-embedding-result');embeddingProbeBusy=true;embeddingProbeSnapshot=snapshot;target.textContent=tr('Embedding-Dienst wird geprüft …');target.classList.remove('mai-error');updateSaveState();
  try{const info=await api('embedding-test',{config:formConfig()});if(formSnapshot('embedding')!==snapshot)return;
   target.classList.toggle('mai-error',!info.ready||info.compatibility==='different');
   target.textContent=info.ready?tr('Bereit für Bilder, Video-Standbilder und Suchanfragen.')+' '+info.model+' · '+info.dimensions+(info.reason?'\n'+tr(info.reason):''):tr(info.message||info.reason);
  }catch(e){if(formSnapshot('embedding')===snapshot){target.classList.add('mai-error');target.textContent=e.message;}}
  finally{embeddingProbeBusy=false;updateSaveState();}
 };
 const workspace=window.MediaAIWorkspace.create({root,api,tr,el,configuration:()=>config});
 const form=$('mai-form');
 let savedSnapshot=null,saving=false,saveError=false,choosingModel=false,embeddingProbeBusy=false,modelProbeBusy=false,embeddingProbeSnapshot=null,modelProbeSnapshot=null;
 window.addEventListener('beforeunload',event=>{if(savedSnapshot!==null&&formSnapshot()!==savedSnapshot){event.preventDefault();event.returnValue='';}});
 const formSnapshot=(group='')=>JSON.stringify([...form.elements].filter(i=>i.name&&(!group||i.name.startsWith(group+'.'))&&!i.disabled&&!i.readOnly).map(i=>[i.name,i.type==='checkbox'?i.checked:i.value]));
 function pipelineRunning(kind){return processingState?.pipelines?.[kind]?.running??lastStatus?.pipelines?.[kind]?.state==='running';}
 function blockedPipelines(){
  const blocked=new Set();if(!config)return [];
  for(const input of form.elements){
   if(!input.name||input.disabled||input.readOnly)continue;
   const [group,key]=input.name.split('.');let previous=key?config[group]?.[key]:config[group];
   if(input.dataset.defaultPrompt)previous=previous||config[group]?.['default_'+key];
   if(input.type==='checkbox')previous=Boolean(previous);
   const value=input.type==='checkbox'?input.checked:input.type==='number'?Number(input.value):input.value;
   if(Array.isArray(previous))previous=previous.join('\n');
   if(value===(previous??''))continue;
   const kinds=group==='faces'?['faces']:group==='embedding'?['embeddings']:['ocr','ocr_enabled'].includes(group)?['ocr']:['vision','video','analysis_enabled'].includes(group)?['descriptions']:['descriptions','ocr','embeddings','faces'];
   for(const kind of kinds)if(pipelineRunning(kind))blocked.add(kind);
  }
  return [...blocked];
 }
 function settingsBlocked(){return blockedPipelines().length>0;}
 function formConfig(){
  const value=structuredClone(config);
  if(!value.faces)value.faces={enabled:false,cosine_threshold:0.55,detection_threshold:0.9,min_face_size:32,min_group_size:2,max_edge:2048};
  for(const input of form.elements){if(!input.name)continue;let x=input.type==='checkbox'?input.checked:input.type==='number'?Number(input.value):input.value;const parts=input.name.split('.');if(['folders','exclude_folders'].includes(input.name))x=input.value.split('\n').map(x=>x.trim()).filter(Boolean);if(input.dataset.defaultPrompt&&x===config[parts[0]]['default_'+parts[1]])x='';if(parts.length===2){if(!value[parts[0]])value[parts[0]]={};value[parts[0]][parts[1]]=x;}else value[parts[0]]=x;}
  if(form.elements['faces.cosine_threshold'])value.faces.cosine_threshold=Number(form.elements['faces.cosine_threshold'].value);
  return value;
 }
 function updateSaveState(){
  const snapshot=formSnapshot(),dirty=savedSnapshot!==null&&snapshot!==savedSnapshot,blocked=dirty?blockedPipelines():[];
  for(const [last,current,id] of [[embeddingProbeSnapshot,formSnapshot('embedding'),'mai-embedding-result'],[modelProbeSnapshot,snapshot,'mai-model-result']])if(last!==null&&last!==current)$(id).textContent=tr('Eingaben geändert. Verbindung erneut prüfen.');
  if(modelProbeSnapshot!==null&&modelProbeSnapshot!==snapshot)modelProbe=null;
  root.querySelectorAll('[data-save-blocked]').forEach(node=>{node.hidden=!blocked.length;node.querySelector('[data-save-blocked-text]').textContent=tr('Zum Speichern zuerst {pipelines} pausieren und den Abschluss der aktuellen Datei abwarten.',{pipelines:blocked.map(kind=>tr({descriptions:'Beschreibungen',ocr:'Texterkennung (OCR)',embeddings:'Visuelle Bildsuche · Embeddings',faces:'Personenerkennung · Gesichter'}[kind])).join(', ')});});
  if(!dirty)saveError=false;
  const state=savedSnapshot===null?'loading':saving?'saving':dirty?(saveError?'error':blocked.length?'blocked':'dirty'):'saved';
  const labels={loading:'Einstellungen werden geladen …',saving:'Wird gespeichert …',dirty:'Ungespeicherte Änderungen',blocked:'Speichern erst nach dem Pausieren möglich',error:'Nicht gespeichert · erneut versuchen',saved:'Gespeichert'};
  root.querySelectorAll('[data-save-status]').forEach(n=>{n.textContent=tr(labels[state]);n.dataset.state=state;});
  root.querySelectorAll('button[type="submit"][form="mai-form"],#mai-form button[type="submit"]').forEach(b=>b.disabled=!dirty||saving||choosingModel||settingsBlocked());
  $('mai-savebar').hidden=!dirty&&!saving;
  $('mai-embedding-test').disabled=savedSnapshot===null||embeddingProbeBusy||saving||choosingModel;
  $('mai-test').disabled=savedSnapshot===null||modelProbeBusy||saving||choosingModel;
  $('mai-embedding-test').textContent=tr(embeddingProbeBusy?'Verbindung wird geprüft …':'Verbindung prüfen');
  processingExplanation();
  renderEmbeddingInstall();
  renderFacesInstall();
 }
 root.querySelectorAll('[data-show-processing]').forEach(button=>button.onclick=()=>{workspace.show('settings');$('mai-processing-admin').open=true;$('mai-processing-admin').scrollIntoView({behavior:'smooth',block:'start'});});
 for(const event of ['input','change'])form.addEventListener(event,e=>{if(e.target.name)updateSaveState();});
 function loadForm(){if(!config.faces)config.faces={enabled:false,cosine_threshold:0.55,detection_threshold:0.9,min_face_size:32,min_group_size:2,max_edge:2048};for(const input of form.elements){if(!input.name)continue;const parts=input.name.split('.');let v=parts.length===2?config[parts[0]]?.[parts[1]]:config[parts[0]];if(input.dataset.defaultPrompt)v=v||config[parts[0]]['default_'+parts[1]];if(input.type==='checkbox')input.checked=Boolean(v);else if(Array.isArray(v))input.value=v.join('\n');else input.value=v??'';} form.querySelectorAll('[data-image-edge]').forEach(i=>i.dispatchEvent(new Event('input')));const allowed=config.can_configure_models;form.elements.user_id.readOnly=true; $('mai-model-settings').querySelectorAll('input,select,button').forEach(i=>i.disabled=!allowed);$('mai-model-settings').querySelectorAll('textarea').forEach(i=>{i.readOnly=!allowed;i.dispatchEvent(new Event('input'));});root.querySelectorAll('#mai-embedding-settings input,#mai-embedding-settings select,[data-model-admin]').forEach(i=>i.disabled=!allowed);root.querySelectorAll('#mai-faces-settings input,#mai-faces-settings select,button[data-faces-install]').forEach(i=>i.disabled=!allowed);processingExplanation(); savedSnapshot=formSnapshot();saveError=false;updateSaveState(); }
 async function saveForm(){
  if(!config||saving||choosingModel)return false;if(savedSnapshot===formSnapshot())return true;if(settingsBlocked()||!form.reportValidity())return false;
  const submittedSnapshot=formSnapshot(),v=formConfig();
  let embeddingChangeId='',embeddingChangeMode='',embeddingAutoRebuild=false;
  const keys=['location','family','model','local_auto_setup','local_profile','local_model_path','local_qwen_source','model_revision','url','max_edge','video_frames'];
  if(keys.some(key=>v.embedding[key]!==config.embedding[key])){
   choosingModel=true;updateSaveState();try{const preview=await api('embedding-change-preview',{config:v});
   if(formSnapshot()!==submittedSnapshot)return false;
   const choice=preview.compatible?{mode:'keep',rebuild:false}:await chooseEmbeddingChange(preview);if(!choice||formSnapshot()!==submittedSnapshot)return false;
   embeddingChangeId=preview.config_id;embeddingChangeMode=choice.mode;embeddingAutoRebuild=choice.rebuild;}finally{choosingModel=false;updateSaveState();}
  }
  saving=true;saveError=false;updateSaveState();
  try{config=await api('config',{config:v,embedding_change_id:embeddingChangeId,embedding_change_mode:embeddingChangeMode,embedding_auto_rebuild:embeddingAutoRebuild});if(formSnapshot()===submittedSnapshot)loadForm();else savedSnapshot=submittedSnapshot;message('');return true;}
  catch(e){saveError=true;message(e.message,true);return false;}
  finally{saving=false;updateSaveState();}
 }
 form.addEventListener('submit',async e=>{e.preventDefault();try{await saveForm();}catch(error){message(error.message,true);}});
 function chooseEmbeddingChange(preview){
  const dialog=$('mai-embedding-change');$('mai-embedding-change-title').textContent=tr(preview.compatibility==='unverified'?'Embedding-Dienst prüfen':'Embedding-Modell wechseln');$('mai-embedding-change-text').textContent=preview.compatibility==='unverified'?tr('Der Vektorraum konnte noch nicht geprüft werden. Das beweist keinen Modellwechsel. Prüfe die Verbindung; vorhandene Vektoren bleiben beim Abbrechen erhalten.'):tr('Zurücksetzen löscht sofort alle Bildvektoren aller Benutzer und den lokalen Vektor-Verarbeitungsspeicher. Betroffene Dateien: {files} · Konten: {accounts}. Die Bildsuche füllt sich beim Neuaufbau wieder.',{files:Number(preview.files).toLocaleString(i18n.language),accounts:preview.accounts});
  $('mai-embedding-change-keep').disabled=!preview.compatible;
  $('mai-embedding-keep-reason').textContent=tr(preview.compatible?'Gleicher Vektorraum nachgewiesen. Vorhandene Vektoren können weiterverwendet werden.':preview.reason);
  $('mai-embedding-auto-rebuild').checked=true;
  $('mai-embedding-change-reset').disabled=preview.compatibility==='unverified';
  $('mai-embedding-auto-rebuild').closest('label').hidden=preview.compatibility==='unverified';
  $('mai-embedding-auto-rebuild').closest('label').nextElementSibling.hidden=preview.compatibility==='unverified';
  return new Promise(resolve=>{const finish=mode=>{dialog.close();resolve(mode?{mode,rebuild:mode==='reset'&&$('mai-embedding-auto-rebuild').checked}:null);};$('mai-embedding-change-cancel').onclick=()=>finish(null);$('mai-embedding-change-reset').onclick=()=>finish('reset');$('mai-embedding-change-keep').onclick=()=>finish('keep');dialog.oncancel=e=>{e.preventDefault();finish(null);};dialog.showModal();$('mai-embedding-change-cancel').focus();});
 }
 async function processing(){
  processingState=await api('processing');
  for(const kind of ['descriptions','ocr','embeddings','faces']){
   const section=root.querySelector('[data-admin-pipeline="'+kind+'"]');
   if(!section)continue;
   const value=processingState.pipelines?.[kind]||processingState;
   section.querySelector('[data-processing-status]').textContent=tr(value.paused?(value.running?'Läufe gesperrt. Die aktuelle Datei wird noch abgeschlossen.':'Diese Verarbeitung ist durch den Administrator pausiert.'):'Benutzerläufe sind erlaubt.');
   const allow=section.querySelector('[data-processing-action="allow_runs"]'),pause=section.querySelector('[data-processing-action="pause_all"]');
   allow.hidden=!value.paused;allow.disabled=value.running;pause.disabled=value.paused&&!value.running;
   section.querySelector('[data-processing-accounts-summary]').textContent=tr('{count} Benutzer · {active} aktiv',{count:value.accounts?.length||0,active:(value.accounts||[]).filter(row=>row.state==='running'||row.requested).length});
   section.querySelector('[data-processing-accounts]').replaceChildren(...(value.accounts||[]).map(row=>el('li',row.user_id+' · '+tr(row.state==='running'?'Läuft':row.requested?'Vorgemerkt':row.paused?'Pausiert':'Bereit'),true)));
  }
 }
 root.querySelectorAll('[data-processing-action]').forEach(button=>button.onclick=async()=>{
  const action=button.dataset.processingAction,kind=button.dataset.kind;
  try{if(action==='pause_all'&&!await confirmModelAction(kind==='embeddings'?'Alle Embedding-Läufe pausieren?':kind==='ocr'?'Alle OCR-Läufe pausieren?':kind==='faces'?'Alle Läufe der Personenerkennung pausieren?':'Alle Beschreibungs-Läufe pausieren?','Die aktuelle Datei wird abgeschlossen. Vorgemerkte Starts werden entfernt; neue und automatische Läufe dieser Verarbeitung sind bis zur Freigabe gesperrt. Die anderen Verarbeitungen laufen weiter.','Pausieren'))return;
   await api('processing',{action,kind});await processing();await status();
  }catch(error){message(error.message,true);}
 });
 function confirmModelAction(title,text,label,destructive=false){
  const dialog=$('mai-model-confirm');$('mai-model-confirm-title').textContent=tr(title);$('mai-model-confirm-text').textContent=tr(text);const ok=$('mai-model-confirm-ok');ok.textContent=tr(label);ok.classList.toggle('mai-model-remove',destructive);
  return new Promise(resolve=>{const finish=value=>{dialog.close();resolve(value);};$('mai-model-confirm-cancel').onclick=()=>finish(false);ok.onclick=()=>finish(true);dialog.oncancel=e=>{e.preventDefault();finish(false);};dialog.showModal();$('mai-model-confirm-cancel').focus();});
 }
 function renderRequirements(automatic,local){
  const full=$('mai-form').elements['embedding.local_profile'].value==='full';
  const lines=automatic?(full?[
   'Empfohlen: 4 CPU-Kerne · 6 GB freier RAM · 5 GB freier Speicher.',
   'Originalmodell (FP32) · ca. 1,52 GB Download plus Laufzeit.'
  ]:[
   'Empfohlen: 4 CPU-Kerne · 2 GB freier RAM · 2 GB freier Speicher.',
   'Sparsame LiteRT-Variante · ca. 388 MB Download plus Laufzeit.'
  ]):[];
  for(const n of root.querySelectorAll('[data-embedding-requirements]')){n.hidden=!automatic;n.replaceChildren(...lines.map(line=>el('p',line)));}
 }
 function selectedProfile(){return $('mai-embedding-provider').value==='full'?'full':'lite';}
 function selectedHealth(){const profile=selectedProfile(),health=lastStatus?.embedding_service;return (health?.profile||'lite')===profile?health:{profile,managed:true,installed:Boolean(lastStatus?.embedding_models?.[profile]?.installed),phase:lastStatus?.embedding_models?.[profile]?.installed?'installed':'not_installed'};}
 function renderEmbeddingInstall(){
  const native=['native','full'].includes($('mai-embedding-provider').value),profile=selectedProfile(),health=selectedHealth(),installed=health?.managed&&health.installed;
  const supported=profile==='lite'?(lastStatus?.embedding_setup_supported||health?.setup_supported):lastStatus?.embedding_setup_profiles?.includes('full');
  const active=lastStatus?.embedding_install_requested&&(lastStatus.embedding_install_profile||'lite')===profile&&['not_installed','disabled',undefined].includes(health?.phase)||['preparing','runtime','downloading','verifying'].includes(health?.phase);
  const dirty=savedSnapshot!==null&&savedSnapshot!==formSnapshot();
  root.querySelectorAll('[data-embedding-install]').forEach(button=>{
   button.hidden=!native||Boolean(installed);button.disabled=!config?.can_configure_models||!lastStatus?.online||!supported||lastStatus?.embedding_remove?.requested||installBusy||active||saving||(dirty&&settingsBlocked());
   button.textContent=tr(active||installBusy?'Einrichtung läuft …':health?.phase==='error'?'Installation erneut versuchen':dirty?'Speichern und Gemma installieren':'Gemma installieren');
  });
  root.querySelectorAll('[data-embedding-remove]').forEach(button=>{button.hidden=!native||!installed;button.disabled=!config?.can_configure_models||!lastStatus?.online||!lastStatus?.embedding_remove_supported||installBusy||lastStatus?.embedding_remove?.requested||lastStatus?.embedding_install_requested||pipelineRunning('embeddings');button.title=tr(pipelineRunning('embeddings')?'Zum Entfernen zuerst die laufende Verarbeitung pausieren.':'Modell und Laufzeit deinstallieren');});
 }
 root.querySelectorAll('[data-embedding-remove]').forEach(button=>button.addEventListener('click',async()=>{
  if(button.disabled)return;
  const label=tr(selectedProfile()==='full'?'Gemma 2 · Original':'Gemma 2 · sparsam');
  if(!await confirmModelAction('Modell deinstallieren',tr('{model} deinstallieren? Modell und Laufzeit werden gelöscht. Die Bildsuche mit dieser Variante ist bis zur erneuten Installation nicht verfügbar. Beschreibungen, OCR und gespeicherte Vektoren bleiben erhalten.',{model:label}),'Deinstallieren',true))return;
  installBusy=true;renderEmbeddingInstall();try{await api('embedding-remove',{profile:selectedProfile()});message('Deinstallation angefordert. Gespeicherte KI-Daten bleiben erhalten.');await status();}catch(e){message(e.message,true);}finally{installBusy=false;renderEmbeddingInstall();}
 }));
 root.querySelectorAll('[data-embedding-install]').forEach(button=>button.addEventListener('click',async()=>{
  if(button.disabled||installBusy)return;installBusy=true;renderEmbeddingInstall();
  try{
   const full=selectedProfile()==='full';
   if(!await confirmModelAction('Gemma auf diesem Host installieren?',tr(full?'Originalmodell: ca. 1,52 GB Download plus Laufzeit. Empfohlen sind 4 CPU-Kerne, 6 GB freier RAM und 5 GB freier Speicher. Deine Auswahl wird gespeichert. Es startet kein Medienlauf.':'Sparsame Variante: ca. 388 MB Download plus Laufzeit. Empfohlen sind 4 CPU-Kerne, 2 GB freier RAM und 2 GB freier Speicher. Deine Auswahl wird gespeichert. Es startet kein Medienlauf.'),'Installieren'))return;
   if(!await saveForm())return;
   await api('embedding-install',{});message('Gemma-Installation angefordert. Die Installation startet keinen Medienlauf.');await status();
  }catch(e){message(e.message,true);}
  finally{installBusy=false;renderEmbeddingInstall();}
 }));
 form.addEventListener('invalid',e=>{const view=e.target.closest('[data-view]');if(view)workspace.show(view.dataset.view);for(let p=e.target.parentElement;p&&p!==root;p=p.parentElement)if(p.tagName==='DETAILS')p.open=true;},true);
 function renderModels(){
  if(!modelProbe)return;
  const result=$('mai-model-result');result.replaceChildren();
  result.append(el('p',tr('Geprüft: {time}',{time:new Date(modelProbe.checked_at*1000).toLocaleString(i18n.language)})));
  const cards=el('div');cards.className='mai-model-checks';
  const errors={connection_failed:'Verbindung fehlgeschlagen oder Zeitüberschreitung.',authentication_failed:'Authentifizierung am Modellserver fehlgeschlagen.',response_too_large:'Modellserver-Antwort ist zu groß.',invalid_response:'Modellserver liefert keine gültige Modellliste.',server_error:'Modellserver meldet einen Fehler.'};
  const number=x=>Number(x).toLocaleString(i18n.language);
  for(const stage of ['vision','ocr']){
   const info=modelProbe[stage],card=el('section');card.className='mai-model-check';card.append(el('h4',stageName(stage)));
   const state=el('p',!info.reachable?(errors[info.error]||'Verbindung fehlgeschlagen oder Zeitüberschreitung.'):!info.available?'Konfiguriertes Modell nicht verfügbar.':info.loaded===true?'Verbunden · Modell geladen':info.loaded===false?'Verbunden · Modell nicht geladen':'Verbunden · Ladestatus unbekannt');
   if(!info.reachable||!info.available||info.loaded===false)state.className='mai-error';card.append(state);
   const list=el('dl');const item=(label,value)=>list.append(el('dt',label),el('dd',value,true));
   item('Konfiguriertes Modell',info.configured_model);
   if(info.reachable){
    item('Geladener Kontext',info.context_length?number(info.context_length)+' '+tr('Tokens'):tr('Nicht gemeldet'));
    item('Modellmaximum',info.max_context_length?number(info.max_context_length)+' '+tr('Tokens'):tr('Nicht gemeldet'));
    item('Bilder unterstützt',info.vision===true?tr('Ja'):info.vision===false?tr('Nein'):tr('Nicht gemeldet'));
    if(info.quantization)item('Quantisierung',info.quantization);
    if(info.architecture)item('Architektur',info.architecture);
   }
   card.append(list);
   if(info.loaded_models.length){card.append(el('p','Aktuell geladene Modelle'));const loaded=el('ul');for(const m of info.loaded_models){loaded.append(el('li',m.name+' · '+m.instance_id+' · '+(m.context_length?tr('{tokens} Tokens geladen',{tokens:number(m.context_length)}):tr('Kontextgröße nicht gemeldet')),true));}card.append(loaded);}
   if(info.source==='openai'&&info.reachable)card.append(el('p','Dieser Server meldet keine geladenen Instanzen oder Kontextgrößen.'));
   if(info.loaded&&info.context_length&&['qwen35','qwen3vl','qwen3_vl'].includes(info.architecture)){
    const pixels=Number(form.elements[stage+'.model_max_pixels']?.value)||info.model_max_pixels;
    const count=stage==='vision'?(Number(form.elements['video.frame_count']?.value)||modelProbe.video_frames):(Number(form.elements['ocr.max_batch_images']?.value)||modelProbe.ocr_batch_images);
    if(pixels>0){const perImage=Math.ceil(pixels/1024),total=count*perImage,remaining=info.context_length-total;
     card.append(el('p',tr(stage==='vision'?'{count} Video-Frames benötigen bis zu {tokens} Bild-Tokens.':'{count} OCR-Bilder benötigen bis zu {tokens} Bild-Tokens.',{count,tokens:number(total)})));
     const space=el('p',remaining>0?tr('{tokens} Tokens bleiben vor Prompt, Denkprozess und Antwort.',{tokens:number(remaining)}):tr('Die gewählte Bildanzahl überschreitet bereits den geladenen Kontext.'));if(remaining<=0)space.className='mai-error';card.append(space);
     card.append(el('p',tr('Rechnerisch bis zu {count} Bilder ohne Prompt und Ausgabe. Schätzung anhand des eingestellten Qwen-Pixelbudgets.',{count:Math.floor(info.context_length/perImage)})));
    }
   }
   cards.append(card);
  }
  result.append(cards);
 }
 $('mai-test').addEventListener('click',async()=>{
  if(!config||modelProbeBusy||saving||choosingModel||!form.reportValidity())return;
  const snapshot=formSnapshot();modelProbeBusy=true;modelProbeSnapshot=snapshot;modelProbe=null;$('mai-model-result').textContent=tr('Verbindung und Modellstatus werden geprüft …');updateSaveState();
  try{const result=await api('test',{config:formConfig()});if(formSnapshot()!==snapshot)return;modelProbe=result;renderModels();}
  catch(e){if(formSnapshot()===snapshot)$('mai-model-result').textContent=e.message;}
  finally{modelProbeBusy=false;updateSaveState();}
 });
 for(const name of ['video.frame_count','vision.model_max_pixels','ocr.model_max_pixels','ocr.max_batch_images'])form.elements[name].addEventListener('input',renderModels);
 const stageName=s=>tr({vision:'Bildanalyse',ocr:'Texterkennung'}[s]||s);
 function embeddingSetupStatus(s){
  const health=selectedHealth(),profile=selectedProfile(),native=['native','full'].includes($('mai-embedding-provider').value),local=native||config?.embedding?.enabled&&config.embedding.location==='local';
  let text='';
  if(local){
   const queued=s.embedding_install_requested&&(s.embedding_install_profile||'lite')===profile&&['not_installed','disabled',undefined].includes(health?.phase);
   text=!s.online?tr('Worker ist offline. Bitte starten, um Gemma zu installieren oder zu nutzen.'):native&&!(profile==='lite'?(s.embedding_setup_supported||health?.setup_supported):s.embedding_setup_profiles?.includes('full'))?tr('Worker unterstützt die Installation noch nicht. Bitte nach dem App-Update neu starten.'):queued?tr('Gemma-Installation angefordert. Der Worker beginnt in Kürze.'):native&&health?.phase==='error'?tr(health.message):native&&health?.managed&&health.installed?tr(health.ready?'Lokale Bildsuche bereit. Modell und Laufzeit bleiben gespeichert.':'Gemma ist installiert. Aktiviere die visuelle Bildsuche, um es zu nutzen.'):tr(health?.message||'Gemma ist noch nicht installiert. Zum Einrichten „Gemma installieren“ auswählen.');
   if(s.online&&health?.phase==='downloading'&&health.total_bytes>0)text+=' '+tr('{received} von {total} MB · {percent} %',{received:(health.received_bytes/1000000).toLocaleString(i18n.language,{maximumFractionDigits:1}),total:Math.round(health.total_bytes/1000000),percent:Math.min(100,Math.floor(100*health.received_bytes/health.total_bytes))});
  }
  if(s.embedding_remove?.requested&&s.embedding_remove.profile===profile)text=tr('Modell und Laufzeit werden entfernt …');
  if(s.embedding_remove?.phase==='error'&&s.embedding_remove.profile===profile)text=tr(s.embedding_remove.message);
  for(const id of ['mai-embedding-setup-processing','mai-embedding-setup-settings']){const n=$(id);if(!n)continue;n.hidden=id==='mai-embedding-setup-settings'?!native:!local;n.textContent=text;n.classList.toggle('mai-error',Boolean(s.online&&health?.phase==='error'));}
  renderEmbeddingInstall();
 }
 let facesStatusData=null, facesInstallBusy=false;
 async function checkFacesStatus(){
  try{
   facesStatusData=await api('faces-status');
  }catch{
   facesStatusData=null;
  }
  renderFacesInstall();
 }
 function formatFacesMessage(raw){if(!raw)return tr('Installation fehlgeschlagen. Bitte erneut versuchen.');const str=String(raw);if(str.includes('hash mismatch')||str.includes('checksum'))return tr('Prüfsumme der Gesichtsmodelle stimmt nicht überein.');if(str.includes('network')||str.includes('download failed')||str.includes('Connection')||str.includes('timed out'))return tr('Download der Gesichtsmodelle fehlgeschlagen. Bitte Netzwerkverbindung prüfen.');if(str.includes('python')||str.includes('venv')||str.includes('pip'))return tr('Einrichtung der isolierten Python-Umgebung fehlgeschlagen.');const sanitized=str.replace(/\/(?:home|var|tmp|usr|opt|etc)\/[^\s:,]+/g,'').trim();return tr(sanitized||'Installation fehlgeschlagen. Bitte erneut versuchen.');}
 function renderFacesInstall(){
  const s=facesStatusData||lastStatus?.faces_setup;
  const installed=Boolean(s?.installed), ready=Boolean(s?.ready), phase=s?.phase||(installed?'installed':'not_installed');
  const active=facesInstallBusy||lastStatus?.faces_install_requested||['preparing','runtime','downloading','verifying'].includes(phase);
  const statusEl=$('mai-faces-setup-settings');
  if(statusEl){
   statusEl.hidden=false;
   let text='';
   if(!lastStatus?.online){
    text=tr('Worker ist offline. Bitte starten, um Gesichtsmodelle zu installieren.');
   }else if(phase==='preparing'||phase==='runtime'){
    text=tr('Python-Laufzeit wird eingerichtet …');
   }else if(phase==='downloading'){
    text=tr('Gesichtsmodelle (YuNet und SFace) werden heruntergeladen …');
   }else if(phase==='verifying'){
    text=tr('Modelle werden überprüft …');
   }else if(phase==='error'){
    text=formatFacesMessage(s?.message);
   }else if(installed){
    text=ready?tr('Lokale Personenerkennung bereit. Gesichtsmodelle sind eingerichtet.'):tr('Gesichtsmodelle installiert.');
   }else{
    text=tr('Gesichtsmodelle sind noch nicht installiert. Zum Einrichten „Gesichtsmodelle installieren“ auswählen.');
   }
   statusEl.textContent=text;
   statusEl.classList.toggle('mai-error',phase==='error');
  }
  root.querySelectorAll('[data-faces-install]').forEach(button=>{
   button.hidden=installed&&ready;
   button.disabled=!config?.can_configure_models||!lastStatus?.online||facesInstallBusy||active||saving;
   button.textContent=tr(active?'Einrichtung läuft …':phase==='error'?'Installation erneut versuchen':'Gesichtsmodelle installieren');
  });
 }
 root.querySelectorAll('[data-faces-install]').forEach(button=>button.addEventListener('click',async()=>{
  if(button.disabled||facesInstallBusy)return;
  if(!await confirmModelAction('Gesichtsmodelle auf diesem Host installieren?',tr('YuNet und SFace als ONNX-Modelle (OpenCV Zoo) herunterladen und einrichten. Deine Einstellungen werden gespeichert. Es startet kein Medienlauf.'),'Installieren'))return;
  facesInstallBusy=true;renderFacesInstall();
  try{
   if(!await saveForm())return;
   await api('faces-install',{});
   message('Installation der Gesichtsmodelle angefordert.');
   await checkFacesStatus();
   await status();
  }catch(e){message(e.message,true);}
  finally{facesInstallBusy=false;renderFacesInstall();}
 }));
 async function status(){try{lastStatus=await api('status');await processing();await checkFacesStatus();embeddingSetupStatus(lastStatus);updateSaveState();workspace.status(lastStatus);}catch(e){message(e.message,true);}}
 api('config').then(c=>{canManage=true;config=c;loadForm();workspace.start();status();}).catch(e=>message(e.status===403?'Nur Administratoren dürfen diese Einstellungen öffnen.':e.message,true));
 const timer=setInterval(()=>{if(!document.contains(root)){clearInterval(timer);return;}if(canManage&&!document.hidden)status();},5000);
}
if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',init);else init();
})();
