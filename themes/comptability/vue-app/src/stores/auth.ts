import { defineStore } from 'pinia'
import { computed, ref } from 'vue'
import * as authService from '@/services/auth.service'
import type { User } from '@/services/auth.service'
import { extractErrorMessage } from '@/utils/format'

export const useAuthStore = defineStore('auth', () => {
  const user = ref<User | null>(null)
  const loading = ref(false)
  const error = ref('')
  const initialized = ref(false)

  const isAuthenticated = computed(() => Boolean(user.value))
  /** Même règle que le module comptability_access : uid 1 ou rôle administrator. */
  const isAdmin = computed(() => Boolean(user.value && (user.value.id === '1' || user.value.roles.includes('administrator'))))

  async function init() {
    if (initialized.value) return
    loading.value = true
    try {
      user.value = await authService.fetchCurrentUser()
    } catch {
      user.value = null
    } finally {
      loading.value = false
      initialized.value = true
    }
  }

  async function login(username: string, password: string) {
    loading.value = true
    error.value = ''
    try {
      user.value = await authService.login(username, password)
    } catch (err) {
      error.value = extractErrorMessage(err)
      throw err
    } finally {
      loading.value = false
    }
  }

  async function logout() {
    await authService.logout()
    user.value = null
  }

  return { user, loading, error, initialized, isAuthenticated, isAdmin, init, login, logout }
})
