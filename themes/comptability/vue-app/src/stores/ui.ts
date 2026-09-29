import { defineStore } from 'pinia'
import { ref } from 'vue'

export const useUiStore = defineStore('ui', () => {
  const sidebarOpen = ref(false)
  const toast = ref<{ type: 'success' | 'error' | 'info'; message: string } | null>(null)
  let toastTimer: ReturnType<typeof setTimeout> | null = null

  function toggleSidebar() {
    sidebarOpen.value = !sidebarOpen.value
  }

  function closeSidebar() {
    sidebarOpen.value = false
  }

  function notify(type: 'success' | 'error' | 'info', message: string) {
    toast.value = { type, message }
    if (toastTimer) clearTimeout(toastTimer)
    toastTimer = setTimeout(() => {
      toast.value = null
    }, 3500)
  }

  return { sidebarOpen, toast, toggleSidebar, closeSidebar, notify }
})
