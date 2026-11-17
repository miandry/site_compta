/**
 * Same registration as app.cache.mg: scope `/`, updateViaCache none.
 * Only runs in production builds served by Drupal.
 */
const SW_URL = '/sw.js'

export async function registerPwaServiceWorker(): Promise<ServiceWorkerRegistration | null> {
  if (typeof navigator === 'undefined' || !('serviceWorker' in navigator)) {
    return null
  }
  try {
    return await navigator.serviceWorker.register(SW_URL, {
      scope: '/',
      updateViaCache: 'none',
    })
  }
  catch (err) {
    console.error('Service worker registration failed:', err)
    try {
      const existing = await navigator.serviceWorker.getRegistration('/')
      if (existing) await existing.unregister()
    }
    catch { /* ignore */ }
    return null
  }
}
