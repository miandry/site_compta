<script setup lang="ts">
import { computed, reactive, ref, watch } from 'vue'
import { RouterLink, useRoute, useRouter } from 'vue-router'
import FieldInput from '@/components/entity/FieldInput.vue'
import BaseSpinner from '@/components/ui/BaseSpinner.vue'
import type { FormValue, FormValues } from '@/schema'
import { BUNDLES, fieldDef } from '@/schema'
import { duplicateMessage, getEntity, removeEntity, saveEntity } from '@/services/entities'
import { useLookupsStore } from '@/stores/lookups'
import { useTermsStore } from '@/stores/terms'
import { useUiStore } from '@/stores/ui'
import BaseModal from '@/components/ui/BaseModal.vue'
import { parseAmountLine } from '@/utils/bulkLines'
import { extractErrorMessage, formatMoney, todayInput } from '@/utils/format'

/** new / update / same : à faire ; created / updated / duplicate / error : résultat du dernier envoi. */
type LineStatus = 'new' | 'update' | 'same' | 'saving' | 'created' | 'updated' | 'duplicate' | 'error'

interface Line {
  raw: string
  /** Position parmi les lignes non vides : relie la ligne à son opération. */
  index: number
  number: number
  amount: number
  label: string
  mouvement: 'Entree' | 'Sortie'
  error: string
  /** Calcul saisi (« (1231+3344)/345 »), vide pour un simple nombre. */
  expression: string
  /** Résultat non entier, arrondi à l'ariary. */
  rounded: boolean
}

/** Valeurs d'une opération telles qu'enregistrées (stockées dans le lot, une par ligne). */
interface Snapshot {
  id: string
  /** Montant signé (négatif = sortie). */
  v: number
  l: string
  d: string
  p: string
  c: string
  k: string
}

const def = BUNDLES.operation
const SHARED = ['field_operation_date', 'field_person', 'field_category', 'field_caisse'] as const
const sharedFields = SHARED.map((key) => fieldDef(def, key))

const terms = useTermsStore()
const lookups = useLookupsStore()
const ui = useUiStore()

const shared = reactive<FormValues>({
  field_operation_date: todayInput(),
  field_person: '',
  field_category: '',
  field_caisse: '',
})
const text = ref('')
const loading = ref(true)
const saving = ref(false)
const formError = ref('')

/**
 * « -23616000 #she » => sortie de 23 616 000, libellé « she ».
 * « -4545+2343 #hto » => calculé : -2 202, donc sortie de 2 202.
 * Le signe du résultat donne le mouvement ; arrondi à l'ariary.
 */
function parseLine(raw: string, number: number, index: number): Line {
  const parsed = parseAmountLine(raw)
  return {
    raw,
    index,
    number,
    amount: Math.abs(parsed.value),
    label: parsed.label,
    mouvement: parsed.value < 0 ? 'Sortie' : 'Entree',
    error: parsed.error,
    expression: parsed.expression ? parsed.amountPart : '',
    rounded: parsed.rounded,
  }
}

const lines = computed<Line[]>(() =>
  text.value
    .split(/\r?\n/)
    .map((raw, i) => ({ raw: raw.trim(), number: i + 1 }))
    .filter((l) => l.raw !== '')
    .map((l, index) => parseLine(l.raw, l.number, index)),
)

const validLines = computed(() => lines.value.filter((l) => !l.error))
const invalidCount = computed(() => lines.value.length - validLines.value.length)

/** Opération liée à chaque ligne, par position (null = pas encore créée). */
const linked = ref<(Snapshot | null)[]>([])
/** Résultat du dernier envoi, par position ; effacé dès que la saisie change. */
const results = reactive<Record<number, { status: LineStatus; message: string }>>({})
const live = reactive<Record<number, boolean>>({})

const signedValue = (l: Line) => (l.mouvement === 'Sortie' ? -l.amount : l.amount)
const snapshotOf = (l: Line, id: string): Snapshot => ({
  id,
  v: signedValue(l),
  l: l.label,
  d: String(shared.field_operation_date || ''),
  p: String(shared.field_person || ''),
  c: String(shared.field_category || ''),
  k: String(shared.field_caisse || ''),
})
const sameAs = (l: Line, s: Snapshot) => {
  const now = snapshotOf(l, s.id)
  return now.v === s.v && now.l === s.l && now.d === s.d && now.p === s.p && now.c === s.c && now.k === s.k
}

/**
 * Ligne → opération : d'abord même place et même contenu, puis même contenu
 * ailleurs (ligne déplacée), puis même libellé (montant modifié), enfin même
 * place (libellé modifié). Le reste : lignes nouvelles / opérations retirées.
 */
const assignment = computed(() => {
  const pool = linked.value.filter((s): s is Snapshot => Boolean(s))
  const used = new Set<string>()
  const map: Record<number, Snapshot | null> = {}
  const sameContent = (l: Line, s: Snapshot) => s.v === signedValue(l) && s.l === l.label
  const take = (l: Line, s: Snapshot) => {
    map[l.index] = s
    used.add(s.id)
  }
  for (const l of validLines.value) {
    const s = linked.value[l.index]
    if (s && !used.has(s.id) && sameContent(l, s)) take(l, s)
  }
  for (const l of validLines.value) {
    if (l.index in map) continue
    const s = pool.find((p) => !used.has(p.id) && sameContent(l, p))
    if (s) take(l, s)
  }
  for (const l of validLines.value) {
    if (l.index in map) continue
    const s = pool.find((p) => !used.has(p.id) && p.l === l.label)
    if (s) take(l, s)
  }
  for (const l of validLines.value) {
    if (l.index in map) continue
    const s = linked.value[l.index]
    if (s && !used.has(s.id)) take(l, s)
    else map[l.index] = null
  }
  return { map, removed: pool.filter((p) => !used.has(p.id)) }
})

/** À faire pour chaque ligne : créer, modifier ou rien. */
function planOf(l: Line): 'new' | 'update' | 'same' {
  const op = assignment.value.map[l.index]
  if (!op) return 'new'
  return sameAs(l, op) ? 'same' : 'update'
}
const statusOf = (l: Line): LineStatus => (live[l.index] ? 'saving' : results[l.index]?.status ?? planOf(l))
const messageOf = (l: Line) => results[l.index]?.message ?? ''
const opIdOf = (l: Line) => assignment.value.map[l.index]?.id ?? ''

const toCreate = computed(() => validLines.value.filter((l) => planOf(l) === 'new'))
const toUpdate = computed(() => validLines.value.filter((l) => planOf(l) === 'update'))
/** Opérations dont la ligne a été retirée de la zone. */
const toRemove = computed(() => assignment.value.removed)
const changeCount = computed(() => toCreate.value.length + toUpdate.value.length + toRemove.value.length)
const totals = computed(() => {
  let entree = 0
  let sortie = 0
  for (const l of validLines.value) {
    if (l.mouvement === 'Sortie') sortie += l.amount
    else entree += l.amount
  }
  return { entree, sortie, net: entree - sortie }
})
const savedCount = computed(() => validLines.value.filter((l) => assignment.value.map[l.index]).length)

function clearResults() {
  for (const key of Object.keys(results)) delete results[Number(key)]
}

const route = useRoute()
const router = useRouter()

/** Lot en cours (chargé via « Charger » ou créé au premier enregistrement). */
const lotId = ref('')

async function loadLot(id: string) {
  const lot = await getEntity('operation_lot', id)
  if (!lot) {
    ui.notify('error', `Saisie Ref-${id} introuvable.`)
    return
  }
  lotId.value = lot.id
  for (const key of SHARED) {
    if (lot.values[key] !== '' && lot.values[key] != null) shared[key] = lot.values[key]
  }
  text.value = String(lot.values.field_lot_lines || '')
  clearResults()

  const stored = String(lot.values.field_lot_operations || '').trim()
  if (stored.startsWith('[')) {
    try {
      linked.value = (JSON.parse(stored) as (Snapshot | null)[]).map((s) => (s && s.id ? { ...s, id: String(s.id) } : null))
      return
    } catch {
      // Ancien format illisible : rapprochement ci-dessous.
    }
  }
  await matchLegacyOperations(stored.split(',').filter(Boolean))
}

/**
 * Lot enregistré avant le lien ligne ↔ opération (« 95,96,97 ») :
 * rapprochement par montant, mouvement et libellé.
 */
async function matchLegacyOperations(ids: string[]) {
  const ops = (await Promise.all(ids.map((id) => getEntity('operation', id).catch(() => null))))
    .filter((op): op is NonNullable<typeof op> => Boolean(op))
  const unmatched = [...ops]
  const result: (Snapshot | null)[] = lines.value.map(() => null)
  for (const line of lines.value) {
    if (line.error) continue
    const i = unmatched.findIndex(
      (op) =>
        Number(op.values.field_amount) === line.amount &&
        String(op.values.field_mouvement_argent) === line.mouvement &&
        (!line.label || op.title === line.label),
    )
    if (i < 0) continue
    const op = unmatched.splice(i, 1)[0]
    result[line.index] = {
      id: op.id,
      v: signedValue(line),
      l: line.label,
      d: String(op.values.field_operation_date || ''),
      p: String(op.values.field_person || ''),
      c: String(op.values.field_category || ''),
      k: String(op.values.field_caisse || ''),
    }
  }
  linked.value = result
}

async function init() {
  loading.value = true
  try {
    await Promise.all([terms.load('category'), terms.load('caisse'), lookups.load('person')])
    if (route.query.lot) await loadLot(String(route.query.lot))
  } catch (err) {
    ui.notify('error', extractErrorMessage(err))
  } finally {
    loading.value = false
  }
}
init()

/** Enregistre (ou met à jour) le lot : lignes, champs communs, total et lien ligne ↔ opération. */
async function saveLot() {
  const values: FormValues = {
    ...shared,
    field_lot_lines: text.value.trim(),
    field_lot_total: totals.value.net,
    field_lot_operations: JSON.stringify(linked.value),
  }
  const id = await saveEntity('operation_lot', values, lookups.deriveContext(), lotId.value || undefined)
  lotId.value = id
  if (route.query.lot !== id) router.replace({ query: { ...route.query, lot: id } })
  return id
}

watch(text, () => {
  formError.value = ''
  clearResults()
})

const tab = ref<'lines' | 'fields'>('lines')

/** Champs communs obligatoires sur cette page (la caisse aussi). */
const isMissing = (key: string) => (sharedFields.find((f) => f.key === key)?.required || key === 'field_caisse') && !shared[key]
const missingShared = computed(() => sharedFields.filter((f) => isMissing(f.key)).length)

function validate(): string {
  if (!validLines.value.length) {
    tab.value = 'lines'
    return 'Ajoutez au moins une ligne « montant #libellé ».'
  }
  if (invalidCount.value) {
    tab.value = 'lines'
    return `${invalidCount.value} ligne(s) illisible(s) : corrigez-les ou supprimez-les.`
  }
  const missing = sharedFields.find((f) => isMissing(f.key))
  if (missing) {
    tab.value = 'fields'
    return `Onglet 2 : renseignez « ${missing.label} ».`
  }
  return ''
}

const confirmOpen = ref(false)

/** Récapitulatif (même date, personne, catégorie, caisse) affiché avant l'envoi. */
const sharedSummary = computed(() => [
  { label: 'Date', value: String(shared.field_operation_date || '—') },
  { label: 'Personne', value: lookups.find('person', String(shared.field_person))?.label ?? '—' },
  { label: 'Catégorie', value: shared.field_category ? terms.label('category', String(shared.field_category)) : '—' },
  { label: 'Caisse', value: shared.field_caisse ? terms.label('caisse', String(shared.field_caisse)) : '—' },
])
/** Totaux après enregistrement (toutes les lignes de la saisie). */
const pendingTotals = totals

function askConfirm() {
  formError.value = validate()
  if (formError.value || saving.value) return
  if (!changeCount.value) {
    formError.value = 'Aucune modification à enregistrer.'
    return
  }
  confirmOpen.value = true
}

function operationValues(line: Line): FormValues {
  return {
    ...shared,
    field_mouvement_argent: line.mouvement,
    field_amount: line.amount,
    field_label: line.label,
    field_operation_type: '',
    field_method_payment: '',
    field_image_prof: [],
  }
}

async function saveAll() {
  confirmOpen.value = false
  formError.value = validate()
  if (formError.value || saving.value) return
  saving.value = true
  clearResults()
  const count = { created: 0, updated: 0, removed: 0, duplicates: 0, errors: 0 }
  const ctx = lookups.deriveContext()
  const plan = assignment.value
  const removed = [...toRemove.value]
  // Résultat aligné sur les lignes : ce qui sera stocké dans le lot.
  const next: (Snapshot | null)[] = lines.value.map((l) => plan.map[l.index] ?? null)

  // Suppressions d'abord : une ligne modifiée peut reprendre le montant d'une ligne retirée.
  const kept: Snapshot[] = []
  for (const op of removed) {
    try {
      await removeEntity('operation', op.id)
      count.removed++
    } catch (err) {
      kept.push(op)
      count.errors++
      ui.notify('error', `Opération ${op.id} non supprimée : ${extractErrorMessage(err)}`)
    }
  }

  // Une par une : la détection de doublons côté Drupal voit les lignes précédentes.
  for (const line of validLines.value) {
    if (planOf(line) === 'same') continue
    const op = next[line.index]
    live[line.index] = true
    try {
      // La modification met aussi à jour le titre (libellé) et les champs communs.
      const id = await saveEntity('operation', operationValues(line), ctx, op?.id)
      next[line.index] = snapshotOf(line, id)
      results[line.index] = { status: op ? 'updated' : 'created', message: '' }
      op ? count.updated++ : count.created++
    } catch (err) {
      const dup = duplicateMessage(err)
      results[line.index] = dup
        ? { status: 'duplicate', message: dup }
        : { status: 'error', message: extractErrorMessage(err) }
      dup ? count.duplicates++ : count.errors++
    } finally {
      delete live[line.index]
    }
  }

  // Opérations non supprimées : gardées en fin de liste, réessayées au prochain envoi.
  linked.value = [...next, ...kept]

  const parts: string[] = []
  if (count.created) parts.push(`${count.created} créée(s)`)
  if (count.updated) parts.push(`${count.updated} modifiée(s)`)
  if (count.removed) parts.push(`${count.removed} supprimée(s)`)
  if (count.duplicates) parts.push(`${count.duplicates} doublon(s) refusé(s)`)
  if (count.errors) parts.push(`${count.errors} erreur(s)`)
  if (!parts.length) parts.push('aucune opération modifiée')

  if (count.created || count.updated || count.removed || lotId.value) {
    try {
      parts.push(`saisie Ref-${await saveLot()} enregistrée`)
    } catch (err) {
      count.errors++
      parts.push(`historique non enregistré (${extractErrorMessage(err)})`)
    }
  }
  saving.value = false

  ui.notify(count.errors || count.duplicates ? 'error' : 'success', `Opérations : ${parts.join(', ')}.`)
}

function newLot() {
  lotId.value = ''
  linked.value = []
  text.value = ''
  clearResults()
  router.replace({ query: {} })
}

function onShared(key: string, value: FormValue) {
  shared[key] = value
  formError.value = ''
  clearResults()
}

const STATUS_LABEL: Record<LineStatus, string> = {
  new: 'Nouveau',
  update: 'À modifier',
  same: 'Enregistré',
  saving: 'Envoi…',
  created: 'Créé',
  updated: 'Modifié',
  duplicate: 'Doublon',
  error: 'Erreur',
}
const STATUS_CLASS: Record<LineStatus, string> = {
  new: 'badge-muted',
  update: 'badge-warning',
  same: 'badge-success',
  saving: 'badge-warning',
  created: 'badge-success',
  updated: 'badge-success',
  duplicate: 'badge-warning',
  error: 'badge-accent',
}
</script>

<template>
  <div class="space-y-5">
    <div class="flex flex-wrap items-center justify-between gap-3">
      <p class="max-w-2xl text-sm text-neu-muted">
        Plusieurs opérations d'un coup : même date, personne, catégorie et caisse ; une ligne par opération,
        <span class="font-mono">montant #libellé</span>. Négatif = sortie, positif = entrée. Le montant peut être un
        calcul : <span class="font-mono">-4545+2343</span>, <span class="font-mono">(1231 + 3344)/345</span>.
      </p>
      <div class="flex flex-wrap gap-2">
        <RouterLink to="/operations" class="btn-secondary">← Opérations</RouterLink>
        <RouterLink to="/operations/saisies" class="btn-secondary">Historique</RouterLink>
      </div>
    </div>

    <div
      v-if="lotId"
      class="flex items-center justify-between gap-3 rounded-neu bg-emerald-50 px-4 py-3 text-sm text-emerald-800"
      data-testid="bulk-lot"
    >
      <span>Saisie <strong>Ref-{{ lotId }}</strong>  : vos modifications mettront à jour ses opérations (création, modification, suppression).</span>
      <button type="button" class="btn-ghost px-2 py-1 text-sm" :disabled="saving" @click="newLot">Nouvelle saisie</button>
    </div>

    <BaseSpinner v-if="loading" label="Chargement des listes" />

    <template v-else>
      <div class="grid grid-cols-2 gap-3" role="tablist">
        <button
          type="button"
          role="tab"
          class="neu-toggle flex items-center justify-center gap-2 py-3 text-center text-sm font-bold"
          :class="{ 'neu-toggle-active': tab === 'lines' }"
          :aria-selected="tab === 'lines'"
          data-testid="tab-lines"
          @click="tab = 'lines'"
        >
          1. Lignes
          <span v-if="validLines.length" class="badge badge-muted">{{ validLines.length }}</span>
        </button>
        <button
          type="button"
          role="tab"
          class="neu-toggle flex items-center justify-center gap-2 py-3 text-center text-sm font-bold"
          :class="{ 'neu-toggle-active': tab === 'fields' }"
          :aria-selected="tab === 'fields'"
          data-testid="tab-fields"
          @click="tab = 'fields'"
        >
          2. Champs communs
          <span v-if="missingShared" class="badge badge-accent" title="Champ(s) à remplir">{{ missingShared }}</span>
          <span v-else class="badge badge-success">✓</span>
        </button>
      </div>

      <div v-show="tab === 'fields'" class="card space-y-4 p-4 sm:p-6" role="tabpanel">
        <p class="text-sm text-neu-muted">Appliqués à toutes les lignes de l'onglet 1.</p>
        <div class="grid gap-4 md:grid-cols-2">
          <FieldInput
            v-for="field in sharedFields"
            :key="field.key"
            :field="field"
            :model-value="shared[field.key]"
            @update:model-value="onShared(field.key, $event)"
          />
        </div>
        <button type="button" class="btn-secondary w-full md:w-auto" @click="tab = 'lines'">← Retour aux lignes</button>
      </div>

      <div v-show="tab === 'lines'" class="card space-y-3 p-4 sm:p-6" role="tabpanel">
        <label class="label" for="bulk-lines">Lignes (une opération par ligne)</label>
        <textarea
          id="bulk-lines"
          v-model="text"
          class="input h-[55dvh] min-h-[320px] resize-y rounded-neu font-mono leading-relaxed lg:h-[60vh]"
          rows="14"
          data-testid="bulk-lines"
          spellcheck="false"
          :placeholder="'-23616000 #she\n13000000 #miandry cash point\n-4545+2343 #hto\n(1231 + 3344)/345 #part'"
          :disabled="saving"
        />
        <p class="text-xs text-neu-muted">
          Exemples : <span class="font-mono">-23 616 000 #loyer</span> (sortie),
          <span class="font-mono">13000000 #vente</span> (entrée), <span class="font-mono">-4545+2343 #hto</span>
          (calcul = −2 202, sortie). Opérations : + − * / et parenthèses ; résultat arrondi à l'ariary.
          Le libellé après « # » est facultatif.
        </p>
      </div>

      <div v-if="lines.length" v-show="tab === 'lines'" class="card p-3 sm:p-4" data-testid="bulk-preview">
        <p class="px-2 pb-2 text-sm text-neu-muted">
          {{ validLines.length }} ligne(s)<template v-if="invalidCount">, <span class="text-rose-600">{{ invalidCount }} illisible(s)</span></template>
          <template v-if="savedCount"> · {{ savedCount }} enregistrée(s)</template>
        </p>
        <p
          v-if="toRemove.length"
          class="mb-3 rounded-neu bg-amber-50 px-3 py-2 text-sm text-amber-800"
          data-testid="bulk-remove-notice"
        >
          {{ toRemove.length }} opération(s) seront supprimées (ligne retirée) :
          <span class="font-mono">{{ toRemove.map((s) => `${s.v < 0 ? '−' : '+'}${formatMoney(Math.abs(s.v))}${s.l ? ` #${s.l}` : ''}`).join(', ') }}</span>
        </p>
        <div class="mb-3 grid gap-2 sm:grid-cols-3" data-testid="bulk-totals">
          <div class="neu-inset flex items-baseline justify-between gap-3 rounded-neu px-3 py-2 sm:block">
            <small class="block text-xs font-semibold text-neu-muted">Entrées</small>
            <strong class="block font-mono text-sm text-emerald-700">+{{ formatMoney(totals.entree) }}</strong>
          </div>
          <div class="neu-inset flex items-baseline justify-between gap-3 rounded-neu px-3 py-2 sm:block">
            <small class="block text-xs font-semibold text-neu-muted">Sorties</small>
            <strong class="block font-mono text-sm text-rose-600">−{{ formatMoney(totals.sortie) }}</strong>
          </div>
          <div class="neu-inset flex items-baseline justify-between gap-3 rounded-neu px-3 py-2 sm:block">
            <small class="block text-xs font-semibold text-neu-muted">Total net</small>
            <strong
              class="block font-mono text-sm"
              :class="totals.net < 0 ? 'text-rose-600' : 'text-emerald-700'"
            >{{ totals.net < 0 ? '−' : '+' }}{{ formatMoney(Math.abs(totals.net)) }}</strong>
          </div>
        </div>

        <ul class="space-y-2">
          <li
            v-for="line in lines"
            :key="`${line.number}-${line.raw}`"
            class="flex items-center gap-3 rounded-neu px-3 py-2"
            :class="line.error ? 'bg-rose-50' : 'neu-inset'"
            data-testid="bulk-line"
          >
            <span class="w-6 shrink-0 text-right text-xs text-neu-muted">{{ line.number }}</span>
            <span class="min-w-0 flex-1">
              <template v-if="line.error">
                <span class="block truncate font-mono text-sm">{{ line.raw }}</span>
                <span class="block text-xs text-rose-600">{{ line.error }}</span>
              </template>
              <template v-else>
                <span class="block truncate text-sm font-semibold text-neu-dark">{{ line.label || '(sans libellé)' }}</span>
                <span class="block text-xs" :class="line.mouvement === 'Sortie' ? 'text-rose-600' : 'text-emerald-700'">
                  {{ line.mouvement === 'Sortie' ? 'Sortie' : 'Entrée' }}
                </span>
                <span v-if="line.expression" class="block truncate font-mono text-xs text-neu-muted" data-testid="bulk-calc">
                  {{ line.expression }} = {{ line.mouvement === 'Sortie' ? '−' : '' }}{{ formatMoney(line.amount) }}<template v-if="line.rounded"> (arrondi)</template>
                </span>
                <span v-if="messageOf(line)" class="block text-xs text-amber-700">{{ messageOf(line) }}</span>
              </template>
            </span>
            <span
              v-if="!line.error"
              class="shrink-0 font-mono text-sm font-semibold"
              :class="line.mouvement === 'Sortie' ? 'text-rose-600' : 'text-emerald-700'"
            >
              {{ line.mouvement === 'Sortie' ? '−' : '+' }}{{ formatMoney(line.amount) }}
            </span>
            <RouterLink
              v-if="!line.error && opIdOf(line) && ['same', 'created', 'updated'].includes(statusOf(line))"
              :to="`/operations/${opIdOf(line)}`"
              class="badge shrink-0"
              :class="STATUS_CLASS[statusOf(line)]"
            >
              {{ STATUS_LABEL[statusOf(line)] }}
            </RouterLink>
            <span v-else-if="!line.error" class="badge shrink-0" :class="STATUS_CLASS[statusOf(line)]">
              {{ STATUS_LABEL[statusOf(line)] }}
            </span>
          </li>
        </ul>
      </div>

      <p v-if="formError" class="rounded-pill neu-inset px-4 py-3 text-sm text-rose-600" role="alert">{{ formError }}</p>

      <div class="flex flex-wrap gap-3 pb-20 lg:pb-0">
        <button
          type="button"
          class="btn-primary flex-1 sm:flex-none"
          data-testid="bulk-save"
          :disabled="saving || !changeCount"
          @click="askConfirm"
        >
          <template v-if="saving">Enregistrement…</template>
          <template v-else-if="!changeCount">Aucune modification</template>
          <template v-else>Enregistrer {{ changeCount }} changement(s)</template>
        </button>
      </div>
    </template>

    <BaseModal :open="confirmOpen" title="Confirmer l'enregistrement" @close="confirmOpen = false">
      <div class="space-y-4" data-testid="bulk-confirm">
        <ul class="space-y-1 text-sm" data-testid="bulk-confirm-plan">
          <li v-if="toCreate.length" class="flex justify-between"><span class="text-neu-muted">À créer</span><strong>{{ toCreate.length }}</strong></li>
          <li v-if="toUpdate.length" class="flex justify-between"><span class="text-neu-muted">À modifier</span><strong class="text-amber-700">{{ toUpdate.length }}</strong></li>
          <li v-if="toRemove.length" class="flex justify-between"><span class="text-neu-muted">À supprimer</span><strong class="text-rose-600">{{ toRemove.length }}</strong></li>
        </ul>
        <p class="text-sm text-neu-muted">Toutes les opérations de la saisie auront :</p>
        <dl class="grid grid-cols-2 gap-x-4 gap-y-2 text-sm">
          <template v-for="item in sharedSummary" :key="item.label">
            <dt class="font-semibold text-neu-muted">{{ item.label }}</dt>
            <dd class="truncate text-neu-dark">{{ item.value }}</dd>
          </template>
        </dl>
        <div class="neu-inset space-y-1 rounded-neu px-4 py-3 font-mono text-sm">
          <p class="flex justify-between gap-3">
            <span class="font-sans text-neu-muted">Entrées</span>
            <span class="text-emerald-700">+{{ formatMoney(pendingTotals.entree) }}</span>
          </p>
          <p class="flex justify-between gap-3">
            <span class="font-sans text-neu-muted">Sorties</span>
            <span class="text-rose-600">−{{ formatMoney(pendingTotals.sortie) }}</span>
          </p>
          <p class="flex justify-between gap-3 border-t border-neu-muted/20 pt-1 font-semibold">
            <span class="font-sans text-neu-dark">Total net</span>
            <span :class="pendingTotals.net < 0 ? 'text-rose-600' : 'text-emerald-700'" data-testid="bulk-confirm-net">
              {{ pendingTotals.net < 0 ? '−' : '+' }}{{ formatMoney(Math.abs(pendingTotals.net)) }}
            </span>
          </p>
        </div>
        <div class="flex gap-3">
          <button type="button" class="btn-secondary flex-1" @click="confirmOpen = false">Annuler</button>
          <button type="button" class="btn-primary flex-1" data-testid="bulk-confirm-ok" @click="saveAll">
            Confirmer
          </button>
        </div>
      </div>
    </BaseModal>
  </div>
</template>
