import english from './en.json';
const language=typeof document==='undefined'?'de':JSON.parse(document.getElementById('chronicle')?.dataset.context||'{}').language||document.documentElement.lang||'de';
export const locale=language.startsWith('de')?'de-AT':'en-GB';
export function tr(text,...values){
 if(Array.isArray(text)){const key=text.reduce((s,part,i)=>s+(i?`{${i-1}}`:'')+part,'');const template=locale==='de-AT'?key:english[key]||key;return template.replace(/\{(\d+)\}/g,(_,n)=>String(values[Number(n)]));}
 return locale==='de-AT'?text:english[text]||text;
}
