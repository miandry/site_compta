<script setup lang="ts">
import { computed, reactive, ref, watch } from 'vue'
import FormSteps from '@/components/ui/FormSteps.vue'
import FieldInput from './FieldInput.vue'
import type { BundleDef, FormValue, FormValues } from '@/schema'
import { fieldDef } from '@/schema'
import type { EntityRecord, FileRef } from '@/services/entities'
import { emptyValues } from '@/services/entities'
import { useLookupsStore } from '@/stores/lookups'
import { todayInput } from '@/utils/format'

const props = defineProps<{
  def: BundleDef
  initial?: EntityRecord | null
  submitting?: boolean
}>()

const emit = defineEmits<{
  submit: [values: FormValues]
  cancel: []
}>()

const lookups = useLookupsStore()
const step = ref(0)
const localError = ref('')
const values = reactive<FormValues>({})
const files = reactive<Record<string, FileRef[]>>({})

const steps = computed(() => props.def.steps.map((s) => s.label))
const isLast = computed(() => step.value === props.def.steps.length - 1)

function reset() {
  for (const key of Object.keys(values)) delete values[key]
  for (const key of Object.keys(files)) delete files[key]
  const base = emptyValues(props.def)
  if (props.initial) {
    Object.assign(base, props.initial.values)
    if ('title' in base) base.title = props.initial.title
    Object.assign(files, props.initial.files)
  } else {
    for (const field of props.def.fields) {
      if (field.kind === 'date' && field.required) base[field.key] = todayInput()
    }
  }
  Object.assign(values, base)
  step.value = 0
  localError.value = ''
}

watch(() => [props.initial, props.def], reset, { immediate: true })

function onChange(key: string, value: FormValue) {
  values[key] = value
  props.def.derive?.(values, key, lookups.deriveContext())
}

function validateStep(index: number): string {
  for (const key of props.def.steps[index].fields) {
    const field = fieldDef(props.def, key)
    const value = values[key]
    if (field.required && (value === '' || value == null || (Array.isArray(value) && !value.length))) {
      return `Renseignez « ${field.label} ».`
    }
  }
  return isLastIndex(index) ? (props.def.validate?.(values) ?? '') : ''
}

function isLastIndex(index: number) {
  return index === props.def.steps.length - 1
}

function goStep(index: number) {
  for (let i = step.value; i < index; i++) {
    const err = validateStep(i)
    if (err) {
      localError.value = err
      return
    }
  }
  localError.value = ''
  step.value = index
}

function next() {
  const err = validateStep(step.value)
  if (err) {
    localError.value = err
    return
  }
  localError.value = ''
  if (isLast.value) {
    const all = props.def.steps.map((_, i) => validateStep(i)).find(Boolean)
    if (all) {
      localError.value = all
      return
    }
    emit('submit', { ...values })
    return
  }
  step.value += 1
}

function prev() {
  localError.value = ''
  if (step.value === 0) {
    emit('cancel')
    return
  }
  step.value -= 1
}
</script>

<template>
  <form class="flex min-h-[320px] flex-col" novalidate @submit.prevent="next">
    <FormSteps :steps="steps" :current="step" @go="goStep" />

    <div class="flex-1">
      <div
        v-for="(group, index) in def.steps"
        v-show="step === index"
        :key="group.label"
        class="space-y-4"
      >
        <FieldInput
          v-for="key in group.fields"
          :key="key"
          :field="fieldDef(def, key)"
          :model-value="values[key]"
          :files="files[key]"
          @update:model-value="onChange(key, $event)"
          @update:files="files[key] = $event"
        />
      </div>
    </div>

    <p v-if="localError" class="mt-4 rounded-pill neu-inset px-4 py-3 text-sm text-rose-600">
      {{ localError }}
    </p>

    <div class="mt-6 flex gap-3">
      <button type="button" class="btn-secondary flex-1" :disabled="submitting" @click="prev">
        {{ step === 0 ? 'Annuler' : 'Précédent' }}
      </button>
      <button type="submit" class="btn-primary flex-1" :disabled="submitting">
        <template v-if="submitting">Enregistrement…</template>
        <template v-else-if="isLast">Enregistrer</template>
        <template v-else>Suivant</template>
      </button>
    </div>
  </form>
</template>
