<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { useRouter } from 'vue-router'
import CellValue from '@/components/entity/CellValue.vue'
import EntityForm from '@/components/entity/EntityForm.vue'
import AppIcon from '@/components/ui/AppIcon.vue'
import BaseModal from '@/components/ui/BaseModal.vue'
import BaseSpinner from '@/components/ui/BaseSpinner.vue'
import { useDisplay } from '@/composables/useDisplay'
import type { BundleName, FormValues, Vocabulary } from '@/schema'
import { BUNDLES, fieldDef } from '@/schema'
import type { EntityRecord } from '@/services/entities'
import { getEntity, listEntities, removeEntity, saveEntity } from '@/services/entities'
import type { RevisionEntry } from '@/services/history'
import { fetchHistory } from '@/services/history'
import { useLookupsStore } from '@/stores/lookups'
import { useTermsStore } from '@/stores/terms'
import { useUiStore } from '@/stores/ui'
import { extractErrorMessage, formatDate, formatMoney } from '@/utils/format'

const props = defineProps<{ bundle: BundleName; id: string; listPath: string }>()

const router = useRouter()
const ui = useUiStore()
const terms = useTermsStore()
const lookups = useLookupsStore()
const { text, sign, signedMoney } = useDisplay()

const def = computed(() => BUNDLES[props.bundle])
const relatedDef = computed(() => (def.value.related ? BUNDLES[def.value.related.bundle] : null))
const record = ref<EntityRecord | null>(null)
const related = ref<EntityRecord[]>([])
const history = ref<RevisionEntry[]>([])
const loading = ref(true)
const error = ref('')
const editOpen = ref(false)
const submitting = ref(false)

const amountKey = computed(() => def.value.mobile?.amountKey ?? def.value.fields.find((f) => f.kind === 'money')?.key)
const dateKey = computed(() => def.value.mobile?.dateKey)
const titleKey = computed(() => def.value.mobile?.titleKeys[0])

const heading = computed(() => {
  if (!record.value) return def.value.label
  for (const key of def.value.mobile?.titleKeys ?? ['title']) {
    const value = key === 'title' ? record.value.title : text(fieldDef(def.value, key), record.value)
    if (value) return value
  }
  return record.value.title || def.value.label
})

const hero = computed(() =>
  record.value && amountKey.value
    ? signedMoney(def.value, record.value, Number(record.value.values[amountKey.value]) || 0)
    : null,
)

const details = computed(() =>
  def.value.fields.filter((f) => !f.virtual && f.key !== amountKey.value && f.key !== titleKey.value && f.kind !== 'image'),
)
const images = computed(() =>
  def.value.fields
    .filter((f) => f.kind === 'image')
    .flatMap((f) => (record.value?.files[f.key] ?? []).map((file) => ({ ...file, label: f.label }))),
)

const relatedAmountKey = computed(() => relatedDef.value?.mobile?.amountKey)
const relatedBalance = computed(() => {
  const rd = relatedDef.value
  const key = relatedAmountKey.value
  if (!rd || !key) return 0
  return related.value.reduce((sum, r) => sum + (sign(rd, r) || 1) * (Number(r.values[key]) || 0), 0)
})

async function loadDependencies() {
  const vocabularies = new Set<Vocabulary>()
  const targets = new Set<BundleName>()
  for (const d of [def.value, relatedDef.value]) {
    for (const field of d?.fields ?? []) {
      if (field.vocabulary) vocabularies.add(field.vocabulary)
      if (field.target) targets.add(field.target)
    }
  }
  await Promise.all([...[...vocabularies].map((v) => terms.load(v)), ...[...targets].map((t) => lookups.load(t))])
}

async function loadRelated() {
  const rel = def.value.related
  if (!rel) {
    related.value = []
    return
  }
  const { items } = await listEntities(rel.bundle, { limit: 'all', filters: { [rel.key]: props.id } })
  related.value = items
}

async function loadHistory() {
  history.value = def.value.history ? await fetchHistory(props.bundle, props.id).catch(() => []) : []
}

function formatDateTime(value: string) {
  return new Intl.DateTimeFormat('fr-FR', { dateStyle: 'medium', timeStyle: 'short' }).format(new Date(value))
}

async function load() {
  loading.value = true
  error.value = ''
  try {
    const [found] = await Promise.all([getEntity(props.bundle, props.id), loadDependencies(), loadRelated(), loadHistory()])
    record.value = found
    if (!found) error.value = `${def.value.label} introuvable.`
  } catch (err) {
    error.value = extractErrorMessage(err)
  } finally {
    loading.value = false
  }
}

watch(() => [props.bundle, props.id], load, { immediate: true })

function relatedTitle(r: EntityRecord) {
  const rd = relatedDef.value!
  for (const key of rd.mobile?.titleKeys ?? ['title']) {
    const value = key === 'title' ? r.title : text(fieldDef(rd, key), r)
    if (value) return value
  }
  return r.title
}

function relatedSubtitle(r: EntityRecord) {
  const rd = relatedDef.value!
  const keys = [rd.mobile?.dateKey, ...(rd.mobile?.subtitleKeys ?? [])].filter(
    (k): k is string => Boolean(k) && k !== def.value.related?.key,
  )
  return keys
    .map((k) => text(fieldDef(rd, k), r))
    .filter(Boolean)
    .join(' · ')
}

function relatedAmount(r: EntityRecord) {
  return signedMoney(relatedDef.value!, r, Number(r.values[relatedAmountKey.value!]) || 0)
}

function openRelated(r: EntityRecord) {
  const name = `${def.value.related!.bundle}-detail`
  if (router.hasRoute(name)) router.push({ name, params: { id: r.id } })
}

async function onSubmit(values: FormValues) {
  if (!record.value) return
  submitting.value = true
  try {
    await saveEntity(props.bundle, values, lookups.deriveContext(), record.value.id)
    ui.notify('success', `${def.value.label} mis(e) à jour.`)
    editOpen.value = false
    lookups.invalidate(props.bundle)
    await load()
  } catch (err) {
    ui.notify('error', extractErrorMessage(err))
  } finally {
    submitting.value = false
  }
}

async function onDelete() {
  if (!record.value || !confirm(`Supprimer « ${record.value.title} » ?`)) return
  try {
    await removeEntity(props.bundle, record.value.id)
    lookups.invalidate(props.bundle)
    ui.notify('success', 'Élément supprimé.')
    router.push(props.listPath)
  } catch (err) {
    ui.notify('error', extractErrorMessage(err))
  }
}
</script>

<template>
  <div class="mx-auto max-w-2xl space-y-5">
    <div class="flex items-center justify-between">
      <button type="button" class="btn-icon h-10 w-10" aria-label="Retour" @click="router.push(listPath)">
        <AppIcon name="back" />
      </button>
      <div v-if="record" class="flex gap-3">
        <button type="button" class="btn-icon h-10 w-10" title="Modifier" aria-label="Modifier" @click="editOpen = true">
          <AppIcon name="edit" />
        </button>
        <button
          type="button"
          class="btn-icon h-10 w-10 text-rose-600"
          title="Supprimer"
          aria-label="Supprimer"
          @click="onDelete"
        >
          <AppIcon name="trash" />
        </button>
      </div>
    </div>

    <BaseSpinner v-if="loading" :label="`Chargement — ${def.label}`" />

    <p v-else-if="error" class="rounded-pill neu-inset px-4 py-3 text-sm text-rose-600">{{ error }}</p>

    <template v-else-if="record">
      <section class="card px-6 py-8 text-center">
        <p class="text-xs font-bold uppercase tracking-[0.18em] text-neu-muted">{{ def.label }}</p>
        <p v-if="hero" class="mt-3 font-mono text-3xl font-bold" :class="hero.tone">{{ hero.text }}</p>
        <p class="mt-2 whitespace-pre-line font-semibold text-neu-dark" :class="hero ? '' : 'mt-3 text-xl'">{{ heading }}</p>
        <p v-if="dateKey && record.values[dateKey]" class="mt-1 text-sm text-neu-muted">
          {{ formatDate(String(record.values[dateKey])) }}
        </p>
      </section>

      <section class="card divide-y divide-ink-100/70 overflow-hidden p-0">
        <div v-for="field in details" :key="field.key" class="flex items-center justify-between gap-4 px-5 py-4">
          <span class="shrink-0 text-sm text-neu-muted">{{ field.columnLabel ?? field.label }}</span>
          <span class="min-w-0 truncate text-right text-sm font-semibold text-neu-dark">
            <CellValue :field="field" :record="record" :def="def" />
          </span>
        </div>
        <div v-if="record.created" class="flex items-center justify-between gap-4 px-5 py-4">
          <span class="text-sm text-neu-muted">Créé le</span>
          <span class="text-sm font-semibold text-neu-dark">{{ formatDate(record.created) }}</span>
        </div>
      </section>

      <section v-for="image in images" :key="image.id" class="card p-4">
        <p class="mb-3 px-1 text-sm text-neu-muted">{{ image.label }}</p>
        <a :href="image.url" target="_blank" rel="noopener">
          <img :src="image.url" :alt="image.label" class="max-h-96 w-full rounded-neu object-contain" />
        </a>
      </section>

      <section v-if="def.related && relatedDef" class="space-y-3">
        <div class="flex items-baseline justify-between px-2">
          <h2 class="text-sm font-bold text-neu-dark">{{ def.related.label }} ({{ related.length }})</h2>
          <span
            v-if="related.length && relatedAmountKey"
            class="font-mono text-sm font-semibold"
            :class="relatedBalance < 0 ? 'text-rose-600' : 'text-emerald-700'"
          >
            {{ formatMoney(relatedBalance) }}
          </span>
        </div>
        <ul v-if="related.length" class="card divide-y divide-ink-100/70 overflow-hidden p-0">
          <li v-for="r in related" :key="r.id">
            <button
              type="button"
              class="flex w-full items-center gap-3 px-4 py-3.5 text-left transition active:bg-ink-100"
              @click="openRelated(r)"
            >
              <span class="min-w-0 flex-1">
                <span class="block truncate text-sm font-semibold text-neu-dark">{{ relatedTitle(r) }}</span>
                <span class="block truncate text-xs text-neu-muted">{{ relatedSubtitle(r) || '—' }}</span>
              </span>
              <span v-if="relatedAmountKey" class="shrink-0 font-mono text-sm font-semibold" :class="relatedAmount(r).tone">
                {{ relatedAmount(r).text }}
              </span>
            </button>
          </li>
        </ul>
        <p v-else class="card px-4 py-8 text-center text-sm text-neu-muted">Aucune opération.</p>
      </section>

      <section v-if="def.history" class="space-y-3">
        <h2 class="px-2 text-sm font-bold text-neu-dark">Historique ({{ history.length }})</h2>
        <ol v-if="history.length" class="card divide-y divide-ink-100/70 overflow-hidden p-0">
          <li v-for="(rev, index) in history" :key="rev.vid" class="flex gap-3 px-4 py-3.5">
            <span
              class="mt-1.5 h-2.5 w-2.5 shrink-0 rounded-full"
              :class="index === 0 ? 'bg-accent' : 'bg-ink-300'"
              aria-hidden="true"
            />
            <span class="min-w-0 flex-1">
              <span class="block text-sm font-semibold text-neu-dark">{{ rev.message || 'Modification' }}</span>
              <span class="block text-xs text-neu-muted">{{ formatDateTime(rev.date) }} · {{ rev.user }}</span>
            </span>
            <span v-if="rev.amount !== null" class="shrink-0 font-mono text-xs text-neu-muted">
              {{ formatMoney(rev.amount) }}
            </span>
          </li>
        </ol>
        <p v-else class="card px-4 py-8 text-center text-sm text-neu-muted">Aucune révision.</p>
      </section>
    </template>

    <BaseModal :open="editOpen" :title="`Modifier — ${def.label}`" @close="editOpen = false">
      <EntityForm :def="def" :initial="record" :submitting="submitting" @submit="onSubmit" @cancel="editOpen = false" />
    </BaseModal>
  </div>
</template>
