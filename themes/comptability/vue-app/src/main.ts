import { createApp } from 'vue'
import { createPinia } from 'pinia'
import App from './App.vue'
import router from './router'
import './assets/css/main.css'

// Also enforced here so it holds even when Drupal serves a cached <head>.
const NO_ZOOM = 'width=device-width, initial-scale=1.0, minimum-scale=1.0, maximum-scale=1.0, user-scalable=no, viewport-fit=cover'
let viewport = document.querySelector<HTMLMetaElement>('meta[name="viewport"]')
if (!viewport) {
  viewport = document.createElement('meta')
  viewport.name = 'viewport'
  document.head.appendChild(viewport)
}
viewport.content = NO_ZOOM

// A zoom left by a focused field (or a closed popup) stays after blur on iOS:
// rewriting the viewport forces the browser back to scale 1.
function resetZoom() {
  if (!viewport || (window.visualViewport?.scale ?? 1) === 1) return
  viewport.content = `${NO_ZOOM}, shrink-to-fit=no`
  requestAnimationFrame(() => { if (viewport) viewport.content = NO_ZOOM })
}
document.addEventListener('focusout', () => setTimeout(resetZoom, 50))
window.visualViewport?.addEventListener('resize', () => {
  if (!document.activeElement || document.activeElement === document.body) resetZoom()
})

// iOS Safari ignores user-scalable=no: block pinch and double-tap zoom by hand.
const blockZoom = (e: Event) => e.preventDefault()
;['gesturestart', 'gesturechange', 'gestureend'].forEach((t) => document.addEventListener(t, blockZoom, { passive: false }))
document.addEventListener('touchmove', (e) => { if (e.touches.length > 1) e.preventDefault() }, { passive: false })

function mount() {
  const el = document.querySelector('#vue-app')
  if (!el) return
  createApp(App).use(createPinia()).use(router).mount(el)
}

if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', mount)
} else {
  mount()
}
