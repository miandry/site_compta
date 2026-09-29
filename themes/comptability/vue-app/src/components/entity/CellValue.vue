<script setup lang="ts">
import { computed } from 'vue'
import { useDisplay } from '@/composables/useDisplay'
import type { BundleDef, FieldDef } from '@/schema'
import { isDebitLabel } from '@/schema'
import type { EntityRecord } from '@/services/entities'

const props = defineProps<{
  field: FieldDef
  record: EntityRecord
  /** Bundle du record : active le signe entrée / sortie des montants. */
  def?: BundleDef
}>()

const { text: display, signedMoney } = useDisplay()

const value = computed(() => props.record.values[props.field.key])
const text = computed(() => display(props.field, props.record) || '—')
const isDirection = computed(() => props.def?.directionKey === props.field.key && Boolean(value.value))
const money = computed(() =>
  props.def ? signedMoney(props.def, props.record, Number(value.value) || 0) : { text: text.value, tone: 'text-neu-dark' },
)
const thumbnail = computed(() => props.record.files[props.field.key]?.[0]?.url ?? '')
</script>

<template>
  <span v-if="field.kind === 'boolean'" class="badge" :class="value ? 'badge-success' : 'badge-muted'">
    {{ value ? 'Actif' : 'Inactif' }}
  </span>
  <span v-else-if="isDirection" class="badge" :class="isDebitLabel(text) ? 'badge-accent' : 'badge-success'">
    {{ text }}
  </span>
  <span v-else-if="field.kind === 'money' && value !== ''" class="whitespace-nowrap font-mono font-semibold" :class="money.tone">
    {{ money.text }}
  </span>
  <a v-else-if="field.kind === 'image' && thumbnail" :href="thumbnail" target="_blank" rel="noopener" class="inline-block">
    <img :src="thumbnail" alt="Justificatif" class="h-10 w-10 rounded-neu object-cover shadow-neu-out-sm" />
  </a>
  <a
    v-else-if="field.kind === 'file' && value"
    :href="String(value)"
    target="_blank"
    rel="noopener"
    class="font-semibold text-accent hover:underline"
  >
    PDF
  </a>
  <span v-else :class="{ 'font-semibold text-neu-dark': field.key === 'title', 'line-clamp-2': field.kind === 'textarea' }">
    {{ text }}
  </span>
</template>
