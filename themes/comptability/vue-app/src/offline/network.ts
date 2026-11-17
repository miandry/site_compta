/** Online / offline detection (navigator + browser events). */

export function isOnline(): boolean {
  return typeof navigator === 'undefined' ? true : navigator.onLine
}

export function isNetworkError(error: unknown): boolean {
  if (!error || typeof error !== 'object') return false
  const err = error as { code?: string; message?: string; response?: unknown }
  if (err.response) return false
  const msg = String(err.message ?? '')
  return err.code === 'ERR_NETWORK' || /network|offline|failed to fetch/i.test(msg)
}

export function onConnectivityChange(cb: (online: boolean) => void): () => void {
  const up = () => cb(true)
  const down = () => cb(false)
  window.addEventListener('online', up)
  window.addEventListener('offline', down)
  return () => {
    window.removeEventListener('online', up)
    window.removeEventListener('offline', down)
  }
}
