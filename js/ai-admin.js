(() => {
'use strict';
async function init() {
 const root=document.getElementById('media-ai-admin'); if(!root || root.dataset.ready)return; root.dataset.ready='1';
 const i18n=window.MediaAIi18n;i18n.language=root.closest('.mai-shell')?.dataset.language||document.documentElement.lang;i18n.apply(root);root.lang=i18n.language;const tr=(key,values)=>i18n.t(key,values);
 const $=id=>document.getElementById(id); let config=null,entries=[],cursor=null,logBusy=false,lastStatus=null,canManage=false,modelProbe=null,installBusy=false;
 const api=async(path,body)=>{const r=await fetch(OC.generateUrl('/apps/nextgallery/ai/admin/'+path),{method:body?'POST':'GET',credentials:'same-origin',headers:{'requesttoken':OC.requestToken,'Content-Type':'application/json','Accept':'application/json'},body:body?JSON.stringify(body):undefined}); if(!r.ok){let detail;try{detail=(await r.json()).error;}catch{}const error=Error(detail?tr(detail):tr('Anfrage fehlgeschlagen (HTTP {code}).',{code:r.status}));error.status=r.status;throw error;}return r.json();};
 const message=(text,error=false)=>{$('mai-message').textContent=tr(text);$('mai-message').classList.toggle('mai-error',error);};
 const el=(tag,text,raw=false)=>{const n=document.createElement(tag);if(text!==undefined)n.textContent=raw?text:tr(text);return n;};
 function promptField(stage,key,label,rows){const l=el('label',label),t=el('textarea');t.name=stage+'.'+key;t.rows=rows;t.maxLength=20000;t.dataset.defaultPrompt='1';l.append(t);const actions=el('div');actions.className='mai-actions mai-prompt-actions';const reset=el('button','Standardprompt wiederherstellen');reset.type='button';reset.dataset.promptReset='1';reset.setAttribute('aria-label',tr('Standardprompt wiederherstellen')+' · '+tr(label)+' · '+stageNameForPrompt(stage));const note=el('span');note.className='mai-muted';const standard=()=>config?.[stage]?.['default_'+key]||'';const update=()=>note.textContent=tr(!t.value||t.value===standard()?'Standardprompt aktiv':'Eigener Prompt');t.addEventListener('input',update);reset.onclick=()=>{t.value=standard();t.dispatchEvent(new Event('input',{bubbles:true}));};actions.append(reset,note);return [l,actions];}
 function stageNameForPrompt(stage){return tr(stage==='vision'?'Bildanalyse':'Texterkennung');}
 function fields(stage,label){const section=el('details');section.className='mai-model';section.append(el('summary',label));const grid=el('div');grid.className='mai-grid';const advanced=el('details'),advancedGrid=el('div');advanced.append(el('summary','Erweiterte Modelleinstellungen'));advancedGrid.className='mai-grid';advanced.append(advancedGrid);
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
 fields('vision','Beschreibungen und Objekte');fields('ocr','Texterkennung (OCR)');const descriptionEnabled=el('label','Für Benutzer aktivieren'),descriptionCheck=el('input');descriptionEnabled.className='mai-check';descriptionCheck.type='checkbox';descriptionCheck.name='analysis_enabled';descriptionEnabled.prepend(descriptionCheck);$('mai-model-settings').querySelector('details').querySelector('summary').after(descriptionEnabled);
 function processingExplanation(){
  const form=$('mai-form'),analysis=form.elements.analysis_enabled.checked,dense=form.elements['embedding.enabled'].checked,local=form.elements['embedding.location'].value==='local';
  const gemma=form.elements['embedding.family'].value==='gemma2',automatic=local&&gemma&&form.elements['embedding.local_auto_setup'].checked;
  const parts=['Dateinamen und Pfade'];if(analysis)parts.push('Beschreibungen, Tags, Objekte und OCR');if(dense)parts.push('ähnliche Bildinhalte');
  $('mai-search-explanation').textContent=tr('Suche: ')+parts.map(x=>tr(x)).join(' + ')+'. '+tr('Bereits gespeicherte KI-Texte bleiben auch bei ausgeschalteter Verarbeitung durchsuchbar.');
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
  root.querySelectorAll('[data-embedding-rebuild-notice]').forEach(n=>{n.hidden=!(dense&&(lastStatus?.embedding_rebuild?.required||changed&&Number(config?.embedding_count)>0));n.textContent=tr('Modellwechsel: Die Bildvektoren müssen passend zum neuen Modell neu aufgebaut werden. Beim nächsten Lauf wird deine Bestätigung angefordert. Die Textsuche mit Beschreibungen, Tags und OCR bleibt verfügbar.');});
  const provider=!local?'external':(form.elements['embedding.local_profile'].value==='full'?'full':'native');root.querySelectorAll('[data-embedding-provider]').forEach(n=>n.value=provider);
 }
 root.querySelectorAll('[data-embedding-provider]').forEach(select=>select.addEventListener('change',()=>{
  const provider=select.value,form=$('mai-form');form.elements['embedding.location'].value=provider==='external'?'external':'local';form.elements['embedding.local_auto_setup'].checked=['native','full'].includes(provider);form.elements['embedding.transport'].value='multimodal';
  if(['native','full'].includes(provider)){form.elements['embedding.local_profile'].value=provider==='full'?'full':'lite';form.elements['embedding.family'].value='gemma2';form.elements['embedding.model'].value=provider==='full'?'embeddinggemma-2-full':'embeddinggemma-2';}
  updateSaveState();if(lastStatus)embeddingSetupStatus(lastStatus);
 }));
 for(const name of ['analysis_enabled','embedding.enabled','embedding.location','embedding.family','embedding.local_auto_setup'])$('mai-form').elements[name].addEventListener('change',processingExplanation);
 $('mai-form').elements['embedding.family'].addEventListener('change',()=>{$('mai-form').elements['embedding.model'].value=$('mai-form').elements['embedding.family'].value==='gemma2'?'embeddinggemma-2':'Qwen/Qwen3-VL-Embedding-2B';updateSaveState();});
 $('mai-embedding-test').onclick=async()=>{const button=$('mai-embedding-test');button.disabled=true;const target=$('mai-embedding-result');target.textContent=tr('Embedding-Dienst wird geprüft …');try{const info=await api('embedding-test',{});target.classList.toggle('mai-error',!info.ready);target.textContent=info.ready?tr('Bereit für Bilder, Video-Standbilder und Suchanfragen.')+' '+info.model+' · '+info.dimensions:tr(info.message);}catch(e){target.classList.add('mai-error');target.textContent=e.message;}finally{button.disabled=false;updateSaveState();}};
 const workspace=window.MediaAIWorkspace.create({root,api,tr,el,configuration:()=>config});
 const form=$('mai-form');
 let savedSnapshot=null,saving=false,saveError=false;
 const formSnapshot=()=>JSON.stringify([...form.elements].filter(i=>i.name&&!i.disabled&&!i.readOnly).map(i=>[i.name,i.type==='checkbox'?i.checked:i.value]));
 function updateSaveState(){
  const dirty=savedSnapshot!==null&&formSnapshot()!==savedSnapshot;
  if(!dirty)saveError=false;
  const state=savedSnapshot===null?'loading':saving?'saving':dirty?(saveError?'error':'dirty'):'saved';
  const labels={loading:'Einstellungen werden geladen …',saving:'Wird gespeichert …',dirty:'Ungespeicherte Änderungen',error:'Nicht gespeichert · erneut versuchen',saved:'Gespeichert'};
  root.querySelectorAll('[data-save-status]').forEach(n=>{n.textContent=tr(labels[state]);n.dataset.state=state;});
  root.querySelectorAll('button[type="submit"]').forEach(b=>b.disabled=!dirty||saving||lastStatus?.state==='running');
  $('mai-savebar').hidden=!dirty&&!saving;
  const setupBusy=lastStatus?.online&&config?.embedding?.enabled&&config.embedding.location==='local'&&['preparing','runtime','downloading','verifying'].includes(lastStatus.embedding_service?.phase);
  $('mai-embedding-test').disabled=savedSnapshot===null||dirty||saving||setupBusy||(['native','full'].includes($('mai-embedding-provider').value)&&(!lastStatus?.embedding_service?.ready||(lastStatus.embedding_service.profile||'lite')!==selectedProfile()));
  $('mai-embedding-test').textContent=tr(setupBusy?'Einrichtung läuft …':'Verbindung prüfen');
  processingExplanation();
  renderEmbeddingInstall();
 }
 for(const event of ['input','change'])form.addEventListener(event,e=>{if(e.target.name)updateSaveState();});
 function loadForm(){for(const input of form.elements){if(!input.name)continue;const parts=input.name.split('.');let v=parts.length===2?config[parts[0]][parts[1]]:config[parts[0]];if(input.dataset.defaultPrompt)v=v||config[parts[0]]['default_'+parts[1]];if(input.type==='checkbox')input.checked=Boolean(v);else if(Array.isArray(v))input.value=v.join('\n');else input.value=v??'';} form.querySelectorAll('[data-image-edge]').forEach(i=>i.dispatchEvent(new Event('input')));const allowed=config.can_configure_models;$('mai-existing-count').textContent=tr('{count} gespeicherte Analysen für {user}',{count:Number(config.metadata_count||0).toLocaleString(i18n.language),user:config.user_id});form.elements.user_id.readOnly=true; $('mai-model-settings').querySelectorAll('input,select,button').forEach(i=>i.disabled=!allowed);$('mai-model-settings').querySelectorAll('textarea').forEach(i=>{i.readOnly=!allowed;i.dispatchEvent(new Event('input'));});root.querySelectorAll('#mai-embedding-settings input,#mai-embedding-settings select,[data-model-admin]').forEach(i=>i.disabled=!allowed);processingExplanation();$('mai-permissions').textContent=allowed?'':tr('Modellserver und Benutzer können nur Nextcloud-Administratoren ändern. Du kannst deine Ordner und Verarbeitung hier verwalten.'); savedSnapshot=formSnapshot();saveError=false;updateSaveState(); }
 async function saveForm(){
  if(!config||saving)return false;if(savedSnapshot===formSnapshot())return true;if(lastStatus?.state==='running'||!form.reportValidity())return false;
  const submittedSnapshot=formSnapshot(),v=structuredClone(config);
  for(const i of form.elements){if(!i.name)continue;let x=i.type==='checkbox'?i.checked:i.type==='number'?Number(i.value):i.value;const parts=i.name.split('.');if(['folders','exclude_folders'].includes(i.name))x=i.value.split('\n').map(x=>x.trim()).filter(Boolean);if(i.dataset.defaultPrompt&&x===config[parts[0]]['default_'+parts[1]])x='';if(parts.length===2)v[parts[0]][parts[1]]=x;else v[parts[0]]=x;}
  saving=true;saveError=false;updateSaveState();
  try{config=await api('config',{config:v});if(formSnapshot()===submittedSnapshot)loadForm();else savedSnapshot=submittedSnapshot;message('');return true;}
  catch(e){saveError=true;message(e.message,true);return false;}
  finally{saving=false;updateSaveState();}
 }
 form.addEventListener('submit',async e=>{e.preventDefault();await saveForm();});
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
  ]):['Der Dienst läuft auf deinem externen Rechner. Installation und Anforderungen stehen in der Anleitung.'];
  for(const n of root.querySelectorAll('[data-embedding-requirements]')){n.replaceChildren(...lines.map(line=>el('p',line)));}
 }
 function selectedProfile(){return $('mai-embedding-provider').value==='full'?'full':'lite';}
 function selectedHealth(){const profile=selectedProfile(),health=lastStatus?.embedding_service;return (health?.profile||'lite')===profile?health:{profile,managed:true,installed:Boolean(lastStatus?.embedding_models?.[profile]?.installed),phase:lastStatus?.embedding_models?.[profile]?.installed?'installed':'not_installed'};}
 function renderEmbeddingInstall(){
  const native=['native','full'].includes($('mai-embedding-provider').value),profile=selectedProfile(),health=selectedHealth(),installed=health?.managed&&health.installed;
  const supported=profile==='lite'?(lastStatus?.embedding_setup_supported||health?.setup_supported):lastStatus?.embedding_setup_profiles?.includes('full');
  const active=lastStatus?.embedding_install_requested&&(lastStatus.embedding_install_profile||'lite')===profile&&['not_installed','disabled',undefined].includes(health?.phase)||['preparing','runtime','downloading','verifying'].includes(health?.phase);
  const dirty=savedSnapshot!==null&&savedSnapshot!==formSnapshot();
  root.querySelectorAll('[data-embedding-install]').forEach(button=>{
   button.hidden=!native||Boolean(installed);button.disabled=!config?.can_configure_models||!lastStatus?.online||!supported||lastStatus?.embedding_remove?.requested||installBusy||active||saving||(dirty&&lastStatus?.state==='running');
   button.textContent=tr(active||installBusy?'Einrichtung läuft …':health?.phase==='error'?'Installation erneut versuchen':dirty?'Speichern und Gemma installieren':'Gemma installieren');
  });
  root.querySelectorAll('[data-embedding-remove]').forEach(button=>{button.hidden=!native||!installed;button.disabled=!config?.can_configure_models||!lastStatus?.online||!lastStatus?.embedding_remove_supported||installBusy||lastStatus?.embedding_remove?.requested||lastStatus?.embedding_install_requested||lastStatus?.state==='running';button.title=tr(lastStatus?.state==='running'?'Zum Entfernen zuerst die laufende Verarbeitung pausieren.':'Modell und Laufzeit deinstallieren');});
 }
 root.querySelectorAll('[data-embedding-remove]').forEach(button=>button.addEventListener('click',async()=>{
  if(button.disabled)return;
  const label=selectedProfile()==='full'?'Gemma 2 · Original':'Gemma 2 · sparsam';
  if(!await confirmModelAction('Modell deinstallieren',tr('{model} deinstallieren? Modell und Laufzeit werden gelöscht. Die Bildsuche mit dieser Variante ist bis zur erneuten Installation nicht verfügbar. Beschreibungen, OCR und gespeicherte Vektoren bleiben erhalten.',{model:label}),'Deinstallieren',true))return;
  installBusy=true;renderEmbeddingInstall();try{await api('embedding-remove',{profile:selectedProfile()});message('Deinstallation angefordert. Gespeicherte KI-Daten bleiben erhalten.');await status();}catch(e){message(e.message,true);}finally{installBusy=false;renderEmbeddingInstall();}
 }));
 root.querySelectorAll('[data-embedding-install]').forEach(button=>button.addEventListener('click',async()=>{
  if(button.disabled||installBusy)return;installBusy=true;renderEmbeddingInstall();
  try{
   if(!await saveForm())return;
   await api('embedding-install',{});message('Gemma-Installation angefordert. Die Installation startet keinen Medienlauf.');await status();
  }catch(e){message(e.message,true);}
  finally{installBusy=false;renderEmbeddingInstall();}
 }));
 let controlBusy=false;
 function renderControls(){
  const s=lastStatus, running=s?.state==='running', waiting=Boolean(s?.control?.requested)&&!(s?.embedding_rebuild?.required&&config?.embedding?.enabled), paused=Boolean(s?.control?.paused), remaining=Number(s?.stats?.pending||0)>0;
  const allowed={start:!running&&!waiting,pause:(running||waiting)&&!paused,resume:!running&&!waiting&&paused&&remaining,retry:!running&&!waiting&&Number(s?.stats?.error||0)>0};
  root.querySelectorAll('[data-action]').forEach(button=>{
   const action=button.dataset.action;button.hidden=(running||waiting)?action!=='pause':action==='pause'||(action==='resume'&&(!remaining||!paused))||(action==='retry'&&!Number(s?.stats?.error||0));
   button.disabled=controlBusy||!s?.online||Boolean(s?.control?.reset_requested)||!allowed[action];
   button.classList.toggle('primary',!button.disabled&&(running||waiting? action==='pause':paused&&remaining?action==='resume':Number(s?.stats?.error||0)>0&&!remaining?action==='retry':action==='start'));
   button.textContent=tr(action==='pause'&&paused?'Pausiere …':{start:'Lauf starten',pause:'Pausieren',resume:'Fortsetzen',retry:'Fehler erneut versuchen'}[action]);
  });
  $('mai-control-hint').textContent=tr(!s?.online?'Worker ist offline.':waiting&&!running?'Start angefordert. Der Worker übernimmt in Kürze.':running&&paused?'Pause angefordert. Die aktuelle Datei wird noch abgeschlossen.':running?'Pausieren greift nach der aktuellen Datei.':remaining?'Fortsetzen bearbeitet offene Dateien; ein neuer Lauf prüft die gesamte Auswahl.':Number(s?.stats?.error||0)>0?'Fehler können gezielt erneut versucht werden. Ein neuer Lauf prüft die Auswahl.':'Ein neuer Lauf prüft die Auswahl auf neue und geänderte Dateien.');
 }
 root.querySelectorAll('[data-action]').forEach(button=>button.addEventListener('click',async()=>{
  if(controlBusy||button.disabled)return;controlBusy=true;renderControls();
  try{
   if(!await saveForm())return;await status();const rebuild=lastStatus?.embedding_rebuild;let rebuildId='';
   if(config.embedding.enabled&&rebuild?.required&&['start','resume','retry'].includes(button.dataset.action)){
    let explanation=tr('Das Embedding-Modell oder seine Einstellungen wurden geändert. Vorhandene Bildvektoren passen möglicherweise nicht mehr – auch bei gleicher Vektorlänge. Für die gewählten Ordner werden passende Vektoren neu berechnet. Während des Neuaufbaus können Treffer in der Bildsuche fehlen. Die Textsuche bleibt verfügbar. Beschreibungen, Tags und OCR bleiben erhalten.');
    if(rebuild.old_dimensions&&rebuild.new_dimensions)explanation+=' '+tr('Vektorlänge: {old} → {new}.',{old:rebuild.old_dimensions,new:rebuild.new_dimensions});
    if(!await confirmModelAction('Bildvektoren neu aufbauen?',explanation,'Neu berechnen und starten'))return;rebuildId=rebuild.request_id;
   }
   const control=await api('control',{action:button.dataset.action,embedding_rebuild_id:rebuildId});if(lastStatus)lastStatus.control=control;message('');await status();}catch(e){message(e.message,true);await status();}finally{controlBusy=false;renderControls();}
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
 $('mai-test').addEventListener('click',async()=>{const button=$('mai-test');button.disabled=true;$('mai-model-result').textContent=tr('Verbindung und Modellstatus werden geprüft …');try{modelProbe=await api('test',{});renderModels();}catch(e){modelProbe=null;$('mai-model-result').textContent=e.message;}finally{button.disabled=false;}});
 for(const name of ['video.frame_count','vision.model_max_pixels','ocr.model_max_pixels','ocr.max_batch_images'])form.elements[name].addEventListener('input',renderModels);
 let pickerTarget='folders',pickerPath='/',pickerSelected=new Set();
 async function pick(path){try{const r=await api('folders?'+new URLSearchParams({path}));pickerPath=r.path;$('mai-picker-path').textContent=path;$('mai-picker-current').checked=pickerSelected.has(path);$('mai-picker-list').replaceChildren();for(const f of r.folders){const row=el('div');row.className='mai-picker-row';const label=el('label',f.name),checkbox=el('input');label.className='mai-check';checkbox.type='checkbox';checkbox.checked=pickerSelected.has(f.path);checkbox.onchange=()=>{if(checkbox.checked){pickerSelected.delete('/');pickerSelected.add(f.path);$('mai-picker-current').checked=pickerSelected.has(pickerPath);}else pickerSelected.delete(f.path);selected();};label.prepend(checkbox);const open=el('button','Öffnen');open.type='button';open.onclick=()=>pick(f.path);label.lastChild.textContent=f.name;row.append(label,open);$('mai-picker-list').append(row);}selected();}catch(e){message(e.message,true);}}
 function selected(){$('mai-picker-selected').textContent=[...pickerSelected].join(' · ')||tr('Keine Ordner ausgewählt');}
 function openPicker(target){pickerTarget=target;pickerSelected=new Set(form.elements[target].value.split('\n').map(x=>x.trim()).filter(Boolean));$('mai-picker').showModal();pick('/');}
 $('mai-pick-folders').onclick=()=>openPicker('folders');$('mai-pick-excludes').onclick=()=>openPicker('exclude_folders');
 $('mai-picker-parent').onclick=()=>pick(pickerPath.replace(/\/[^/]+\/?$/,'')||'/');
 $('mai-picker-current').onchange=()=>{if($('mai-picker-current').checked){if(pickerPath==='/')pickerSelected.clear();else pickerSelected.delete('/');pickerSelected.add(pickerPath);}else pickerSelected.delete(pickerPath);selected();};
 $('mai-picker-apply').onclick=()=>{form.elements[pickerTarget].value=[...pickerSelected].join('\n');form.elements[pickerTarget].dispatchEvent(new Event('input',{bubbles:true}));$('mai-picker').close();};$('mai-picker-close').onclick=()=>$('mai-picker').close();
 const events={run_start:'Lauf gestartet',inventory_start:'Inventar gestartet',inventory_finished:'Inventar eingelesen',file_start:'Datei gestartet',stage_start:'Stufe gestartet',stage_finished:'Stufe abgeschlossen',file_finished:'Datei abgeschlossen',file_error:'Dateifehler',run_finished:'Lauf beendet',worker_error:'Worker-Fehler',service_unavailable:'Verarbeitung wegen Dienstausfall pausiert',embedding_finished:'Bildsuche vorbereitet',embedding_error:'Fehler bei der Bildsuche',embedding_inventory_finished:'Bildsuch-Inventar eingelesen',ai_reset_finished:'KI-Daten zurückgesetzt',inference_attempt_start:'Modellversuch gestartet',inference_attempt_finished:'Modellversuch abgeschlossen',inference_attempt_error:'Modellversuch verworfen'};const stageName=s=>tr({vision:'Bildanalyse',ocr:'Texterkennung',download:'Datei laden',embedding:'Visuelle Bildsuche'}[s]||s);
 const names={running:'Analyse läuft',idle:'Bereit',paused:'Pausiert',finished:'Lauf abgeschlossen',deferred:'GPU nicht verfügbar',error:'Fehler',not_started:'Noch nicht gestartet',stopped:'Gestoppt'};
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
  for(const id of ['mai-embedding-setup-processing','mai-embedding-setup-settings']){const n=$(id);if(!n)continue;n.hidden=!local;n.textContent=text;n.classList.toggle('mai-error',Boolean(s.online&&health?.phase==='error'));}
  const n=$('mai-embedding-health');n.hidden=!local;n.textContent=text;n.classList.toggle('mai-error',Boolean(s.online&&health?.phase==='error'));
  renderEmbeddingInstall();
 }
 function formatDuration(value){
  const seconds=Math.max(0,Math.round(Number.isFinite(value)?value:0));
  const hours=Math.floor(seconds/3600),minutes=Math.floor(seconds%3600/60);
  return [hours?hours+' h':'',minutes?minutes+' min':'',seconds%60+' s'].filter(Boolean).join(' ');
 }
 async function status(){try{const s=await api('status');lastStatus=s;$('mai-online').textContent=tr(s.online?'Worker online':'Worker offline')+' · '+tr(names[s.state]||s.state);$('mai-online').dataset.state=s.online?s.state:'offline';$('mai-counts').replaceChildren();for(const[k,title]of[['done','Erledigte Verarbeitungsschritte'],['pending','Offen'],['error','Fehler']]){const d=el('div');d.className='mai-count';d.append(el('strong',Number(s.stats?.[k]||0).toLocaleString(i18n.language)),el('span',title));$('mai-counts').append(d);}$('mai-stage-progress').textContent=[['analysis_stats','Beschreibung und Texterkennung'],['embedding_stats','Visuelle Bildsuche']].map(([key,title])=>tr(title)+': '+((key==='analysis_stats'?!config.analysis_enabled:!config.embedding.enabled)?tr('Verarbeitung ausgeschaltet') :tr('{done} erledigt · {pending} offen · {error} Fehler',{done:s[key]?.done||0,pending:s[key]?.pending||0,error:s[key]?.error||0}))).join(' | ');embeddingSetupStatus(s);renderControls();updateSaveState();workspace.status(s);const c=s.current;$('mai-current').replaceChildren();if(c&&s.state==='running'){const path=el('span',c.path,true);path.className='mai-current-path';path.title=c.path;const stage=el('span',stageName(c.stage)+(c.attempt?' · '+tr('Versuch {attempt}/{total}',{attempt:c.attempt,total:c.total_attempts}):''));stage.className='mai-current-stage';const elapsed=el('span',Math.max(0,Math.round(Date.now()/1000-s.stage_started_at))+' s');elapsed.className='mai-current-time';$('mai-current').append(path,stage,elapsed);if(c.reasoning_chars!==undefined){const progress=el('span',tr('{count} Zeichen empfangen',{count:c.reasoning_chars+c.content_chars}));progress.className='mai-current-progress';$('mai-current').append(progress);}}else $('mai-current').textContent=(['error','deferred'].includes(s.state)||s.control?.pause_reason)?tr(s.message||''):s.message==='KI-Daten zurückgesetzt'?tr(s.message):'';const r=s.last_run;$('mai-last').textContent=r?tr('Letzter Lauf: {files} Dateien · {duration} · {published} gespeichert · {cache} Cache-Treffer',{files:r.processed,duration:formatDuration(Number(r.seconds||0)),published:r.published||0,cache:r.cached_stage_results||0}):'';}catch(e){message(e.message,true);}}
 // Older file-completion entries lack seconds; pair their existing start/end timestamps.
 function fileDurations(rows){
  const starts=new Map(),durations=new Map();
  for(const row of [...rows].reverse()){
   if(!row.file_id&&!row.path)continue;
   const key=JSON.stringify([row.run_id??null,row.file_id||row.path]);
   if(row.event==='file_start')starts.set(key,Date.parse(row.time));
   if(row.event==='file_finished'||row.event==='file_error'){
    const start=starts.get(key),end=Date.parse(row.time);
    if(row.seconds===undefined&&Number.isFinite(start)&&Number.isFinite(end)&&end>=start)durations.set(row,(end-start)/1000);
    starts.delete(key);
   }
  }
  return durations;
 }
 function renderLogs(){$('mai-logs').replaceChildren();const durations=fileDurations(entries);const mode=$('mai-log-level').value;const visible=entries.filter(x=>mode==='error'?(x.level==='error'||x.event==='inference_attempt_error'):mode==='files'?['file_finished','file_error','run_start','run_finished','worker_error','service_unavailable','ai_reset_finished','embedding_finished','embedding_error','inference_attempt_error'].includes(x.event):true);for(const row of visible){const tableRow=el('tr');if(row.level==='error'||row.event==='inference_attempt_error')tableRow.className='mai-error';const clock=el('td',new Date(row.time).toLocaleTimeString(i18n.language));clock.title=row.time;const eventCell=el('td');const eventLabel=el('span',events[row.event]||row.event);eventLabel.className='mai-log-event';eventLabel.dataset.event=row.event;eventCell.append(eventLabel);tableRow.append(clock,eventCell);const td=el('td');const mobileEvent=el('span',events[row.event]||row.event);mobileEvent.className='mai-log-mobile-event';td.append(mobileEvent);if(row.path&&row.file_id){const a=el('a',row.path.split('/').filter(Boolean).at(-1),true);a.className='mai-log-file';a.title=row.path;a.setAttribute('aria-label',row.path);a.href=OC.generateUrl('/f/'+encodeURIComponent(row.file_id));td.append(a);const parent=row.path.slice(0,row.path.lastIndexOf('/'));if(parent){const breadcrumb=el('span',parent,true);breadcrumb.className='mai-log-path';breadcrumb.title=parent;td.append(breadcrumb);}}else td.append(el('span',row.path||'',true));if(row.stage)td.append(el('span',' · '+stageName(row.stage)));if(row.attempt)td.append(el('span',' · '+tr('Versuch {attempt}/{total}',{attempt:row.attempt,total:row.total_attempts||Number(config?.[row.stage]?.loop_retries??3)+1})));if(row.state==='timeout')td.append(el('strong',' · '+tr('Zeitgrenze pro Bild überschritten')));if(row.state==='loop')td.append(el('strong',' · '+tr('Wiederholungsschleife erkannt')));if(row.channel)td.append(el('span',' · '+(row.channel==='reasoning_content'?'Reasoning':tr('Antwort'))));if(row.retrying!==undefined)td.append(el('span',' · '+tr(row.retrying?'Automatische Korrektur angefordert':'Keine weiteren Korrekturversuche')));if(row.streamed===false)td.append(el('span',' · '+tr('Antwort nicht gestreamt')));if(row.output_channel==='reasoning_json_workaround')td.append(el('span',' · '+tr('JSON aus Reasoning-Feld')));if(row.empty_text===true)td.append(el('span',' · '+tr('Gültiges JSON: kein Text erkannt')));const technical=el('details'),technicalContent=el('div');technical.className='mai-log-technical';technical.append(el('summary','Analyse-Details'),technicalContent);for(const [stage,info] of Object.entries(row.images||(row.image?{[row.stage]:row.image}:{}))){if(info.sent_width)technicalContent.append(el('p',stageName(stage)+' · '+tr('{width} × {height} Pixel · {mp} MP · vollständiges Bild',{width:info.sent_width,height:info.sent_height,mp:(info.sent_pixels/1000000).toLocaleString(i18n.language,{maximumFractionDigits:2})})+(info.resized?' · '+tr('Verkleinert'):'')));}for(const [stage,info] of Object.entries(row.images||(row.image?{[row.stage]:row.image}:{}))){if(info.image_count>1)technicalContent.append(el('p',stageName(stage)+' · '+tr('{count} Bilder gemeinsam in einer Anfrage',{count:info.image_count})));if(info.frames)technicalContent.append(el('p',tr('Video: {seconds} s · gleichmäßige Frame-Abstände',{seconds:info.duration_seconds.toFixed(2)})));}if(technicalContent.childElementCount)td.append(technical);if(row.error)td.append(el('p',row.error));if(row.reason)td.append(el('p',row.reason));if(row.temperature!==undefined)td.append(el('span',' · '+tr('Temperatur')+' '+row.temperature));if(row.usage?.completion_tokens)td.append(el('span',' · '+row.usage.completion_tokens+' Tokens'));if(row.cached)td.append(el('span',' · Cache'));if(row.event==='run_finished')td.append(el('span',tr('{steps} Verarbeitungsschritte · {published} Ergebnisse gespeichert',{steps:row.processed||0,published:row.published||0})));if(row.event==='file_finished'&&row.corrective_retries)td.append(el('strong',' · '+tr('Abgeschlossen · Korrekturversuche: {count}',{count:row.corrective_retries})));if(row.file_id&&row.event==='file_finished'){const detail=el('details'),summary=el('summary','Ergebnis ansehen'),content=el('div');detail.className='mai-log-result';detail.append(summary,content);detail.addEventListener('toggle',async()=>{if(!detail.open || detail.dataset.loaded)return;content.textContent=tr('KI-Metadaten laden …');try{const result=await api('result?'+new URLSearchParams({file_id:row.file_id}));content.replaceChildren();if(!result.available){content.append(el('p','Für dieses Bild liegt noch keine Analyse vor.'));return;}const a=result.metadata.analysis;content.append(el('h4','Bildbeschreibung'),el('p',a.vision.description,true),el('h4','Typ und Suchbegriffe'),el('p',[a.vision.type,...i18n.keywords(a.vision)].join(' · '),true),el('h4','Erkannter Text'),el('pre',a.ocr.source==='skipped_video'?tr('OCR bei Videos ausgelassen.'):a.ocr.text||tr('Kein Text erkannt.'),true));if(a.vision.uncertain_details.length)content.append(el('h4','Unsichere Angaben'),el('p',a.vision.uncertain_details.join('\n'),true));detail.dataset.loaded='1';}catch(e){content.textContent=e.message;}});td.append(detail);}const duration=el('td',row.seconds!==undefined?Number(row.seconds).toLocaleString(i18n.language,{maximumFractionDigits:3})+' s':durations.has(row)?'≈ '+durations.get(row).toLocaleString(i18n.language)+' s':'—');duration.className='mai-duration';if(row.seconds===undefined&&durations.has(row))duration.title=tr('Aus Start- und Endzeit im Log berechnet; auf Sekunden gerundet.');tableRow.append(td,duration);$('mai-logs').append(tableRow);}if(!visible.length){const tableRow=el('tr'),td=el('td',$('mai-log-level').value==='error'?'Keine Fehler an diesem Tag.':'Keine Ereignisse an diesem Tag.');td.colSpan=4;td.className='mai-empty';tableRow.append(td);$('mai-logs').append(tableRow);} $('mai-log-older').hidden=cursor===null;}
 async function logs(older=false){if(logBusy)return;logBusy=true;try{const q=new URLSearchParams({date:$('mai-log-date').value,cursor:String(older?cursor||0:0)});const r=await api('logs?'+q);entries=older?entries.concat(r.entries):r.entries;cursor=r.cursor;renderLogs();}catch(e){message(e.message,true);}finally{logBusy=false;}}
 let maintenanceAction=null;
 async function maintenance(action){if(!lastStatus?.control?.paused || lastStatus.state==='running'){message('Bitte zuerst pausieren und das Ende der aktuellen Datei abwarten.',true);return;}if(action==='reset_ai'){const c=await api('config');config.metadata_count=c.metadata_count;config.embedding_count=c.embedding_count;}maintenanceAction=action;$('mai-maintenance-title').textContent=tr(action==='clear_logs'?'Aktivitätslog leeren':'Alle KI-Daten löschen');$('mai-maintenance-info').textContent=tr(action==='clear_logs'?'Nur die Aktivitätslogs werden gelöscht. KI-Ergebnisse und Originale bleiben erhalten.':'Alle gespeicherten KI-Ergebnisse, OCR-Texte, Bild-Embeddings und der Analyse-Cache werden gelöscht. Bilder und Videos bleiben unverändert. Anschließend musst du einen neuen Lauf starten.');$('mai-maintenance-count').textContent=action==='reset_ai'?tr('Alle {count} gespeicherten Analysen des Kontos {user} werden gelöscht, auch außerhalb der aktuell gewählten Ordner.',{count:Number(config.metadata_count||0).toLocaleString(i18n.language),user:config.user_id})+' '+tr('Zusätzlich werden Bildsuch-Daten für {count} Medien gelöscht.',{count:Number(config.embedding_count||0).toLocaleString(i18n.language)}):'';$('mai-maintenance-check').checked=false;$('mai-maintenance-confirm').disabled=true;$('mai-maintenance').showModal();}
 $('mai-clear-logs').onclick=()=>maintenance('clear_logs').catch(e=>message(e.message,true));$('mai-reset-ai').onclick=()=>maintenance('reset_ai').catch(e=>message(e.message,true));$('mai-maintenance-cancel').onclick=()=>$('mai-maintenance').close();$('mai-maintenance-check').onchange=()=>{$('mai-maintenance-confirm').disabled=!$('mai-maintenance-check').checked;};
 $('mai-maintenance-confirm').onclick=async()=>{try{await api('maintenance',{action:maintenanceAction,confirmation:maintenanceAction,expected_count:config.metadata_count});$('mai-maintenance').close();message(maintenanceAction==='clear_logs'?'Aktivitätslog geleert.':'KI-Reset angefordert. Der Worker setzt nur die Analysedaten zurück.');await logs();await status();}catch(e){message(e.message,true);}};
 const now=new Date();$('mai-log-date').value=[now.getFullYear(),String(now.getMonth()+1).padStart(2,'0'),String(now.getDate()).padStart(2,'0')].join('-');
 $('mai-log-refresh').onclick=()=>logs();$('mai-log-older').onclick=()=>{$('mai-log-live').checked=false;logs(true);};$('mai-log-date').onchange=()=>{$('mai-log-live').checked=false;logs();};$('mai-log-level').onchange=renderLogs;
 let accountsLoaded=false;
 async function accountConfig(){const uid=$('mai-account-select').value;if(!uid)return;try{const c=await api('account-config?'+new URLSearchParams({user_id:uid}));for(const input of $('mai-account-form').elements){if(!input.name)continue;if(input.type==='checkbox')input.checked=c[input.name];else input.value=Array.isArray(c[input.name])?c[input.name].join('\n'):c[input.name]||'';}$('mai-account-message').textContent='';}catch(e){$('mai-account-message').textContent=e.message;}}
 async function accounts(){try{const data=await api('accounts?'+new URLSearchParams({query:$('mai-account-search').value}));const selected=$('mai-account-select').value;$('mai-account-select').replaceChildren(...data.accounts.map(user=>{const option=el('option',user.name+' · '+user.id,true);option.value=user.id;return option;}));if([...$('mai-account-select').options].some(o=>o.value===selected))$('mai-account-select').value=selected;else if([...$('mai-account-select').options].some(o=>o.value===config.user_id))$('mai-account-select').value=config.user_id;accountsLoaded=true;await accountConfig();}catch(e){message(e.message,true);}}
 $('mai-account-search-button').onclick=accounts;$('mai-account-select').onchange=accountConfig;
 $('mai-account-form').onsubmit=async event=>{event.preventDefault();const v={};for(const input of $('mai-account-form').elements){if(!input.name)continue;v[input.name]=input.type==='checkbox'?input.checked:input.name.endsWith('folders')?input.value.split('\n').map(x=>x.trim()).filter(Boolean):input.value;}try{await api('account-config',{user_id:$('mai-account-select').value,config:v});$('mai-account-message').textContent=tr('Einstellungen gespeichert.');}catch(e){$('mai-account-message').textContent=e.message;}};
 root.addEventListener('media-ai-view',()=>{if(config&&workspace.view()==='accounts'&&!accountsLoaded)accounts();});
 api('config').then(c=>{canManage=true;config=c;loadForm();workspace.start();if(workspace.view()==='accounts')accounts();status();}).catch(e=>{if(e.status===403){for(const id of ['mai-savebar','mai-library','mai-pipeline-status','mai-pipeline-settings','mai-advanced-settings','mai-pipeline-logs','mai-pipeline-maintenance'])$(id).hidden=true;workspace.restrict();message('Nur Administratoren dürfen diese Einstellungen öffnen.',true);}else message(e.message,true);});let tick=0;const timer=setInterval(()=>{if(!document.contains(root)){clearInterval(timer);return;}if(!canManage)return;if(!document.hidden)status();if(++tick%2===0 && $('mai-log-live').checked && workspace.view()==='activity' && !document.hidden && !root.querySelector('#mai-logs details[open]') && root.querySelector('.mai-table').scrollTop===0)logs();},5000);root.addEventListener('media-ai-view',()=>{if(canManage&&workspace.view()==='activity'&&!entries.length)logs();});
}
if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',init);else init();
})();
