const DB_VERSION = 1;
const STORES = {SENSOR_DATA:'sensorData', PRESET_MODES:'presetModes', EVENTS:'events', SETTINGS:'settings'};
const MAX_CACHE_AGE = 24 * 60 * 60 * 1000;
let cacheContext = null;
let databasePromise = null;
function contextKey() { return cacheContext ? `${cacheContext.userId}:${cacheContext.deviceId}` : ''; }
function closeDatabase() {
  const pending = databasePromise; databasePromise = null;
  if (pending) pending.then(db => db.close()).catch(() => {});
}
function setCacheContext(userId, deviceId) {
  if (!Number.isSafeInteger(Number(userId)) || Number(userId) < 1 || !/^[A-Za-z0-9_-]{1,64}$/.test(String(deviceId))) {
    closeDatabase(); cacheContext = null; return;
  }
  closeDatabase(); cacheContext = {userId:Number(userId), deviceId:String(deviceId)};
}
function initDatabase() {
  const scope = contextKey();
  if (!scope) return Promise.reject(new Error('Cache requires an authenticated user and device context'));
  if (databasePromise) return databasePromise;
  databasePromise = new Promise((resolve, reject) => {
    let settled=false;
    const fail=error => { settled=true; clearTimeout(timer); reject(error); };
    const timer = setTimeout(() => fail(new Error('IndexedDB open timed out')), 8000);
    let request;
    try { request = indexedDB.open('farmCacheDB:' + scope, DB_VERSION); }
    catch (error) { clearTimeout(timer); reject(error); return; }
    request.onerror = () => fail(request.error);
    request.onblocked = () => fail(new Error('IndexedDB upgrade blocked'));
    request.onupgradeneeded = () => {
      const db = request.result;
      for (const [name,key] of [[STORES.SENSOR_DATA,'timestamp'],[STORES.PRESET_MODES,'id'],[STORES.EVENTS,'id'],[STORES.SETTINGS,'key']]) {
        if (!db.objectStoreNames.contains(name)) {
          const store = db.createObjectStore(name,{keyPath:key, autoIncrement:name===STORES.EVENTS});
          store.createIndex('user_id','user_id',{unique:false});
          if (name===STORES.EVENTS) store.createIndex('date','date',{unique:false});
        }
      }
    };
    request.onsuccess = () => {
      clearTimeout(timer); const db=request.result;
      if (settled || scope !== contextKey()) { db.close(); fail(new Error('Cache context changed')); return; }
      settled=true;
      db.onversionchange = () => { db.close(); databasePromise=null; };
      resolve(db);
    };
  });
  const pending=databasePromise;
  pending.catch(() => { if (databasePromise===pending) databasePromise=null; });
  return databasePromise;
}
async function cacheTransaction(storeName, mode, operation) {
  const scope=contextKey(), db=await initDatabase();
  if (scope!==contextKey()) throw new Error('Cache context changed');
  return new Promise((resolve,reject) => {
    let result;
    const tx=db.transaction(storeName,mode);
    const timer=setTimeout(() => { try { tx.abort(); } catch {} reject(new Error('IndexedDB transaction timed out')); },8000);
    tx.oncomplete=() => { clearTimeout(timer); scope===contextKey() ? resolve(result) : reject(new Error('Cache context changed')); };
    tx.onerror=tx.onabort=() => { clearTimeout(timer); reject(tx.error || new Error('IndexedDB transaction failed')); };
    try { operation(tx.objectStore(storeName), value => { result=value; }); }
    catch(error) { clearTimeout(timer); try { tx.abort(); } catch {} reject(error); }
  });
}
function saveToCache(storeName,data) {
  return cacheTransaction(storeName,'readwrite',(store,done) => {
    for (const item of Array.isArray(data) ? data : [data]) store.put({...item,cached_at:Date.now()});
    done('Данные сохранены');
  });
}
function isDataFresh(data) { return !!data?.cached_at && Date.now()-data.cached_at<MAX_CACHE_AGE; }
function getFromCache(storeName,key=null,indexDetails=null) {
  return cacheTransaction(storeName,'readonly',(store,done) => {
    const req=key!==null ? store.get(key) : indexDetails ? store.index(indexDetails.indexName).getAll(indexDetails.value) : store.getAll();
    req.onsuccess=() => done(key!==null ? (isDataFresh(req.result) ? req.result : null) : (req.result||[]).filter(isDataFresh));
  });
}
function deleteFromCache(storeName,key) { return cacheTransaction(storeName,'readwrite',(store,done) => { store.delete(key); done(true); }); }
function clearStore(storeName) { return cacheTransaction(storeName,'readwrite',(store,done) => { store.clear(); done(true); }); }
async function fetchDataWithCache(apiUrl,storeName,options={},cacheOptions={}) {
  const scope=contextKey();
  const refresh=async () => {
    const response=await fetch(apiUrl,{method:'GET',credentials:'include',...options});
    if (!response.ok) throw new Error(`HTTP ${response.status}`);
    const data=await response.json();
    if (scope!==contextKey()) throw new Error('Cache context changed during request');
    if (scope) await saveToCache(storeName,data).catch(() => {});
    return data;
  };
  try {
    const cached=await getFromCache(storeName,cacheOptions.key ?? null,cacheOptions.indexName ? {indexName:cacheOptions.indexName,value:cacheOptions.indexValue} : null);
    if (cached && Object.keys(cached).length) { void refresh().catch(() => {}); return cached; }
  } catch { /* Cache failures never prevent a network request. */ }
  return refresh();
}
function clearCacheContext() { closeDatabase(); cacheContext=null; }
window.addEventListener('pagehide', closeDatabase);
window.addEventListener('fitodomik:logout', clearCacheContext);
// The historical unscoped database is never read again.
try { indexedDB.deleteDatabase('farmCacheDB'); } catch {}
window.cacheManager={initDatabase,saveToCache,getFromCache,deleteFromCache,clearStore,fetchDataWithCache,STORES,setContext:setCacheContext,clearContext:clearCacheContext};
