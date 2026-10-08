<script setup lang="ts">
import { computed, reactive, ref, watch } from 'vue'
import { RouterLink } from 'vue-router'
import FieldInput from '@/components/entity/FieldInput.vue'
import BaseSpinner from '@/components/ui/BaseSpinner.vue'
import type { FormValue, FormValues } from '@/schema'
import { BUNDLES, fieldDef } from '@/schema'
import { duplicateMessage, saveEntity } from '@/services/entities'
import { useLookupsStore } from '@/stores/lookups'
import { useTermsStore } from '@/stores/terms'
import { useUiStore } from '@/stores/ui'
import BaseModal from '@/components/ui/BaseModal.vue'
import { evaluate, isExpression } from '@/utils/calc'
import { extractErrorMessage, formatMoney, todayInput } from '@/utils/format'

type LineStatus = 'ready' | 'saving' | 'ok' | 'duplicate' | 'error'

interface Line {
  raw: string
  /** Texte + rang parmi les lignes identiques : garde le statut quand on modifie la zone. */
  key: string
  number: number
  amount: number
  label: string
  mouvement: 'Entree' | 'Sortie'
  error: string
  status: LineStatus
  message: string
  id?: string
  /** Calcul saisi (« (1231+3344)/345 »), vide pour un simple nombre. */
  expression: string
  /** Résultat non entier, arrondi à l'ariary. */
  rounded: boolean
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

/** Statuts déjà obtenus, par texte de ligne : une ligne enregistrée n'est jamais renvoyée. */
const done = reactive<Record<string, Pick<Line, 'status' | 'message' | 'id'>>>({})

/**
 * « -23616000 #she » => sortie de 23 616 000, libellé « she ».
 * « -4545+2343 #hto » => calculé : -2 202, donc sortie de 2 202.
 * Le signe du résultat donne le mouvement ; arrondi à l'ariary.
 */
function parseLine(raw: string, number: number, key: string): Line {
  const line: Line = {
    raw, key, number, amount: 0, label: '', mouvement: 'Entree', error: '', status: 'ready', message: '',
    expression: '', rounded: false,
  }
  const hash = raw.indexOf('#')
  const amountPart = (hash >= 0 ? raw.slice(0, hash) : raw).trim()
  line.label = hash >= 0 ? raw.slice(hash + 1).replace(/\s+/g, ' ').trim() : ''
  if (!amountPart) {
    line.error = 'Montant manquant'
    return line
  }

  let result: number
  try {
    result = evaluate(amountPart)
  } catch (err) {
    line.error = `Montant illisible « ${amountPart} » : ${(err as Error).message}`
    return line
  }
  const rounded = Math.round(result)
  if (!rounded) {
    line.error = `Montant nul (${amountPart} = ${result})`
    return line
  }
  line.amount = Math.abs(rounded)
  line.mouvement = rounded < 0 ? 'Sortie' : 'Entree'
  line.expression = isExpression(amountPart) ? amountPart : ''
  line.rounded = rounded !== result
  const previous = done[key]
  if (previous) Object.assign(line, previous)
  return line
}

const lines = computed<Line[]>(() => {
  const seen: Record<string, number> = {}
  return text.value
    .split(/\r?\n/)
    .map((raw, i) => ({ raw: raw.trim(), number: i + 1 }))
    .filter((l) => l.raw !== '')
    .map((l) => {
      seen[l.raw] = (seen[l.raw] ?? 0) + 1
      return parseLine(l.raw, l.number, `${seen[l.raw]}|${l.raw}`)
    })
})

const validLines = computed(() => lines.value.filter((l) => !l.error))
const toSave = computed(() => validLines.value.filter((l) => l.status !== 'ok'))
const invalidCount = computed(() => lines.value.length - validLines.value.length)
const totals = computed(() => {
  let entree = 0
  let sortie = 0
  for (const l of validLines.value) {
    if (l.mouvement === 'Sortie') sortie += l.amount
    else entree += l.amount
  }
  return { entree, sortie, net: entree - sortie }
})
const savedCount = computed(() => lines.value.filter((l) => l.status === 'ok').length)

// Lignes en cours d'enregistrement (statut affiché en direct).
const live = reactive<Record<string, LineStatus>>({})
const statusOf = (l: Line): LineStatus => live[l.key] ?? l.status

async function init() {
  loading.value = true
  try {
    await Promise.all([terms.load('category'), terms.load('caisse'), lookups.load('person')])
  } catch (err) {
    ui.notify('error', extractErrorMessage(err))
  } finally {
    loading.value = false
  }
}
init()

watch(text, () => (formError.value = ''))

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
const pendingTotals = computed(() => {
  let entree = 0
  let sortie = 0
  for (const l of toSave.value) {
    if (l.mouvement === 'Sortie') sortie += l.amount
    else entree += l.amount
  }
  return { entree, sortie, net: entree - sortie }
})

function askConfirm() {
  formError.value = validate()
  if (formError.value || saving.value) return
  confirmOpen.value = true
}

async function saveAll() {
  confirmOpen.value = false
  formError.value = validate()
  if (formError.value || saving.value) return
  saving.value = true
  let ok = 0
  let duplicates = 0
  let errors = 0
  const ctx = lookups.deriveContext()

  // Une par une : la détection de doublons côté Drupal voit les lignes précédentes.
  for (const line of toSave.value) {
    live[line.key] = 'saving'
    const values: FormValues = {
      ...shared,
      field_mouvement_argent: line.mouvement,
      field_amount: line.amount,
      field_label: line.label,
      field_operation_type: '',
      field_method_payment: '',
      field_image_prof: [],
    }
    try {
      const id = await saveEntity('operation', values, ctx)
      done[line.key] = { status: 'ok', message: '', id }
      ok++
    } catch (err) {
      const dup = duplicateMessage(err)
      done[line.key] = dup
        ? { status: 'duplicate', message: dup }
        : { status: 'error', message: extractErrorMessage(err) }
      dup ? duplicates++ : errors++
    } finally {
      delete live[line.key]
    }
  }
  saving.value = false

  const parts = [`${ok} opération(s) créée(s)`]
  if (duplicates) parts.push(`${duplicates} doublon(s) refusé(s)`)
  if (errors) parts.push(`${errors} erreur(s)`)
  ui.notify(errors || duplicates ? 'error' : 'success', parts.join(', ') + '.')
}

/** Retire de la zone les lignes déjà enregistrées. */
function clearSaved() {
  const saved = new Set(lines.value.filter((l) => l.status === 'ok').map((l) => l.number))
  text.value = text.value
    .split(/\r?\n/)
    .filter((_, i) => !saved.has(i + 1))
    .join('\n')
    .trim()
  for (const key of Object.keys(done)) delete done[key]
}

function onShared(key: string, value: FormValue) {
  shared[key] = value
  formError.value = ''
}

const STATUS_LABEL: Record<LineStatus, string> = {
  ready: 'Prêt',
  saving: 'Envoi…',
  ok: 'Créé',
  duplicate: 'Doublon',
  error: 'Erreur',
}
const STATUS_CLASS: Record<LineStatus, string> = {
  ready: 'badge-muted',
  saving: 'badge-warning',
  ok: 'badge-success',
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
      <RouterLink to="/operations" class="btn-secondary">← Opérations</RouterLink>
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
          <template v-if="savedCount"> · {{ savedCount }} créée(s)</template>
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
                <span v-if="line.message" class="block text-xs text-amber-700">{{ line.message }}</span>
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
              v-if="statusOf(line) === 'ok' && line.id"
              :to="`/operations/${line.id}`"
              class="badge badge-success shrink-0"
            >
              {{ STATUS_LABEL.ok }}
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
          :disabled="saving || !toSave.length"
          @click="askConfirm"
        >
          <template v-if="saving">Enregistrement…</template>
          <template v-else>Enregistrer {{ toSave.length }} opération(s)</template>
        </button>
        <button v-if="savedCount && !saving" type="button" class="btn-secondary" @click="clearSaved">
          Retirer les lignes créées
        </button>
      </div>
    </template>

    <BaseModal :open="confirmOpen" title="Confirmer l'enregistrement" @close="confirmOpen = false">
      <div class="space-y-4" data-testid="bulk-confirm">
        <p class="text-sm text-neu-muted">
          {{ toSave.length }} opération(s) vont être créées avec :
        </p>
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
