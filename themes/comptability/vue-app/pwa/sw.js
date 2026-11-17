/**
 * Web PWA service worker (pattern from app.cache.mg):
 * - precaches the Drupal front shell + theme assets so the installed app boots offline
 * - never caches API or cross-origin requests
 *
 * PRECACHE_URLS + PRECACHE_NAME are injected at build time by scripts/generate-pwa.mjs.
 */
const PRECACHE_NAME = "comptability-precache-mwkrb3ec"
const APP_SHELL_URL = "/"
const PRECACHE_URLS = ["/","/apple-icon-180.png","/favicon-196.png","/manifest-icon-192.maskable.png","/manifest-icon-512.maskable.png","/manifest.webmanifest","/sites/default/themes/comptability/css/style.css","/sites/default/themes/comptability/dist/app.js","/sites/default/themes/comptability/dist/assets/main.css"]
const MATCH_OPTIONS = { ignoreVary: true }

const isThemeAsset = (pathname) =>
  pathname.startsWith("/sites/default/themes/comptability/")

const isApi = (pathname) =>
  pathname.startsWith("/api_solutions") || pathname.startsWith("/comptability-access")

/** Hash SPA lives on the Drupal front page only. */
const isAppShellNav = (pathname) => pathname === "/" || pathname === ""

self.addEventListener("install", (event) => {
  event.waitUntil(
    (async () => {
      const cache = await caches.open(PRECACHE_NAME)
      await Promise.all(
        PRECACHE_URLS.map(async (url) => {
          if (isThemeAsset(url) && (await cache.match(url, MATCH_OPTIONS))) {
            return
          }
          const response = await fetch(url, { cache: "reload" })
          if (!response.ok) {
            throw new Error(`Precache failed for ${url}: ${response.status}`)
          }
          await cache.put(url, response)
        }),
      )
      await self.skipWaiting()
    })(),
  )
})

self.addEventListener("activate", (event) => {
  event.waitUntil(
    (async () => {
      const cacheKeys = await caches.keys()
      await Promise.all(
        cacheKeys
          .filter((cacheKey) => cacheKey !== PRECACHE_NAME)
          .map((cacheKey) => caches.delete(cacheKey)),
      )

      const current = new Set(PRECACHE_URLS)
      const cache = await caches.open(PRECACHE_NAME)
      const requests = await cache.keys()
      await Promise.all(
        requests
          .filter((request) => !current.has(new URL(request.url).pathname))
          .map((request) => cache.delete(request)),
      )

      await self.clients.claim()
    })(),
  )
})

const handleNavigation = async (request) => {
  const cache = await caches.open(PRECACHE_NAME)
  try {
    const response = await fetch(request)
    const contentType = response.headers.get("content-type") ?? ""
    if (response.ok && !response.redirected && contentType.includes("text/html")) {
      await cache.put(APP_SHELL_URL, response.clone())
    }
    return response
  } catch (error) {
    const shell = await cache.match(APP_SHELL_URL, MATCH_OPTIONS)
    if (shell) {
      return shell
    }
    throw error
  }
}

const handleThemeAsset = async (request) => {
  const cache = await caches.open(PRECACHE_NAME)
  const cached = await cache.match(request, MATCH_OPTIONS)
  if (cached) {
    return cached
  }
  const response = await fetch(request)
  if (response.ok) {
    await cache.put(request, response.clone())
  }
  return response
}

const handleStaticFile = async (request) => {
  try {
    return await fetch(request)
  } catch (error) {
    const cached = await caches.match(request, MATCH_OPTIONS)
    if (cached) {
      return cached
    }
    throw error
  }
}

self.addEventListener("fetch", (event) => {
  const { request } = event
  if (request.method !== "GET") {
    return
  }

  const url = new URL(request.url)
  if (url.origin !== self.location.origin || isApi(url.pathname)) {
    return
  }

  if (request.mode === "navigate" && isAppShellNav(url.pathname)) {
    event.respondWith(handleNavigation(request))
    return
  }

  if (isThemeAsset(url.pathname)) {
    event.respondWith(handleThemeAsset(request))
    return
  }

  event.respondWith(handleStaticFile(request))
})

self.addEventListener("push", (event) => {
  let data = { title: "Comptabilité", body: "" }

  if (event.data) {
    try {
      data = { ...data, ...event.data.json() }
    } catch {
      data.body = event.data.text()
    }
  }

  event.waitUntil(
    self.registration.showNotification(data.title ?? "Comptabilité", {
      body: data.body,
      icon: "/manifest-icon-192.maskable.png",
      badge: "/manifest-icon-192.maskable.png",
    }),
  )
})
