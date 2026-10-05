<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import BaseSpinner from '@/components/ui/BaseSpinner.vue'
import type { MzAiProvider, MzAiProviderId, MzAiSettings } from '@/services/mzAi'
import { fetchMzAiSettings, saveMzAiProvider } from '@/services/mzAi'
import { useUiStore } from '@/stores/ui'
import { extractErrorMessage } from '@/utils/format'

const BADGES: Record<MzAiProviderId, { initials: string; tone: string }> = {
  gemini: { initials: 'G', tone: 'bg-sky-100 text-sky-700' },
  claude: { initials: 'C', tone: 'bg-orange-100 text-orange-700' },
  chatgpt: { initials: 'AI', tone: 'bg-emerald-100 text-emerald-700' },
}

const ui = useUiStore()
const settings = ref<MzAiSettings | null>(null)
const selected = ref<MzAiProviderId>('gemini')
const loading = ref(true)
const error = ref('')
const saving = ref(false)

const dirty = computed(() => !!settings.value && selected.value !== settings.value.ai_provider)

function apply(data: MzAiSettings) {
  settings.value = data
  selected.value = data.ai_provider
}

onMounted(async () => {
  try {
    apply(await fetchMzAiSettings())
  } catch (err) {
    error.value = extractErrorMessage(err)
  } finally {
    loading.value = false
  }
})

function choose(p: MzAiProvider) {
  if (!settings.value?.locked_by_settings) selected.value = p.id
}

async function save() {
  saving.value = true
  try {
    apply(await saveMzAiProvider(selected.value))
    ui.notify('success', 'Fournisseur IA enregistré.')
  } catch (err) {
    ui.notify('error', extractErrorMessage(err))
  } finally {
    saving.value = false
  }
}

const compact = new Intl.NumberFormat('fr-FR', { notation: 'compact', maximumFractionDigits: 1 })

function usageLine(p: MzAiProvider) {
  const u = p.usage
  return `${u.requests} appel${u.requests > 1 ? 's' : ''} · ${compact.format(u.input_tokens)} tokens entrée · ${compact.format(u.output_tokens)} tokens sortie`
}
</script>

<template>
  <div class="mx-auto max-w-3xl space-y-5">
    <p class="px-1 text-sm text-neu-muted">
      Choisissez le moteur d'intelligence artificielle (module mz_api_integration) utilisé par l'application.
    </p>

    <BaseSpinner v-if="loading" label="Chargement des réglages IA" />
    <p v-else-if="error" class="rounded-pill neu-inset px-4 py-3 text-sm text-rose-600">{{ error }}</p>

    <template v-else-if="settings">
      <p v-if="settings.locked_by_settings" class="rounded-pill neu-inset px-4 py-3 text-sm text-neu-muted">
        Le fournisseur actif est imposé par <code>settings.php</code> (<code>$settings['mz_ai_provider']</code>).
      </p>

      <div role="radiogroup" aria-label="Fournisseur IA" class="space-y-5">
        <div
          v-for="p in settings.providers"
          :key="p.id"
          role="radio"
          :aria-checked="selected === p.id"
          :aria-disabled="settings.locked_by_settings"
          tabindex="0"
          class="card space-y-3 p-5 transition"
          :class="[selected === p.id ? 'ring-2 ring-accent' : '', settings.locked_by_settings ? '' : 'cursor-pointer']"
          :data-testid="`ai-${p.id}`"
          @click="choose(p)"
          @keydown.enter.space.prevent="choose(p)"
        >
          <div class="flex items-start gap-4">
            <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-full text-sm font-bold" :class="BADGES[p.id]?.tone">
              {{ BADGES[p.id]?.initials ?? p.label.charAt(0) }}
            </span>
            <div class="min-w-0 flex-1">
              <p class="font-semibold text-neu-dark">{{ p.label }}</p>
              <p class="text-sm text-neu-muted">Modèle : {{ p.model }}</p>
            </div>
            <span
              v-if="selected === p.id"
              class="flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-accent text-xs font-bold text-white"
              aria-hidden="true"
            >✓</span>
          </div>

          <div class="flex flex-wrap gap-2">
            <span
              class="rounded-full px-2.5 py-0.5 text-[0.65rem] font-bold uppercase tracking-wider"
              :class="p.configured ? 'bg-emerald-100 text-emerald-700' : 'bg-rose-100 text-rose-700'"
            >
              {{ p.configured ? 'Clé API configurée' : 'Clé API manquante' }}
            </span>
            <span v-if="settings.ai_provider === p.id" class="rounded-full bg-sky-100 px-2.5 py-0.5 text-[0.65rem] font-bold uppercase tracking-wider text-sky-700">
              Actif
            </span>
          </div>

          <div class="border-t border-ink-100/70 pt-3">
            <p class="text-sm font-semibold text-neu-dark">Coût estimé : {{ p.usage.cost_usd_formatted }}</p>
            <p class="text-xs text-neu-muted">{{ usageLine(p) }}</p>
            <p v-if="p.usage.last_request_label" class="text-xs text-neu-muted">Dernier appel : {{ p.usage.last_request_label }}</p>
          </div>
        </div>
      </div>

      <p class="px-1 text-xs text-neu-muted">{{ settings.usage_note }}</p>

      <button
        type="button"
        class="btn-primary w-full"
        data-testid="ai-save"
        :disabled="!dirty || saving || settings.locked_by_settings"
        @click="save"
      >
        {{ saving ? 'Enregistrement…' : 'Enregistrer le fournisseur IA' }}
      </button>
    </template>
  </div>
</template>
