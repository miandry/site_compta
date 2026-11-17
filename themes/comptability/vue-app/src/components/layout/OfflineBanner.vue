<script setup lang="ts">
import { useOfflineStore } from '@/stores/offline'

const offline = useOfflineStore()
</script>

<template>
  <div
    v-if="offline.statusLabel"
    class="flex items-center justify-between gap-3 px-4 py-2 text-xs font-semibold sm:text-sm"
    :class="offline.online
      ? 'bg-amber-100 text-amber-900'
      : 'bg-neu-dark text-white'"
    role="status"
  >
    <p class="min-w-0 flex-1 truncate">{{ offline.statusLabel }}</p>
    <button
      v-if="offline.online && offline.pending && !offline.syncing"
      type="button"
      class="shrink-0 underline"
      @click="offline.syncNow()"
    >
      Synchroniser
    </button>
  </div>
</template>
