<script setup lang="ts">
defineProps<{ label: string }>()
defineEmits<{ click: [] }>()
</script>

<template>
  <button
    type="button"
    class="fixed bottom-6 right-5 z-30 flex h-14 w-14 items-center justify-center rounded-2xl bg-gradient-to-br from-accent-soft to-accent text-3xl font-light text-white shadow-neu-accent active:scale-95 md:hidden"
    :aria-label="label"
    @click="$emit('click')"
  >
    +
  </button>
</template>
