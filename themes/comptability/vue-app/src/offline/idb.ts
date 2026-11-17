/** Minimal IndexedDB helpers (no extra dependency). */

const DB_NAME = 'comptability-offline'
const DB_VERSION = 1

export type StoreName = 'meta' | 'terms' | 'lookups' | 'lists' | 'outbox'

function openDb(): Promise<IDBDatabase> {
  return new Promise((resolve, reject) => {
    const req = indexedDB.open(DB_NAME, DB_VERSION)
    req.onupgradeneeded = () => {
      const db = req.result
      for (const name of ['meta', 'terms', 'lookups', 'lists', 'outbox'] as StoreName[]) {
        if (!db.objectStoreNames.contains(name)) {
          db.createObjectStore(name, { keyPath: 'key' })
        }
      }
    }
    req.onsuccess = () => resolve(req.result)
    req.onerror = () => reject(req.error ?? new Error('IndexedDB open failed'))
  })
}

async function withStore<T>(
  store: StoreName,
  mode: IDBTransactionMode,
  fn: (s: IDBObjectStore) => IDBRequest<T> | void,
): Promise<T | void> {
  const db = await openDb()
  return new Promise((resolve, reject) => {
    const tx = db.transaction(store, mode)
    const s = tx.objectStore(store)
    let req: IDBRequest<T> | undefined
    try {
      const result = fn(s)
      if (result) req = result
    }
    catch (err) {
      reject(err)
      return
    }
    tx.oncomplete = () => resolve(req ? req.result : undefined)
    tx.onerror = () => reject(tx.error ?? new Error('IndexedDB tx failed'))
    if (req) {
      req.onerror = () => reject(req!.error)
    }
  })
}

export async function idbGet<T>(store: StoreName, key: string): Promise<T | undefined> {
  const row = await withStore<{ key: string; value: T } | undefined>(store, 'readonly', (s) => s.get(key))
  return row?.value
}

export async function idbSet<T>(store: StoreName, key: string, value: T): Promise<void> {
  await withStore(store, 'readwrite', (s) => {
    s.put({ key, value })
  })
}

export async function idbDelete(store: StoreName, key: string): Promise<void> {
  await withStore(store, 'readwrite', (s) => {
    s.delete(key)
  })
}

export async function idbGetAll<T>(store: StoreName): Promise<Array<{ key: string; value: T }>> {
  const db = await openDb()
  return new Promise((resolve, reject) => {
    const tx = db.transaction(store, 'readonly')
    const req = tx.objectStore(store).getAll()
    req.onsuccess = () => resolve((req.result ?? []) as Array<{ key: string; value: T }>)
    req.onerror = () => reject(req.error)
  })
}
