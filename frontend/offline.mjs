const request = r => new Promise((resolve, reject) => {
 r.onsuccess = () => resolve(r.result);
 r.onerror = () => reject(r.error);
});

export async function openStore(key) {
 // Keep schema v1 readable by an already saved offline HTML/JS shell.
 const r = indexedDB.open(`nextgallery-${key}`, 1);
 r.onupgradeneeded = () => {
  const db = r.result;
  if (!db.objectStoreNames.contains('items')) db.createObjectStore('items', {keyPath: 'id'});
  if (!db.objectStoreNames.contains('blobs')) db.createObjectStore('blobs');
  if (!db.objectStoreNames.contains('meta')) db.createObjectStore('meta');
 };
 const db = await request(r);
 db.onversionchange = () => db.close();
 return db;
}

export function transaction(db, stores, mode, fn) {
 return new Promise((resolve, reject) => {
  const t = db.transaction(stores, mode);
  let result;
  try { result = fn(t); } catch (error) { t.abort(); reject(error); return; }
  t.oncomplete = () => resolve(result);
  t.onabort = t.onerror = () => reject(t.error || new Error('Lokaler Speicher nicht verfügbar.'));
 });
}

export async function readCollection(db) { return request(db.transaction('items').objectStore('items').getAll()); }
export async function readMeta(db) { return request(db.transaction('meta').objectStore('meta').get('collection')); }
export async function readBlob(db, id) { return request(db.transaction('blobs').objectStore('blobs').get(id)); }
export async function saveItem(db, item, blob) {
 return transaction(db, ['items', 'blobs'], 'readwrite', t => {
  t.objectStore('items').put(item);
  t.objectStore('blobs').put(blob, item.id);
 });
}
export async function saveMeta(db, meta) {
 return transaction(db, ['meta'], 'readwrite', t => t.objectStore('meta').put(meta, 'collection'));
}

function removeStage(t, generation) {
 const prefix = 'stage-item:' + generation + ':';
 const cursor = t.objectStore('meta').openCursor(IDBKeyRange.bound(prefix, prefix + '\uffff'));
 cursor.onsuccess = () => {
  const row = cursor.result;
  if (!row) return;
  t.objectStore('blobs').delete([generation, row.value.id]);
  row.delete();
  row.continue();
 };
 t.objectStore('meta').delete('stage:' + generation);
}

/** Downloads never overwrite the active collection, including in another tab. */
export async function beginCollection(db) {
 const generation = crypto.randomUUID();
 await transaction(db, ['meta', 'blobs'], 'readwrite', t => {
  const meta = t.objectStore('meta');
  const epoch = meta.get('epoch');
  epoch.onsuccess = () => meta.put({epoch: epoch.result || '', started: Date.now()}, 'stage:' + generation);
  // Reclaim abandoned downloads after a crash without touching a live tab.
  const sessions = meta.openCursor();
  sessions.onsuccess = () => {
   const row = sessions.result;
   if (!row) return;
   if (String(row.key).startsWith('stage:') && row.value.started < Date.now() - 3600000) {
    removeStage(t, String(row.key).slice(6));
   }
   row.continue();
  };
 });
 return generation;
}

export async function stageItem(db, generation, item, blob) {
 return transaction(db, ['meta', 'blobs'], 'readwrite', t => {
  t.objectStore('meta').put({generation, id: item.id, item}, 'stage-item:' + generation + ':' + item.id);
  t.objectStore('blobs').put(blob, [generation, item.id]);
 });
}

/** One transaction publishes metadata, items and blobs, or none of them. */
export async function commitCollection(db, generation, meta) {
 return transaction(db, ['items', 'blobs', 'meta'], 'readwrite', t => {
  const metadata = t.objectStore('meta');
  const session = metadata.get('stage:' + generation);
  session.onsuccess = () => {
   if (!session.result) { t.abort(); return; }
   const epoch = metadata.get('epoch');
   epoch.onsuccess = () => {
    // Removal/logout in another tab must not resurrect an in-flight download.
    if (session.result.epoch !== (epoch.result || '')) { t.abort(); return; }
    t.objectStore('items').clear();
    // Active IDs are numeric; staged blobs use [generation, id] array keys.
    const copyStage = () => {
     let copied = 0;
     const prefix = 'stage-item:' + generation + ':';
     const cursor = metadata.openCursor(IDBKeyRange.bound(prefix, prefix + '\uffff'));
     cursor.onsuccess = () => {
      const row = cursor.result;
      if (!row) {
       if (copied !== meta.count) { t.abort(); return; }
       metadata.put(meta, 'collection');
       metadata.delete('stage:' + generation);
       metadata.put(crypto.randomUUID(), 'epoch');
       return;
      }
      const blob = t.objectStore('blobs').get([generation, row.value.id]);
      blob.onsuccess = () => {
       if (!(blob.result instanceof Blob) || !blob.result.size) { t.abort(); return; }
       t.objectStore('items').put(row.value.item);
       t.objectStore('blobs').put(blob.result, row.value.id);
       t.objectStore('blobs').delete([generation, row.value.id]);
       row.delete();
       copied++;
       row.continue();
      };
     };
    };
    const oldBlobs = t.objectStore('blobs').openKeyCursor(IDBKeyRange.bound(0, Number.MAX_VALUE));
    oldBlobs.onsuccess = () => {
     if (!oldBlobs.result) { copyStage(); return; }
     t.objectStore('blobs').delete(oldBlobs.result.primaryKey);
     oldBlobs.result.continue();
    };
   };
  };
 });
}

export async function discardCollection(db, generation) {
 return transaction(db, ['meta', 'blobs'], 'readwrite', t => removeStage(t, generation));
}

export async function clear(db) {
 return transaction(db, ['items', 'blobs', 'meta'], 'readwrite', t => {
  for (const name of ['items', 'blobs', 'meta']) t.objectStore(name).clear();
  t.objectStore('meta').put(crypto.randomUUID(), 'epoch');
 });
}
