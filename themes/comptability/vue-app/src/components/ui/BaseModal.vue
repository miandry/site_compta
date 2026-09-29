<script setup lang="ts">
import { onMounted, onUnmounted } from 'vue'

const props = defineProps<{
  title: string
  open: boolean
}>()

const emit = defineEmits<{ close: [] }>()

function onKey(e: KeyboardEvent) {
  if (e.key === 'Escape' && props.open) emit('close')
}

onMounted(() => window.addEventListener('keydown', onKey))
onUnmounted(() => window.removeEventListener('keydown', onKey))
</script>

<template>
  <Teleport to="body">
    <div
      v-if="open"
      class="fixed inset-0 z-50 flex items-end justify-center bg-neu-dark/25 p-4 sm:items-center"
      @click.self="emit('close')"
    >
      <div
        class="card w-full max-w-lg overflow-hidden p-1"
        role="dialog"
        aria-modal="true"
        :aria-label="title"
      >
        <div class="flex items-center justify-between px-6 py-5">
          <h2 class="text-lg font-semibold text-neu-dark">{{ title }}</h2>
          <button type="button" class="btn-ghost px-2 py-1" aria-label="Fermer" @click="emit('close')">
            ✕
          </button>
        </div>
        <div class="px-6 pb-6">
          <slot />
        </div>
      </div>
    </div>
  </Teleport>
</template>
