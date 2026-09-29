<script setup lang="ts">
defineProps<{
  steps: string[]
  current: number
}>()

defineEmits<{
  go: [index: number]
}>()
</script>

<template>
  <div class="mb-6">
    <div class="mb-3 flex items-center justify-between gap-2">
      <p class="text-xs font-semibold uppercase tracking-wide text-neu-muted">
        Étape {{ current + 1 }} / {{ steps.length }}
      </p>
      <p class="truncate text-sm font-medium text-neu-dark">{{ steps[current] }}</p>
    </div>

    <div class="flex items-center gap-2">
      <button
        v-for="(label, index) in steps"
        :key="label"
        type="button"
        class="h-2.5 flex-1 rounded-pill transition"
        :class="index <= current ? 'bg-accent shadow-neu-accent' : 'shadow-neu-out-sm opacity-70'"
        :style="index <= current ? '' : 'background: #e0e5ec'"
        :aria-label="label"
        :aria-current="index === current ? 'step' : undefined"
        @click="$emit('go', index)"
      />
    </div>
  </div>
</template>
