<script setup lang="ts">
import { computed, reactive, ref, watch } from 'vue'
import ActionSheet from '@/components/ui/ActionSheet.vue'
import type { SheetAction } from '@/components/ui/ActionSheet.vue'
import AppIcon from '@/components/ui/AppIcon.vue'
import BaseModal from '@/components/ui/BaseModal.vue'
import BaseSpinner from '@/components/ui/BaseSpinner.vue'
import MobileFab from '@/components/ui/MobileFab.vue'
import type { Vocabulary } from '@/schema'
import { VOCABULARIES } from '@/schema'
import type { Term } from '@/services/terms'
import { useTermsStore } from '@/stores/terms'
import { useUiStore } from '@/stores/ui'
import { avatarFor, extractErrorMessage } from '@/utils/format'

const props = defineProps<{ vocabulary: Vocabulary }>()

const terms = useTermsStore()
const ui = useUiStore()

const info = computed(() => VOCABULARIES[props.vocabulary])
const items = computed(() => terms.byVocabulary[props.vocabulary])

const search = ref('')
const filtered = computed(() => {
  const q = search.value.trim().toLowerCase()
  return q ? items.value.filter((t) => `${t.name} ${t.description}`.toLowerCase().includes(q)) : items.value
})

const selected = ref<Term | null>(null)
const sheetActions: SheetAction[] = [
  { id: 'edit', label: 'Modifier', icon: 'edit' },
  { id: 'delete', label: 'Supprimer', icon: 'trash', danger: true },
]

const modalOpen = ref(false)
const submitting = ref(false)
const editing = ref<Term | null>(null)
const form = reactive({ name: '', description: '' })
const localError = ref('')

async function refresh() {
  try {
    await terms.load(props.vocabulary, true)
  } catch (err) {
    ui.notify('error', extractErrorMessage(err))
  }
}

watch(
  () => props.vocabulary,
  () => {
    search.value = ''
    refresh()
  },
  { immediate: true },
)

function openCreate() {
  editing.value = null
  form.name = ''
  form.description = ''
  localError.value = ''
  modalOpen.value = true
}

function openEdit(term: Term) {
  editing.value = term
  form.name = term.name
  form.description = term.description
  localError.value = ''
  modalOpen.value = true
}

function act(action: string) {
  const term = selected.value
  selected.value = null
  if (!term) return
  if (action === 'edit') openEdit(term)
  else onDelete(term)
}

async function onSubmit() {
  if (!form.name.trim()) {
    localError.value = 'Indiquez un nom.'
    return
  }
  submitting.value = true
  try {
    await terms.save(props.vocabulary, { ...form }, editing.value?.id)
    ui.notify('success', editing.value ? 'Terme mis à jour.' : 'Terme créé.')
    modalOpen.value = false
  } catch (err) {
    ui.notify('error', extractErrorMessage(err))
  } finally {
    submitting.value = false
  }
}

async function onDelete(term: Term) {
  if (!confirm(`Supprimer « ${term.name} » ?`)) return
  try {
    await terms.remove(props.vocabulary, term.id)
    ui.notify('success', 'Terme supprimé.')
  } catch (err) {
    ui.notify('error', extractErrorMessage(err))
  }
}
</script>

<template>
  <div class="space-y-5">
    <div class="hidden flex-wrap items-center justify-between gap-3 md:flex">
      <p class="max-w-2xl text-sm text-neu-muted">{{ info.description }}</p>
      <button type="button" class="btn-primary" @click="openCreate">+ Ajouter</button>
    </div>

    <input
      v-model="search"
      type="search"
      class="input md:hidden"
      :placeholder="`Rechercher — ${info.label}`"
    />

    <BaseSpinner v-if="terms.loading && !items.length" :label="`Chargement — ${info.label}`" />

    <template v-else>
      <div class="space-y-4 md:hidden">
        <p class="px-2 text-sm text-neu-muted">{{ filtered.length }} élément(s)</p>
        <ul v-if="filtered.length" class="card divide-y divide-ink-100/70 overflow-hidden p-0">
          <li v-for="term in filtered" :key="term.id">
            <button
              type="button"
              class="flex w-full items-center gap-3 px-4 py-3.5 text-left transition active:bg-ink-100"
              @click="selected = term"
            >
              <span
                class="flex h-11 w-11 shrink-0 items-center justify-center rounded-full text-sm font-bold"
                :class="avatarFor(term.name).tone"
              >
                {{ avatarFor(term.name).initials }}
              </span>
              <span class="min-w-0 flex-1">
                <span class="block truncate text-[0.95rem] font-semibold text-neu-dark">{{ term.name }}</span>
                <span class="block truncate text-xs text-neu-muted">{{ term.description || '—' }}</span>
              </span>
            </button>
          </li>
        </ul>
        <p v-else class="card px-4 py-12 text-center text-sm text-neu-muted">Aucun élément.</p>
      </div>

      <div class="card hidden overflow-hidden p-2 md:block">
        <div v-if="!items.length" class="px-4 py-10 text-center text-sm text-neu-muted">Aucun terme.</div>
        <div
          v-for="term in items"
          :key="term.id"
          class="flex items-center justify-between gap-3 rounded-neu px-4 py-4"
        >
          <div class="flex min-w-0 items-center gap-3">
            <span
              class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full text-sm font-bold"
              :class="avatarFor(term.name).tone"
            >
              {{ avatarFor(term.name).initials }}
            </span>
            <div class="min-w-0">
              <p class="font-semibold text-neu-dark">{{ term.name }}</p>
              <p class="truncate text-sm text-neu-muted">{{ term.description || '—' }}</p>
            </div>
          </div>
          <div class="flex shrink-0 gap-2">
            <button type="button" class="btn-icon" title="Modifier" aria-label="Modifier" @click="openEdit(term)">
              <AppIcon name="edit" :size="16" />
            </button>
            <button
              type="button"
              class="btn-icon text-rose-600"
              title="Supprimer"
              aria-label="Supprimer"
              @click="onDelete(term)"
            >
              <AppIcon name="trash" :size="16" />
            </button>
          </div>
        </div>
      </div>
    </template>

    <ActionSheet :open="Boolean(selected)" :actions="sheetActions" @close="selected = null" @action="act">
      <div v-if="selected" class="flex items-center gap-3">
        <span
          class="flex h-11 w-11 shrink-0 items-center justify-center rounded-full text-sm font-bold"
          :class="avatarFor(selected.name).tone"
        >
          {{ avatarFor(selected.name).initials }}
        </span>
        <div class="min-w-0 flex-1">
          <p class="truncate font-semibold text-neu-dark">{{ selected.name }}</p>
          <p class="truncate text-xs text-neu-muted">{{ selected.description || info.label }}</p>
        </div>
      </div>
    </ActionSheet>

    <MobileFab :label="`Ajouter — ${info.label}`" @click="openCreate" />

    <BaseModal
      :open="modalOpen"
      :title="editing ? `Modifier — ${info.label}` : `Nouveau — ${info.label}`"
      @close="modalOpen = false"
    >
      <form class="space-y-4" @submit.prevent="onSubmit">
        <div>
          <label class="label" for="term-name">Nom <span class="text-accent">*</span></label>
          <input id="term-name" v-model="form.name" class="input" required />
        </div>
        <div>
          <label class="label" for="term-desc">Description</label>
          <textarea
            id="term-desc"
            v-model="form.description"
            class="input min-h-[110px] !rounded-neu"
            rows="4"
            placeholder="Optionnel…"
          />
        </div>
        <p v-if="localError" class="rounded-pill neu-inset px-4 py-3 text-sm text-rose-600">{{ localError }}</p>
        <div class="flex gap-3 pt-2">
          <button type="button" class="btn-secondary flex-1" :disabled="submitting" @click="modalOpen = false">
            Annuler
          </button>
          <button type="submit" class="btn-primary flex-1" :disabled="submitting">
            {{ submitting ? 'Enregistrement…' : 'Enregistrer' }}
          </button>
        </div>
      </form>
    </BaseModal>
  </div>
</template>
