import { createApp } from 'vue'
import { createPinia } from 'pinia'
import App from './App.vue'
import router from './router'
import './assets/css/main.css'

// iOS Safari ignores user-scalable=no: block pinch and double-tap zoom by hand.
const blockZoom = (e: Event) => e.preventDefault()
;['gesturestart', 'gesturechange', 'gestureend'].forEach((t) => document.addEventListener(t, blockZoom, { passive: false }))
document.addEventListener('touchmove', (e) => { if (e.touches.length > 1) e.preventDefault() }, { passive: false })
let lastTouchEnd = 0
document.addEventListener('touchend', (e) => {
  const now = Date.now()
  if (now - lastTouchEnd < 300) e.preventDefault()
  lastTouchEnd = now
}, { passive: false })

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
