<script setup lang="ts">
import AppIcon from '@/components/ui/AppIcon.vue'
import type { IconName } from '@/components/ui/AppIcon.vue'

export interface SheetAction {
  id: string
  label: string
  icon: IconName
  danger?: boolean
}

defineProps<{ open: boolean; actions: SheetAction[] }>()

const emit = defineEmits<{
  close: []
  action: [id: string]
}>()
</script>

<template>
  <Teleport to="body">
    <Transition
      enter-active-class="transition duration-200"
      leave-active-class="transition duration-150"
      enter-from-class="opacity-0"
      leave-to-class="opacity-0"
    >
      <div v-if="open" class="fixed inset-0 z-50 bg-neu-dark/30 md:hidden" @click="emit('close')" />
    </Transition>
    <Transition
      enter-active-class="transition duration-200 ease-out"
      leave-active-class="transition duration-150 ease-in"
      enter-from-class="translate-y-full"
      leave-to-class="translate-y-full"
    >
      <div
        v-if="open"
        class="fixed inset-x-0 bottom-0 z-50 rounded-t-neu-xl bg-neu-bg px-4 pb-6 pt-3 shadow-neu-out md:hidden"
        role="dialog"
        aria-modal="true"
      >
        <div class="relative mb-3 flex h-8 items-center justify-center">
          <span class="h-1.5 w-10 rounded-pill bg-ink-300" />
          <button type="button" class="btn-icon absolute right-0 h-8 w-8" aria-label="Fermer" @click="emit('close')">
            <AppIcon name="close" :size="15" />
          </button>
        </div>
        <div class="mb-4 px-2">
          <slot />
        </div>
        <div class="flex items-start justify-around px-2">
          <button
            v-for="action in actions"
            :key="action.id"
            type="button"
            class="group flex flex-col items-center gap-1.5 text-xs font-semibold"
            :class="action.danger ? 'text-rose-600' : 'text-neu-dark'"
            @click="emit('action', action.id)"
          >
            <span class="btn-icon h-12 w-12 group-active:shadow-neu-in">
              <AppIcon :name="action.icon" :size="20" />
            </span>
            {{ action.label }}
          </button>
        </div>
      </div>
    </Transition>
  </Teleport>
</template>
