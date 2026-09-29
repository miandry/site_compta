<script setup lang="ts">
defineProps<{ label?: string }>()
</script>

<template>
  <div class="flex flex-col items-center justify-center gap-3 py-12 text-ink-500" role="status">
    <span
      class="h-9 w-9 animate-spin rounded-full border-2 border-brand-200 border-t-brand-600"
      aria-hidden="true"
    />
    <p v-if="label" class="text-sm">{{ label }}</p>
  </div>
</template>
