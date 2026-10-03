const request=r=>new Promise((resolve,reject)=>{r.onsuccess=()=>resolve(r.result);r.onerror=()=>reject(r.error);});
export async function openStore(key) {
 const r=indexedDB.open(`chronicle-${key}`,1);r.onupgradeneeded=()=>{r.result.createObjectStore('items',{keyPath:'id'});r.result.createObjectStore('blobs');r.result.createObjectStore('meta');};return request(r);
}
export function transaction(db,stores,mode,fn) {return new Promise((resolve,reject)=>{const t=db.transaction(stores,mode);let result;try{result=fn(t);}catch(e){t.abort();reject(e);return;}t.oncomplete=()=>resolve(result);t.onabort=t.onerror=()=>reject(t.error||new Error('Lokaler Speicher nicht verfügbar.'));});}
export async function readCollection(db) {return request(db.transaction('items').objectStore('items').getAll());}
export async function readMeta(db) {return request(db.transaction('meta').objectStore('meta').get('collection'));}
export async function readBlob(db,id) {return request(db.transaction('blobs').objectStore('blobs').get(id));}
export async function saveItem(db,item,blob) {return transaction(db,['items','blobs'],'readwrite',t=>{t.objectStore('items').put(item);t.objectStore('blobs').put(blob,item.id);});}
export async function saveMeta(db,meta) {return transaction(db,['meta'],'readwrite',t=>t.objectStore('meta').put(meta,'collection'));}
export async function clear(db) {return transaction(db,['items','blobs','meta'],'readwrite',t=>{for(const s of ['items','blobs','meta'])t.objectStore(s).clear();});}
