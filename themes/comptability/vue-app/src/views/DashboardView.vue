<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { RouterLink, useRouter } from 'vue-router'
import BaseSpinner from '@/components/ui/BaseSpinner.vue'
import { useDisplay } from '@/composables/useDisplay'
import type { Vocabulary } from '@/schema'
import { BUNDLES } from '@/schema'
import type { EntityRecord } from '@/services/entities'
import { listEntities } from '@/services/entities'
import { useAuthStore } from '@/stores/auth'
import { useLookupsStore } from '@/stores/lookups'
import { useTermsStore } from '@/stores/terms'
import { extractErrorMessage, formatDate, formatMoney } from '@/utils/format'

const def = BUNDLES.operation
const router = useRouter()
const auth = useAuthStore()
const lookups = useLookupsStore()
const terms = useTermsStore()
const { sign, signedMoney } = useDisplay()

const loading = ref(true)
const error = ref('')
const operations = ref<EntityRecord[]>([])

onMounted(async () => {
  try {
    const [ops] = await Promise.all([
      listEntities('operation', { limit: 'all' }),
      lookups.load('person'),
      ...(['caisse', 'category'] as Vocabulary[]).map((v) => terms.load(v)),
    ])
    operations.value = ops.items
  } catch (err) {
    error.value = extractErrorMessage(err)
  } finally {
    loading.value = false
  }
})

const amount = (r: EntityRecord) => Number(r.values.field_amount) || 0
const credits = computed(() => operations.value.filter((r) => sign(def, r) > 0))
const debits = computed(() => operations.value.filter((r) => sign(def, r) < 0))
const sum = (rows: EntityRecord[]) => rows.reduce((s, r) => s + amount(r), 0)
const balance = computed(() => sum(credits.value) - sum(debits.value))

const stats = computed(() => [
  {
    label: 'Solde',
    value: formatMoney(balance.value),
    tone: balance.value < 0 ? 'text-rose-600' : 'text-neu-dark',
    hint: 'Entrées − sorties',
  },
  { label: 'Entrées', value: formatMoney(sum(credits.value)), tone: 'text-emerald-700', hint: `${credits.value.length} entrée(s)` },
  { label: 'Sorties', value: formatMoney(sum(debits.value)), tone: 'text-rose-600', hint: `${debits.value.length} sortie(s)` },
  { label: 'Opérations', value: String(operations.value.length), tone: 'text-neu-dark', hint: `${lookups.items.person.length} personne(s)` },
])

const recent = computed(() => operations.value.slice(0, 6))

const byCaisse = computed(() => {
  const map = new Map<string, number>()
  for (const r of operations.value) {
    const id = String(r.values.field_caisse || '')
    map.set(id, (map.get(id) ?? 0) + (sign(def, r) || 1) * amount(r))
  }
  return [...map.entries()]
    .map(([id, total]) => ({ label: (id && terms.label('caisse', id)) || 'Sans caisse', total }))
    .sort((a, b) => b.total - a.total)
})

const debitsByCategory = computed(() => {
  const map = new Map<string, number>()
  for (const r of debits.value) {
    const id = String(r.values.field_category || '')
    map.set(id, (map.get(id) ?? 0) + amount(r))
  }
  const rows = [...map.entries()]
    .map(([id, total]) => ({ label: (id && terms.label('category', id)) || 'Sans catégorie', total }))
    .sort((a, b) => b.total - a.total)
  const max = Math.max(1, ...rows.map((r) => r.total))
  return rows.map((r) => ({ ...r, pct: Math.round((r.total / max) * 100) }))
})

const labelOf = (r: EntityRecord) => String(r.values.field_label || r.title)
const personOf = (r: EntityRecord) =>
  lookups.find('person', String(r.values.field_person))?.label || r.labels.field_person || '—'
</script>

<template>
  <div class="space-y-6">
    <BaseSpinner v-if="loading" label="Chargement du tableau de bord…" />

    <p v-else-if="error" class="rounded-pill neu-inset px-4 py-3 text-sm text-rose-600">{{ error }}</p>

    <template v-else>
      <section class="grid grid-cols-2 gap-4 xl:grid-cols-4">
        <article v-for="stat in stats" :key="stat.label" class="card relative overflow-hidden p-5">
          <div class="stat-bubble" />
          <p class="text-sm font-semibold text-neu-muted">{{ stat.label }}</p>
          <p class="mt-2 font-mono text-lg font-semibold sm:text-xl" :class="stat.tone">{{ stat.value }}</p>
          <p class="mt-1 text-xs text-neu-muted">{{ stat.hint }}</p>
        </article>
      </section>

      <div class="grid gap-6 xl:grid-cols-[1.2fr_0.8fr]">
        <section class="card p-5">
          <div class="mb-4 flex items-center justify-between gap-3">
            <h2 class="font-display text-lg font-semibold text-neu-dark">Dernières opérations</h2>
            <RouterLink to="/operations" class="text-sm font-semibold text-accent hover:underline">Voir tout</RouterLink>
          </div>
          <ul class="space-y-3">
            <li v-for="op in recent" :key="op.id">
              <button
                type="button"
                class="flex w-full items-center justify-between gap-3 rounded-neu px-4 py-3 text-left shadow-neu-in"
                @click="router.push({ name: 'operation-detail', params: { id: op.id } })"
              >
                <div class="min-w-0">
                  <p class="truncate font-semibold text-neu-dark">{{ labelOf(op) }}</p>
                  <p class="truncate text-xs text-neu-muted">
                    {{ formatDate(String(op.values.field_operation_date)) }} · {{ personOf(op) }}
                  </p>
                </div>
                <p class="shrink-0 font-mono text-sm font-semibold" :class="signedMoney(def, op, amount(op)).tone">
                  {{ signedMoney(def, op, amount(op)).text }}
                </p>
              </button>
            </li>
            <li v-if="!recent.length" class="py-8 text-center text-sm text-neu-muted">
              Aucune opération pour le moment.
              <RouterLink :to="{ path: '/operations', query: { nouveau: '1' } }" class="ml-1 text-accent hover:underline">
                En créer une
              </RouterLink>
            </li>
          </ul>
        </section>

        <div class="space-y-6">
          <section class="card p-5">
            <div class="mb-4 flex items-center justify-between gap-3">
              <h2 class="font-display text-lg font-semibold text-neu-dark">Solde par caisse</h2>
              <RouterLink v-if="auth.isAdmin" to="/parametres/caisse" class="text-sm font-semibold text-accent hover:underline">Gérer</RouterLink>
            </div>
            <ul class="space-y-3">
              <li
                v-for="row in byCaisse"
                :key="row.label"
                class="flex items-center justify-between gap-3 rounded-neu px-4 py-3 shadow-neu-out-sm"
              >
                <p class="truncate font-semibold text-neu-dark">{{ row.label }}</p>
                <p class="shrink-0 font-mono text-sm font-semibold" :class="row.total < 0 ? 'text-rose-600' : 'text-emerald-700'">
                  {{ formatMoney(row.total) }}
                </p>
              </li>
              <li v-if="!byCaisse.length" class="py-6 text-center text-sm text-neu-muted">Aucun mouvement.</li>
            </ul>
          </section>

          <section class="card p-5">
            <div class="mb-4 flex items-center justify-between gap-3">
              <h2 class="font-display text-lg font-semibold text-neu-dark">Sorties par catégorie</h2>
              <RouterLink to="/operations" class="text-sm font-semibold text-accent hover:underline">Détail</RouterLink>
            </div>
            <ul class="space-y-4">
              <li v-for="row in debitsByCategory" :key="row.label">
                <div class="mb-1.5 flex justify-between gap-3 text-sm">
                  <span class="font-semibold text-neu-dark">{{ row.label }}</span>
                  <span class="font-mono text-neu-muted">{{ formatMoney(row.total) }}</span>
                </div>
                <div class="h-2.5 rounded-pill shadow-neu-in">
                  <div class="h-2.5 rounded-pill bg-accent shadow-neu-accent" :style="{ width: `${row.pct}%` }" />
                </div>
              </li>
              <li v-if="!debitsByCategory.length" class="py-6 text-center text-sm text-neu-muted">Aucune sortie enregistrée.</li>
            </ul>
          </section>
        </div>
      </div>
    </template>
  </div>
</template>
