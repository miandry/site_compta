<script setup lang="ts">
import { computed, reactive, ref } from 'vue'
import AppIcon from '@/components/ui/AppIcon.vue'
import type { FieldDef, FormValue, FormValues, LookupItem } from '@/schema'
import { BUNDLES } from '@/schema'
import type { FileRef } from '@/services/entities'
import { emptyValues, saveEntity, uploadFile } from '@/services/entities'
import { useLookupsStore } from '@/stores/lookups'
import { useTermsStore } from '@/stores/terms'
import { extractErrorMessage } from '@/utils/format'

const props = defineProps<{
  field: FieldDef
  modelValue: FormValue
  files?: FileRef[]
  excludeId?: string
}>()

const emit = defineEmits<{
  'update:modelValue': [value: FormValue]
  'update:files': [value: FileRef[]]
}>()

const terms = useTermsStore()
const lookups = useLookupsStore()
const uploading = ref(false)
const uploadError = ref('')

const inputId = computed(() => `f-${props.field.key}`)

const choices = computed<{ value: string; label: string }[]>(() => {
  const f = props.field
  if (f.kind === 'options') {
    return Object.entries(f.options ?? {}).map(([value, label]) => ({ value, label }))
  }
  if (f.kind === 'term' && f.vocabulary) {
    return terms.byVocabulary[f.vocabulary].map((t) => ({ value: t.id, label: t.name }))
  }
  if (f.kind === 'node' && f.target) {
    const list = (lookups.items as Record<string, LookupItem[]>)[f.target] ?? []
    return list
      .filter((item) => item.id !== props.excludeId)
      .filter((item) => !f.targetFilter || f.targetFilter(item))
      .map((item) => ({ value: item.id, label: item.label }))
  }
  return []
})

const canCreate = computed(() => Boolean(props.field.creatable) && (props.field.kind === 'term' || props.field.kind === 'node'))

const useToggles = computed(
  () =>
    !canCreate.value &&
    (props.field.kind === 'options' || (props.field.kind === 'term' && props.field.required)) &&
    choices.value.length > 0 &&
    choices.value.length <= 2,
)

/** Champs saisis dans la mini-création : nom du terme, ou champs texte du bundle cible. */
const quickFields = computed<FieldDef[]>(() => {
  const f = props.field
  if (f.kind === 'node' && f.target) {
    return BUNDLES[f.target].fields.filter((q) => q.kind === 'text' || q.kind === 'tel' || q.kind === 'email')
  }
  return [{ key: 'name', label: 'Nom', kind: 'text', required: true }]
})

const creating = ref(false)
const saving = ref(false)
const createError = ref('')
const draft = reactive<Record<string, string>>({})

function startCreate() {
  for (const key of Object.keys(draft)) delete draft[key]
  for (const q of quickFields.value) draft[q.key] = ''
  createError.value = ''
  creating.value = true
}

function cancelCreate() {
  creating.value = false
}

async function submitCreate() {
  const missing = quickFields.value.find((q) => q.required && !draft[q.key]?.trim())
  if (missing) {
    createError.value = `Renseignez « ${missing.label} ».`
    return
  }
  const f = props.field
  saving.value = true
  createError.value = ''
  try {
    let id = ''
    if (f.kind === 'term' && f.vocabulary) {
      id = await terms.save(f.vocabulary, { name: draft.name.trim(), description: '' })
    } else if (f.kind === 'node' && f.target) {
      const values: FormValues = { ...emptyValues(BUNDLES[f.target]), ...draft }
      id = await saveEntity(f.target, values, lookups.deriveContext())
      lookups.invalidate(f.target)
      await lookups.load(f.target, true)
    }
    update(id)
    creating.value = false
  } catch (err) {
    createError.value = extractErrorMessage(err)
  } finally {
    saving.value = false
  }
}
const multipleImages = computed(() => props.field.kind === 'image' && props.field.multiple !== false)

function update(value: FormValue) {
  emit('update:modelValue', value)
}

function groupDigits(value: FormValue): string {
  if (value === '' || value == null) return ''
  const n = Math.round(Number(value))
  return Number.isFinite(n) ? String(n).replace(/\B(?=(\d{3})+(?!\d))/g, ' ') : ''
}

function onNumberInput(event: Event) {
  const input = event.target as HTMLInputElement
  const digits = input.value.replace(/\D/g, '')
  const value = digits === '' ? '' : Number(digits)
  input.value = groupDigits(value)
  update(value)
}

async function onFiles(event: Event) {
  const input = event.target as HTMLInputElement
  const selected = Array.from(input.files ?? [])
  if (!selected.length) return
  uploading.value = true
  uploadError.value = ''
  try {
    const uploaded = await Promise.all(selected.map((file) => uploadFile(file)))
    if (props.field.kind === 'file') {
      update(uploaded[0].url)
    } else {
      const next = multipleImages.value ? [...(props.files ?? []), ...uploaded] : uploaded.slice(0, 1)
      emit('update:files', next)
      update(next.map((f) => f.id))
    }
  } catch (err) {
    uploadError.value = extractErrorMessage(err)
  } finally {
    uploading.value = false
    input.value = ''
  }
}

function removeImage(id: string) {
  const next = (props.files ?? []).filter((f) => f.id !== id)
  emit('update:files', next)
  update(next.map((f) => f.id))
}
</script>

<template>
  <div>
    <label v-if="field.kind !== 'boolean'" class="label" :for="inputId">
      {{ field.label }}<span v-if="field.required" class="text-accent"> *</span>
    </label>

    <div v-if="useToggles" class="grid grid-cols-2 gap-3">
      <button
        v-for="choice in choices"
        :key="choice.value"
        type="button"
        class="neu-toggle text-center font-semibold"
        :class="{ 'neu-toggle-active': modelValue === choice.value }"
        @click="update(choice.value)"
      >
        {{ choice.label }}
      </button>
    </div>

    <div v-else-if="field.kind === 'options' || field.kind === 'term' || field.kind === 'node'">
      <div class="flex gap-2">
        <select
          :id="inputId"
          class="input min-w-0 flex-1"
          :required="field.required"
          :value="modelValue"
          @change="update(($event.target as HTMLSelectElement).value)"
        >
          <option value="">{{ field.required ? 'Choisir…' : '— Aucun —' }}</option>
          <option v-for="choice in choices" :key="choice.value" :value="choice.value">
            {{ choice.label }}
          </option>
        </select>
        <button
          v-if="canCreate"
          type="button"
          class="btn-icon h-12 w-12 shrink-0 text-accent"
          :class="{ 'shadow-neu-in': creating }"
          :title="`Ajouter — ${field.label}`"
          :aria-label="`Ajouter — ${field.label}`"
          @click="creating ? cancelCreate() : startCreate()"
        >
          <AppIcon :name="creating ? 'close' : 'plus'" :size="18" />
        </button>
      </div>

      <div v-if="creating" class="neu-inset mt-3 space-y-3 rounded-neu p-4">
        <p class="text-xs font-bold uppercase tracking-[0.12em] text-neu-muted">Nouveau — {{ field.label }}</p>
        <input
          v-for="(quick, index) in quickFields"
          :key="quick.key"
          v-model="draft[quick.key]"
          class="input"
          :type="quick.kind === 'tel' ? 'tel' : quick.kind === 'email' ? 'email' : 'text'"
          :placeholder="`${quick.label}${quick.required ? ' *' : ''}`"
          :autofocus="index === 0"
          @keydown.enter.prevent="submitCreate"
        />
        <p v-if="createError" class="text-sm text-rose-600">{{ createError }}</p>
        <div class="flex gap-2">
          <button type="button" class="btn-secondary flex-1" :disabled="saving" @click="cancelCreate">Annuler</button>
          <button type="button" class="btn-primary flex-1" :disabled="saving" @click="submitCreate">
            {{ saving ? 'Ajout…' : 'Ajouter' }}
          </button>
        </div>
      </div>
    </div>

    <button
      v-else-if="field.kind === 'boolean'"
      :id="inputId"
      type="button"
      class="neu-toggle flex w-full items-center justify-between font-semibold"
      :class="{ 'neu-toggle-active': modelValue }"
      @click="update(!modelValue)"
    >
      <span>{{ field.label }}</span>
      <span class="badge" :class="modelValue ? 'badge-success' : 'badge-muted'">
        {{ modelValue ? 'Oui' : 'Non' }}
      </span>
    </button>

    <textarea
      v-else-if="field.kind === 'textarea'"
      :id="inputId"
      class="input min-h-[110px] !rounded-neu"
      rows="4"
      :required="field.required"
      :placeholder="field.placeholder"
      :value="String(modelValue ?? '')"
      @input="update(($event.target as HTMLTextAreaElement).value)"
    />

    <div v-else-if="field.kind === 'money' || field.kind === 'number'" class="input-group">
      <input
        :id="inputId"
        class="input font-mono"
        type="text"
        inputmode="numeric"
        autocomplete="off"
        :required="field.required"
        :placeholder="field.placeholder ?? '0'"
        :value="groupDigits(modelValue)"
        @input="onNumberInput"
      />
      <span v-if="field.kind === 'money'" class="input-group-text font-mono text-xs font-bold">Ar</span>
    </div>

    <div v-else-if="field.kind === 'file' || field.kind === 'image'" class="space-y-3">
      <label class="neu-dropzone" :for="inputId">
        <span class="text-sm font-semibold text-neu-dark">
          {{
            uploading
              ? 'Envoi en cours…'
              : field.kind === 'image'
                ? multipleImages
                  ? 'Ajouter des images'
                  : files?.length
                    ? "Remplacer l'image"
                    : 'Ajouter une image'
                : 'Choisir un fichier PDF'
          }}
        </span>
        <span class="mt-1 text-xs text-neu-muted">Cliquez pour parcourir</span>
        <input
          :id="inputId"
          type="file"
          class="sr-only"
          :accept="field.kind === 'image' ? 'image/*' : 'application/pdf,image/*'"
          :multiple="multipleImages"
          :disabled="uploading"
          @change="onFiles"
        />
      </label>
      <a
        v-if="field.kind === 'file' && modelValue"
        :href="String(modelValue)"
        target="_blank"
        rel="noopener"
        class="badge max-w-full truncate"
      >
        {{ String(modelValue).split('/').pop() }}
      </a>
      <div v-if="field.kind === 'image' && files?.length" class="flex flex-wrap gap-3">
        <div v-for="file in files" :key="file.id" class="neu-raised-sm relative h-20 w-20 overflow-hidden rounded-neu">
          <img v-if="file.url" :src="file.url" alt="" class="h-full w-full object-cover" />
          <button
            type="button"
            class="absolute right-1 top-1 rounded-pill bg-white/80 px-1.5 text-xs text-accent"
            aria-label="Retirer"
            @click="removeImage(file.id)"
          >
            ✕
          </button>
        </div>
      </div>
      <p v-if="uploadError" class="text-sm text-rose-600">{{ uploadError }}</p>
    </div>

    <input
      v-else
      :id="inputId"
      class="input"
      :type="field.kind === 'date' ? 'date' : field.kind === 'email' ? 'email' : field.kind === 'tel' ? 'tel' : 'text'"
      :required="field.required"
      :placeholder="field.placeholder"
      :value="modelValue"
      @input="update(($event.target as HTMLInputElement).value)"
    />
  </div>
</template>
