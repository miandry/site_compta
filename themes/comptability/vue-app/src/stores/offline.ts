import { defineStore } from 'pinia'
import { computed, ref } from 'vue'
import { pendingCount } from '@/offline/outbox'
import { onConnectivityChange, isOnline } from '@/offline/network'
import { flushOutbox, onSyncState } from '@/offline/sync'
import { useUiStore } from './ui'

export const useOfflineStore = defineStore('offline', () => {
  const online = ref(isOnline())
  const pending = ref(0)
  const syncing = ref(false)
  const lastError = ref('')
  let started = false

  const statusLabel = computed(() => {
    if (!online.value) {
      return pending.value
        ? `Hors ligne · ${pending.value} en attente`
        : 'Hors ligne · consultation du cache'
    }
    if (syncing.value) return 'Synchronisation…'
    if (pending.value) return `${pending.value} modification(s) à synchroniser`
    return ''
  })

  async function refreshPending() {
    pending.value = await pendingCount()
  }

  async function syncNow(quiet = false) {
    if (!online.value) return
    const result = await flushOutbox()
    await refreshPending()
    if (!quiet && result.flushed) {
      useUiStore().notify('success', `${result.flushed} élément(s) synchronisé(s).`)
    }
    if (result.error) {
      lastError.value = result.error
      if (!quiet) useUiStore().notify('error', `Sync : ${result.error}`)
    }
  }

  function start() {
    if (started) return
    started = true
    online.value = isOnline()
    void refreshPending()
    onConnectivityChange((up) => {
      online.value = up
      if (up) {
        useUiStore().notify('info', 'Connexion rétablie — synchronisation…')
        void syncNow()
      }
      else {
        useUiStore().notify('info', 'Vous êtes hors ligne. Les saisies seront mises en file.')
      }
    })
    onSyncState((state) => {
      syncing.value = state.syncing
      pending.value = state.pending
      if (state.lastError) lastError.value = state.lastError
    })
    if (online.value) void syncNow(true)
  }

  return {
    online,
    pending,
    syncing,
    lastError,
    statusLabel,
    refreshPending,
    syncNow,
    start,
  }
})
