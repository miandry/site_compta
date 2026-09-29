import { createApp } from 'vue'
import { createPinia } from 'pinia'
import App from './App.vue'
import router from './router'
import './assets/css/main.css'

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
