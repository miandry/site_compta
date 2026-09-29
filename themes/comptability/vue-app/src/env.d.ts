/// <reference types="vite/client" />

declare module '*.vue' {
  import type { DefineComponent } from 'vue'
  const component: DefineComponent<object, object, unknown>
  export default component
}

interface DrupalSettings {
  path?: { baseUrl?: string }
  comptability?: { siteName?: string }
}

interface Window {
  drupalSettings?: DrupalSettings
}
