<script setup lang="ts">
defineProps<{
  page: number
  totalPages: number
  total?: number
}>()

const emit = defineEmits<{
  change: [page: number]
}>()
</script>

<template>
  <div v-if="totalPages > 1" class="flex flex-wrap items-center justify-between gap-3 pt-4">
    <p class="text-sm text-ink-500">
      Page {{ page }} / {{ totalPages }}
      <span v-if="total !== undefined"> · {{ total }} résultat(s)</span>
    </p>
    <div class="flex gap-2">
      <button
        type="button"
        class="btn-secondary"
        :disabled="page <= 1"
        @click="emit('change', page - 1)"
      >
        Précédent
      </button>
      <button
        type="button"
        class="btn-secondary"
        :disabled="page >= totalPages"
        @click="emit('change', page + 1)"
      >
        Suivant
      </button>
    </div>
  </div>
</template>
