<script setup lang="ts">
export type IconName = 'eye' | 'edit' | 'trash' | 'close' | 'back' | 'plus'

withDefaults(defineProps<{ name: IconName; size?: number }>(), { size: 18 })

const PATHS: Record<IconName, string[]> = {
  eye: ['M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7S2 12 2 12Z', 'M12 15a3 3 0 1 0 0-6 3 3 0 0 0 0 6Z'],
  edit: ['M12 20h9', 'M16.5 3.5a2.12 2.12 0 0 1 3 3L7 19l-4 1 1-4Z'],
  trash: ['M3 6h18', 'M8 6V4h8v2', 'M19 6l-1 14H6L5 6', 'M10 11v6', 'M14 11v6'],
  close: ['M18 6 6 18', 'M6 6l12 12'],
  back: ['M19 12H5', 'M12 19l-7-7 7-7'],
  plus: ['M12 5v14', 'M5 12h14'],
}
</script>

<template>
  <svg
    :width="size"
    :height="size"
    viewBox="0 0 24 24"
    fill="none"
    stroke="currentColor"
    stroke-width="2"
    stroke-linecap="round"
    stroke-linejoin="round"
    aria-hidden="true"
  >
    <path v-for="d in PATHS[name]" :key="d" :d="d" />
  </svg>
</template>
