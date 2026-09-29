<script setup lang="ts">
import { computed, reactive, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import CellValue from '@/components/entity/CellValue.vue'
import EntityForm from '@/components/entity/EntityForm.vue'
import MobileList from '@/components/entity/MobileList.vue'
import AppIcon from '@/components/ui/AppIcon.vue'
import BaseModal from '@/components/ui/BaseModal.vue'
import BasePagination from '@/components/ui/BasePagination.vue'
import BaseSpinner from '@/components/ui/BaseSpinner.vue'
import MobileFab from '@/components/ui/MobileFab.vue'
import { useDisplay } from '@/composables/useDisplay'
import type { BundleName, FieldDef, FormValues, LookupItem, Vocabulary } from '@/schema'
import { BUNDLES, fieldDef } from '@/schema'
import type { EntityRecord } from '@/services/entities'
import { listEntities, removeEntity, saveEntity } from '@/services/entities'
import { useLookupsStore } from '@/stores/lookups'
import { useTermsStore } from '@/stores/terms'
import { useUiStore } from '@/stores/ui'
import { extractErrorMessage, formatMoney } from '@/utils/format'

const props = defineProps<{ bundle: BundleName }>()

const PAGE_SIZE = 10

const route = useRoute()
const router = useRouter()
const ui = useUiStore()
const terms = useTermsStore()
const lookups = useLookupsStore()

const def = computed(() => BUNDLES[props.bundle])
const columns = computed(() => def.value.columns.map((key) => fieldDef(def.value, key)))
const filterFields = computed(() => def.value.filters.map((key) => fieldDef(def.value, key)))
const moneyColumn = computed(() => [...columns.value].reverse().find((f) => f.kind === 'money'))
const { sign } = useDisplay()

const items = ref<EntityRecord[]>([])
const total = ref(0)
const page = ref(1)
const loading = ref(true)
const error = ref('')
const search = ref('')
const filters = reactive<Record<string, string>>({})
const dateFrom = ref('')
const dateTo = ref('')
const filtersOpen = ref(false)
const activeFilterCount = computed(
  () => Object.values(filters).filter(Boolean).length + (dateFrom.value || dateTo.value ? 1 : 0),
)

const modalOpen = ref(false)
const editing = ref<EntityRecord | null>(null)
const submitting = ref(false)

const totalPages = computed(() => Math.max(1, Math.ceil(total.value / PAGE_SIZE)))
/** Total de la page ; net (entrées − sorties) quand le bundle a un sens. */
const pageSum = computed(() => {
  const key = moneyColumn.value?.key
  if (!key) return 0
  return items.value.reduce((sum, r) => sum + (sign(def.value, r) || 1) * (Number(r.values[key]) || 0), 0)
})
const pageSumTone = computed(() =>
  !def.value.directionKey ? 'text-neu-dark' : pageSum.value < 0 ? 'text-rose-600' : 'text-emerald-700',
)
const pageSumLabel = computed(() => (def.value.directionKey ? 'Solde (page)' : 'Total (page)'))

function filterChoices(field: FieldDef) {
  if (field.kind === 'options') {
    return Object.entries(field.options ?? {}).map(([value, label]) => ({ value, label }))
  }
  if (field.kind === 'term' && field.vocabulary) {
    return terms.byVocabulary[field.vocabulary].map((t) => ({ value: t.id, label: t.name }))
  }
  if (field.kind === 'node' && field.target) {
    return ((lookups.items as Record<string, LookupItem[]>)[field.target] ?? []).map((i) => ({
      value: i.id,
      label: i.label,
    }))
  }
  return []
}

async function loadDependencies() {
  const vocabularies = new Set<Vocabulary>()
  const targets = new Set<BundleName>()
  for (const field of def.value.fields) {
    if (field.vocabulary) vocabularies.add(field.vocabulary)
    if (field.target) targets.add(field.target)
  }
  await Promise.all([...[...vocabularies].map((v) => terms.load(v)), ...[...targets].map((t) => lookups.load(t))])
}

function query(pageIndex: number, limit = PAGE_SIZE) {
  return listEntities(props.bundle, {
    page: pageIndex,
    limit,
    search: search.value.trim() || undefined,
    filters: { ...filters },
    dateRange: def.value.dateFilterKey
      ? { key: def.value.dateFilterKey, from: dateFrom.value, to: dateTo.value }
      : undefined,
  })
}

/** `silent` : rafraîchit sans spinner (après un enregistrement) pour garder la liste et le scroll. */
let refreshSeq = 0
async function refresh(silent = false) {
  const seq = ++refreshSeq
  if (!silent) loading.value = true
  error.value = ''
  try {
    const result = await query(page.value)
    if (seq !== refreshSeq) return
    items.value = result.items
    total.value = result.total
    if (!silent) {
      mobileItems.value = page.value === 1 ? result.items : []
      mobilePage.value = page.value === 1 ? 1 : 0
    }
  } catch (err) {
    if (seq === refreshSeq) error.value = extractErrorMessage(err)
  } finally {
    if (seq === refreshSeq) loading.value = false
  }
}

// Mobile : 10 éléments, puis « Charger plus » ajoute la page suivante à la liste.
const mobileItems = ref<EntityRecord[]>([])
const mobilePage = ref(0)
const loadingMore = ref(false)
const hasMore = computed(() => mobileItems.value.length < total.value)
const mobileSum = computed(() => {
  const key = moneyColumn.value?.key
  if (!key) return 0
  return mobileItems.value.reduce((sum, r) => sum + (sign(def.value, r) || 1) * (Number(r.values[key]) || 0), 0)
})
const mobileSumTone = computed(() =>
  !def.value.directionKey ? 'text-neu-dark' : mobileSum.value < 0 ? 'text-rose-600' : 'text-emerald-700',
)

/** Charge la page suivante (api_solutions `offset` / `pager`) et l'ajoute à la liste. */
async function loadMore() {
  if (loadingMore.value || loading.value || !hasMore.value) return
  loadingMore.value = true
  try {
    const next = mobilePage.value + 1
    const result = await query(next)
    const known = new Set(mobileItems.value.map((r) => r.id))
    mobileItems.value = [...mobileItems.value, ...result.items.filter((r) => !known.has(r.id))]
    mobilePage.value = next
    total.value = result.total
  } catch (err) {
    ui.notify('error', extractErrorMessage(err))
  } finally {
    loadingMore.value = false
  }
}

/** Recharge d'un coup toutes les pages déjà affichées sur mobile. */
async function reloadMobile() {
  if (!mobilePage.value) return
  const result = await query(1, PAGE_SIZE * mobilePage.value)
  mobileItems.value = result.items
  total.value = result.total
}

async function init() {
  items.value = []
  mobileItems.value = []
  mobilePage.value = 0
  page.value = 1
  search.value = ''
  dateFrom.value = ''
  dateTo.value = ''
  for (const key of Object.keys(filters)) delete filters[key]
  for (const key of def.value.filters) filters[key] = ''
  loading.value = true
  try {
    await loadDependencies()
  } catch (err) {
    ui.notify('error', extractErrorMessage(err))
  }
  await refresh()
}

watch(() => props.bundle, init, { immediate: true })

watch(
  () => route.query.nouveau,
  (value) => {
    if (!value) return
    openCreate()
    router.replace({ query: { ...route.query, nouveau: undefined } })
  },
  { immediate: true },
)

function applyFilters() {
  page.value = 1
  refresh()
}

function resetFilters() {
  search.value = ''
  dateFrom.value = ''
  dateTo.value = ''
  for (const key of Object.keys(filters)) filters[key] = ''
  applyFilters()
}

function goPage(next: number) {
  page.value = next
  refresh()
}

function openCreate() {
  editing.value = null
  modalOpen.value = true
}

const detailRouteName = computed(() => `${props.bundle}-detail`)
const hasDetail = computed(() => router.hasRoute(detailRouteName.value))

function openDetail(record: EntityRecord) {
  if (hasDetail.value) router.push({ name: detailRouteName.value, params: { id: record.id } })
}

function openEdit(record: EntityRecord) {
  editing.value = record
  modalOpen.value = true
}

async function afterChange() {
  lookups.invalidate(props.bundle)
  await Promise.all([refresh(true), reloadMobile(), lookups.load(props.bundle)])
}

async function onSubmit(values: FormValues) {
  submitting.value = true
  try {
    await saveEntity(props.bundle, values, lookups.deriveContext(), editing.value?.id)
    ui.notify('success', editing.value ? `${def.value.label} mis(e) à jour.` : `${def.value.label} créé(e).`)
    modalOpen.value = false
    await afterChange()
  } catch (err) {
    ui.notify('error', extractErrorMessage(err))
  } finally {
    submitting.value = false
  }
}

async function onDelete(record: EntityRecord) {
  if (!confirm(`Supprimer « ${record.title} » ?`)) return
  try {
    await removeEntity(props.bundle, record.id)
    ui.notify('success', 'Élément supprimé.')
    await afterChange()
  } catch (err) {
    ui.notify('error', extractErrorMessage(err))
  }
}
</script>

<template>
  <div class="space-y-5" :class="{ 'max-md:flex max-md:h-full max-md:flex-col': def.mobile }">
    <div class="flex flex-wrap items-center justify-between gap-3" :class="{ 'hidden md:flex': def.mobile }">
      <p class="max-w-2xl text-sm text-neu-muted">{{ def.description }}</p>
      <button type="button" class="btn-primary" @click="openCreate">{{ def.createLabel }}</button>
    </div>

    <div v-if="def.mobile" class="flex shrink-0 gap-2 md:hidden">
      <input
        v-model="search"
        type="search"
        class="input flex-1"
        :placeholder="`Rechercher — ${def.plural}`"
        @keyup.enter="applyFilters"
      />
      <button
        type="button"
        class="btn-secondary relative px-4"
        :class="{ 'neu-toggle-active': filtersOpen }"
        aria-label="Filtres"
        @click="filtersOpen = !filtersOpen"
      >
        ☰
        <span
          v-if="activeFilterCount"
          class="absolute -right-1 -top-1 flex h-5 w-5 items-center justify-center rounded-full bg-accent text-[0.65rem] text-white"
        >
          {{ activeFilterCount }}
        </span>
      </button>
    </div>

    <div class="card shrink-0 p-4" :class="{ 'hidden md:block': def.mobile && !filtersOpen }">
      <div class="grid gap-3 md:grid-cols-2 xl:grid-cols-4">
        <div :class="{ 'hidden md:block': def.mobile }">
          <label class="label" :for="`s-${bundle}`">Recherche</label>
          <input
            :id="`s-${bundle}`"
            v-model="search"
            class="input"
            placeholder="Libellé…"
            @keyup.enter="applyFilters"
          />
        </div>
        <div v-if="def.dateFilterKey" class="grid grid-cols-2 gap-2 md:col-span-2">
          <div>
            <label class="label" :for="`from-${bundle}`">Du</label>
            <input
              :id="`from-${bundle}`"
              v-model="dateFrom"
              type="date"
              class="input"
              :max="dateTo || undefined"
              @change="applyFilters"
            />
          </div>
          <div>
            <label class="label" :for="`to-${bundle}`">Au</label>
            <input
              :id="`to-${bundle}`"
              v-model="dateTo"
              type="date"
              class="input"
              :min="dateFrom || undefined"
              @change="applyFilters"
            />
          </div>
        </div>
        <div v-for="field in filterFields" :key="field.key">
          <label class="label" :for="`flt-${field.key}`">{{ field.label }}</label>
          <select :id="`flt-${field.key}`" v-model="filters[field.key]" class="input" @change="applyFilters">
            <option value="">Tous</option>
            <option v-for="choice in filterChoices(field)" :key="choice.value" :value="choice.value">
              {{ choice.label }}
            </option>
          </select>
        </div>
        <div class="flex items-end gap-2">
          <button type="button" class="btn-primary flex-1" @click="applyFilters">Filtrer</button>
          <button type="button" class="btn-secondary" @click="resetFilters">Réinit.</button>
        </div>
      </div>
    </div>

    <BaseSpinner v-if="loading" :label="`Chargement — ${def.plural}`" />

    <p v-else-if="error" class="rounded-pill neu-inset px-4 py-3 text-sm text-rose-600">{{ error }}</p>

    <div v-else-if="def.mobile" class="flex min-h-0 flex-1 flex-col md:hidden">
      <p class="mb-3 flex shrink-0 justify-between px-2 text-sm text-neu-muted">
        <span>{{ total }} élément(s)</span>
        <span v-if="mobileItems.length && moneyColumn" class="font-mono font-semibold" :class="mobileSumTone">
          {{ formatMoney(mobileSum) }}
        </span>
      </p>
      <div class="-mx-4 min-h-0 flex-1 overflow-y-auto overscroll-contain px-4 pb-2 pt-1">
      <MobileList
        :def="def"
        :mobile="def.mobile"
        :items="mobileItems"
        :page-size="PAGE_SIZE"
        :total="total"
        @view="openDetail"
        @edit="openEdit"
        @delete="onDelete"
      />
      <p v-if="!mobileItems.length" class="card px-4 py-12 text-center text-sm text-neu-muted">Aucun élément.</p>
      <div v-else class="flex flex-col items-center gap-3 pb-20 pt-4 text-xs text-neu-muted">
        <span class="font-semibold">{{ mobileItems.length }} / {{ total }}</span>
        <button
          v-if="hasMore"
          type="button"
          class="btn-secondary w-full"
          :disabled="loadingMore"
          @click="loadMore"
        >
          <span
            v-if="loadingMore"
            class="mr-2 inline-block h-4 w-4 animate-spin rounded-full border-2 border-ink-300 border-t-accent align-middle"
            aria-hidden="true"
          />
          {{ loadingMore ? 'Chargement…' : `Charger plus (${Math.min(PAGE_SIZE, total - mobileItems.length)})` }}
        </button>
        <span v-else>Fin de la liste</span>
      </div>
      </div>
    </div>

    <div v-if="!loading && !error" class="card p-3 sm:p-4" :class="{ 'hidden md:block': def.mobile }">
      <div class="flex flex-wrap items-center justify-between gap-2 px-2 pb-1">
        <p class="text-sm text-neu-muted">{{ total }} élément(s)</p>
        <p v-if="moneyColumn && items.length" class="text-sm text-neu-muted">
          {{ pageSumLabel }} :
          <span class="font-mono font-semibold" :class="pageSumTone">{{ formatMoney(pageSum) }}</span>
        </p>
      </div>

      <div class="overflow-x-auto">
        <table class="table-neu">
          <thead>
            <tr>
              <th v-for="col in columns" :key="col.key" :class="{ 'text-right': col.kind === 'money' }">
                {{ col.columnLabel ?? col.label }}
              </th>
              <th class="text-right">Actions</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="record in items" :key="record.id">
              <td v-for="col in columns" :key="col.key" :class="{ 'text-right': col.kind === 'money' }">
                <CellValue :field="col" :record="record" :def="def" />
              </td>
              <td class="whitespace-nowrap text-right">
                <div class="inline-flex gap-2">
                  <button
                    v-if="hasDetail"
                    type="button"
                    class="btn-icon"
                    title="Détails"
                    aria-label="Détails"
                    @click="openDetail(record)"
                  >
                    <AppIcon name="eye" :size="16" />
                  </button>
                  <button
                    type="button"
                    class="btn-icon"
                    title="Modifier"
                    aria-label="Modifier"
                    @click="openEdit(record)"
                  >
                    <AppIcon name="edit" :size="16" />
                  </button>
                  <button
                    type="button"
                    class="btn-icon text-rose-600"
                    title="Supprimer"
                    aria-label="Supprimer"
                    @click="onDelete(record)"
                  >
                    <AppIcon name="trash" :size="16" />
                  </button>
                </div>
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <p v-if="!items.length" class="px-4 py-12 text-center text-sm text-neu-muted">
        Aucun élément.
        <button type="button" class="ml-1 font-semibold text-accent hover:underline" @click="openCreate">
          En créer un
        </button>
      </p>

      <BasePagination :page="page" :total-pages="totalPages" :total="total" @change="goPage" />
    </div>

    <MobileFab v-if="def.mobile" :label="def.createLabel" @click="openCreate" />

    <BaseModal
      :open="modalOpen"
      :title="editing ? `Modifier — ${def.label}` : `Nouveau — ${def.label}`"
      @close="modalOpen = false"
    >
      <EntityForm
        :def="def"
        :initial="editing"
        :submitting="submitting"
        @submit="onSubmit"
        @cancel="modalOpen = false"
      />
    </BaseModal>
  </div>
</template>
