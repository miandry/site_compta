<script setup lang="ts">
import { computed, reactive, ref, watch } from 'vue'
import { RouterLink, useRouter } from 'vue-router'
import AppIcon from '@/components/ui/AppIcon.vue'
import BaseSpinner from '@/components/ui/BaseSpinner.vue'
import type { EntityRecord } from '@/services/entities'
import { listEntities, removeEntity } from '@/services/entities'
import { useLookupsStore } from '@/stores/lookups'
import { useTermsStore } from '@/stores/terms'
import { useUiStore } from '@/stores/ui'
import { lotBreakdown, parseAmountLine, splitLines } from '@/utils/bulkLines'
import { extractErrorMessage, formatDate, formatMoney } from '@/utils/format'

const PAGE_SIZE = 10

const router = useRouter()
const ui = useUiStore()
const terms = useTermsStore()
const lookups = useLookupsStore()

const items = ref<EntityRecord[]>([])
const total = ref(0)
const page = ref(1)
const loading = ref(true)
const loadingMore = ref(false)
const error = ref('')
const search = ref('')
const date = ref('')
const order = ref<'DESC' | 'ASC'>('DESC')
const openDetails = reactive<Record<string, boolean>>({})

const hasMore = computed(() => items.value.length < total.value)

function query(pageIndex: number) {
  return listEntities('operation_lot', {
    page: pageIndex,
    limit: PAGE_SIZE,
    search: search.value.trim() || undefined,
    dateRange: date.value ? { key: 'field_operation_date', from: date.value, to: date.value } : undefined,
    sortField: 'nid',
    sortOrder: order.value,
  })
}

let seq = 0
async function refresh() {
  const current = ++seq
  loading.value = true
  error.value = ''
  try {
    const result = await query(1)
    if (current !== seq) return
    items.value = result.items
    total.value = result.total
    page.value = 1
  } catch (err) {
    if (current === seq) error.value = extractErrorMessage(err)
  } finally {
    if (current === seq) loading.value = false
  }
}

async function loadMore() {
  if (loadingMore.value || !hasMore.value) return
  loadingMore.value = true
  try {
    const result = await query(page.value + 1)
    const known = new Set(items.value.map((r) => r.id))
    items.value = [...items.value, ...result.items.filter((r) => !known.has(r.id))]
    total.value = result.total
    page.value++
  } catch (err) {
    ui.notify('error', extractErrorMessage(err))
  } finally {
    loadingMore.value = false
  }
}

let searchTimer: ReturnType<typeof setTimeout> | undefined
watch(search, () => {
  clearTimeout(searchTimer)
  searchTimer = setTimeout(refresh, 350)
})
watch([date, order], refresh)

Promise.all([terms.load('category'), terms.load('caisse'), lookups.load('person')]).catch(() => {})
refresh()

const number = new Intl.NumberFormat('fr-FR', { maximumFractionDigits: 2 })

/** « 11433×665×6.63 = 50 407 525,35 #toky » ; ligne illisible telle quelle. */
function expressionLines(lot: EntityRecord) {
  return splitLines(String(lot.values.field_lot_lines || '')).map((raw) => {
    const line = parseAmountLine(raw)
    if (line.error) return { text: raw, error: true }
    const label = line.label ? ` #${line.label}` : ''
    return { text: `${line.amountPart} = ${number.format(line.exact)}${label}`, error: false }
  })
}

function personLabel(lot: EntityRecord) {
  return lot.labels.field_person || lookups.find('person', String(lot.values.field_person))?.label || lot.title
}

function subtitle(lot: EntityRecord) {
  const category = lot.labels.field_category || (lot.values.field_category ? terms.label('category', String(lot.values.field_category)) : '')
  const caisse = lot.labels.field_caisse || (lot.values.field_caisse ? terms.label('caisse', String(lot.values.field_caisse)) : '')
  return [category, caisse].filter(Boolean).join(' · ')
}

function operationCount(lot: EntityRecord) {
  const raw = String(lot.values.field_lot_operations || '').trim()
  if (raw.startsWith('[')) {
    try {
      const list = JSON.parse(raw)
      return Array.isArray(list) ? list.filter(Boolean).length : 0
    } catch {
      return 0
    }
  }
  return raw.split(',').filter(Boolean).length
}

function netOf(lot: EntityRecord) {
  return lotBreakdown(String(lot.values.field_lot_lines || '')).net
}

const signed = (n: number) => `${n < 0 ? '−' : ''}${formatMoney(Math.abs(n))}`

function charger(lot: EntityRecord) {
  router.push({ path: '/operations/saisie-multiple', query: { lot: lot.id } })
}

async function onDelete(lot: EntityRecord) {
  if (!confirm(`Supprimer la saisie Ref-${lot.id} de l'historique ?\nLes opérations déjà créées sont conservées.`)) return
  try {
    await removeEntity('operation_lot', lot.id)
    items.value = items.value.filter((r) => r.id !== lot.id)
    total.value = Math.max(0, total.value - 1)
    ui.notify('success', `Saisie Ref-${lot.id} supprimée.`)
  } catch (err) {
    ui.notify('error', extractErrorMessage(err))
  }
}
</script>

<template>
  <div class="space-y-4">
    <div class="flex flex-wrap items-center gap-2">
      <button
        type="button"
        class="btn-icon shrink-0"
        :title="order === 'DESC' ? 'Plus récentes d\'abord' : 'Plus anciennes d\'abord'"
        :aria-label="order === 'DESC' ? 'Trier : plus anciennes d\'abord' : 'Trier : plus récentes d\'abord'"
        data-testid="lot-sort"
        @click="order = order === 'DESC' ? 'ASC' : 'DESC'"
      >
        {{ order === 'DESC' ? '↓' : '↑' }}
      </button>
      <div class="relative min-w-[10rem] flex-1">
        <input
          v-model="search"
          type="search"
          class="input py-2 pr-9"
          placeholder="Recherche par client"
          data-testid="lot-search"
        />
        <button
          v-if="search"
          type="button"
          class="absolute right-3 top-1/2 -translate-y-1/2 text-neu-muted"
          aria-label="Effacer"
          @click="search = ''"
        >
          ✕
        </button>
      </div>
      <input v-model="date" type="date" class="input w-auto flex-none py-2" aria-label="Date" data-testid="lot-date" />
      <RouterLink to="/operations/saisie-multiple" class="btn-primary hidden sm:inline-flex">+ Saisie multiple</RouterLink>
    </div>

    <BaseSpinner v-if="loading" label="Chargement des saisies" />
    <p v-else-if="error" class="rounded-pill neu-inset px-4 py-3 text-sm text-rose-600">{{ error }}</p>
    <p v-else-if="!items.length" class="card px-4 py-12 text-center text-sm text-neu-muted">
      Aucune saisie multiple.
      <RouterLink to="/operations/saisie-multiple" class="ml-1 font-semibold text-accent hover:underline">En créer une</RouterLink>
    </p>

    <template v-else>
      <p class="px-2 text-sm text-neu-muted">{{ total }} saisie(s)</p>

      <article v-for="lot in items" :key="lot.id" class="card space-y-3 p-4" data-testid="lot-card">
        <header class="flex items-start justify-between gap-3">
          <div class="min-w-0">
            <h2 class="truncate font-semibold text-neu-dark">{{ personLabel(lot) }}</h2>
            <p class="font-semibold text-emerald-600">Ref-{{ lot.id }}</p>
            <p class="text-sm text-neu-muted">
              {{ formatDate(String(lot.values.field_operation_date || '')) }}
              <template v-if="subtitle(lot)"> · {{ subtitle(lot) }}</template>
            </p>
          </div>
          <button
            type="button"
            class="shrink-0 p-1 text-rose-500 hover:text-rose-700"
            title="Supprimer"
            aria-label="Supprimer"
            @click="onDelete(lot)"
          >
            <AppIcon name="trash" :size="16" />
          </button>
        </header>

        <div>
          <p class="mb-1 text-sm text-neu-muted">Expression :</p>
          <pre
            class="neu-inset max-h-36 overflow-y-auto whitespace-pre-wrap break-words rounded-xl px-3 py-2 font-mono text-[13px] leading-relaxed text-neu-dark"
            data-testid="lot-lines"
          ><template v-for="(line, i) in expressionLines(lot)" :key="i"><span :class="{ 'text-rose-600': line.error }">{{ line.text }}</span>
</template></pre>
        </div>

        <div v-if="openDetails[lot.id]" class="space-y-1 border-t border-neu-muted/20 pt-2 text-sm" data-testid="lot-details">
          <p v-for="[label, value] in lotBreakdown(String(lot.values.field_lot_lines || '')).byLabel" :key="label" class="flex justify-between gap-3">
            <span class="truncate text-neu-muted">{{ label }} :</span>
            <span class="shrink-0 font-mono font-semibold" :class="value < 0 ? 'text-rose-600' : 'text-neu-dark'">{{ signed(value) }}</span>
          </p>
          <p class="flex justify-between gap-3">
            <span class="text-neu-muted">Total positif :</span>
            <span class="font-mono font-semibold text-emerald-700">{{ formatMoney(lotBreakdown(String(lot.values.field_lot_lines || '')).positive) }}</span>
          </p>
          <p class="flex justify-between gap-3">
            <span class="text-neu-muted">Total négatif :</span>
            <span class="font-mono font-semibold text-rose-600">{{ signed(lotBreakdown(String(lot.values.field_lot_lines || '')).negative) }}</span>
          </p>
          <p class="flex justify-between gap-3">
            <span class="text-neu-muted">Opérations créées :</span>
            <span class="font-semibold text-neu-dark">{{ operationCount(lot) }}</span>
          </p>
        </div>

        <p class="flex items-baseline justify-between gap-3">
          <span class="text-sm text-neu-muted">Total :</span>
          <span class="font-mono text-lg font-bold" :class="netOf(lot) < 0 ? 'text-rose-600' : 'text-emerald-600'" data-testid="lot-total">
            {{ signed(netOf(lot)) }}
          </span>
        </p>

        <div class="flex gap-2">
          <button type="button" class="btn-primary flex-1 py-2" data-testid="lot-load" @click="charger(lot)">
            <AppIcon name="edit" :size="16" /> Charger
          </button>
          <button
            type="button"
            class="btn-secondary py-2"
            :class="{ 'neu-toggle-active': openDetails[lot.id] }"
            data-testid="lot-details-btn"
            @click="openDetails[lot.id] = !openDetails[lot.id]"
          >
            ⓘ Détails
          </button>
        </div>
      </article>

      <div class="flex flex-col items-center gap-2 pb-20 pt-2 text-xs text-neu-muted lg:pb-0">
        <span class="font-semibold">{{ items.length }} / {{ total }}</span>
        <button v-if="hasMore" type="button" class="btn-secondary w-full" :disabled="loadingMore" @click="loadMore">
          {{ loadingMore ? 'Chargement…' : 'Charger plus' }}
        </button>
      </div>
    </template>
  </div>
</template>
