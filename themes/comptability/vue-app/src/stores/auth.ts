import { defineStore } from 'pinia'
import { computed, ref } from 'vue'
import { cacheUser, getCachedUser } from '@/offline/cache'
import { isNetworkError, isOnline } from '@/offline/network'
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
      try {
        await cacheUser(user.value ? {
          id: user.value.id,
          name: user.value.name,
          email: user.value.email,
          roles: [...user.value.roles],
        } : null)
      }
      catch {
        /* best-effort */
      }
    }
    catch (err) {
      if (!isOnline() || isNetworkError(err)) {
        user.value = await getCachedUser()
      }
      else {
        user.value = null
      }
    }
    finally {
      loading.value = false
      initialized.value = true
    }
  }

  async function login(username: string, password: string) {
    loading.value = true
    error.value = ''
    try {
      const next = await authService.login(username, password)
      user.value = next
      // Cache a plain copy; never block login if IndexedDB fails.
      try {
        await cacheUser({
          id: next.id,
          name: next.name,
          email: next.email,
          roles: [...next.roles],
        })
      }
      catch {
        /* offline cache is best-effort */
      }
    }
    catch (err) {
      error.value = extractErrorMessage(err)
      throw err
    }
    finally {
      loading.value = false
    }
  }

  async function logout() {
    await authService.logout()
    user.value = null
    await cacheUser(null)
  }

  return { user, loading, error, initialized, isAuthenticated, isAdmin, init, login, logout }
})
