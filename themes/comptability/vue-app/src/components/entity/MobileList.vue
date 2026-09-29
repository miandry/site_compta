<script setup lang="ts">
import { ref } from 'vue'
import ActionSheet from '@/components/ui/ActionSheet.vue'
import type { SheetAction } from '@/components/ui/ActionSheet.vue'
import { useDisplay } from '@/composables/useDisplay'
import type { BundleDef, MobileListDef } from '@/schema'
import { fieldDef } from '@/schema'
import type { EntityRecord } from '@/services/entities'
import { avatarFor } from '@/utils/format'

const props = defineProps<{
  def: BundleDef
  mobile: MobileListDef
  items: EntityRecord[]
  /** Taille de page : affiche un séparateur « Page n » au début de chaque page chargée. */
  pageSize?: number
  total?: number
}>()

function pageStart(index: number) {
  return Boolean(props.pageSize) && index > 0 && index % props.pageSize! === 0
}

function pageLabel(index: number) {
  const size = props.pageSize!
  const n = index / size + 1
  const last = Math.min(index + size, props.total ?? index + size)
  return `Page ${n} · ${index + 1}–${last}`
}

const emit = defineEmits<{
  view: [record: EntityRecord]
  edit: [record: EntityRecord]
  delete: [record: EntityRecord]
}>()

const sheetActions: SheetAction[] = [
  { id: 'view', label: 'Détails', icon: 'eye' },
  { id: 'edit', label: 'Modifier', icon: 'edit' },
  { id: 'delete', label: 'Supprimer', icon: 'trash', danger: true },
]

const { text, signedMoney } = useDisplay()
const selected = ref<EntityRecord | null>(null)

function valueOf(record: EntityRecord, key: string): string {
  return key === 'title' ? record.title : text(fieldDef(props.def, key), record)
}

function firstText(record: EntityRecord, keys: string[]): string {
  for (const key of keys) {
    const value = valueOf(record, key)
    if (value) return value
  }
  return ''
}

function subtitle(record: EntityRecord): string {
  return [props.mobile.dateKey, ...props.mobile.subtitleKeys]
    .map((key) => (key ? valueOf(record, key) : ''))
    .filter(Boolean)
    .join(' · ')
}

function avatar(record: EntityRecord) {
  return avatarFor(valueOf(record, props.mobile.avatarKey) || record.title)
}

function amount(record: EntityRecord) {
  const key = props.mobile.amountKey
  return key ? signedMoney(props.def, record, Number(record.values[key]) || 0) : null
}

function act(action: string) {
  const record = selected.value
  selected.value = null
  if (!record) return
  if (action === 'view') emit('view', record)
  else if (action === 'edit') emit('edit', record)
  else emit('delete', record)
}
</script>

<template>
  <div>
    <ul class="card divide-y divide-ink-100/70 overflow-hidden p-0">
      <li v-for="(record, index) in items" :key="record.id">
        <p
          v-if="pageStart(index)"
          class="bg-ink-100/60 px-4 py-1.5 text-center text-[0.7rem] font-bold uppercase tracking-[0.14em] text-neu-muted"
        >
          {{ pageLabel(index) }}
        </p>
        <button
          type="button"
          class="flex w-full items-center gap-3 px-4 py-3.5 text-left transition active:bg-ink-100"
          @click="selected = record"
        >
          <span
            class="flex h-11 w-11 shrink-0 items-center justify-center rounded-full text-sm font-bold"
            :class="avatar(record).tone"
          >
            {{ avatar(record).initials }}
          </span>
          <span class="min-w-0 flex-1">
            <span class="block truncate text-[0.95rem] font-semibold text-neu-dark">
              {{ firstText(record, mobile.titleKeys) || def.label }}
            </span>
            <span class="block truncate text-xs text-neu-muted">{{ subtitle(record) || '—' }}</span>
          </span>
          <span v-if="amount(record)" class="shrink-0 font-mono text-sm font-semibold" :class="amount(record)!.tone">
            {{ amount(record)!.text }}
          </span>
        </button>
      </li>
    </ul>

    <ActionSheet :open="Boolean(selected)" :actions="sheetActions" @close="selected = null" @action="act">
      <div v-if="selected" class="flex items-center gap-3">
        <span
          class="flex h-11 w-11 shrink-0 items-center justify-center rounded-full text-sm font-bold"
          :class="avatar(selected).tone"
        >
          {{ avatar(selected).initials }}
        </span>
        <div class="min-w-0 flex-1">
          <p class="truncate font-semibold text-neu-dark">{{ firstText(selected, mobile.titleKeys) || def.label }}</p>
          <p class="truncate text-xs text-neu-muted">{{ subtitle(selected) }}</p>
        </div>
        <p v-if="amount(selected)" class="font-mono text-base font-semibold" :class="amount(selected)!.tone">
          {{ amount(selected)!.text }}
        </p>
      </div>
    </ActionSheet>
  </div>
</template>
