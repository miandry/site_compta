import { defineStore } from 'pinia'
import { reactive, ref } from 'vue'
import type { Vocabulary } from '@/schema'
import * as termsService from '@/services/terms'
import type { Term } from '@/services/terms'

export const useTermsStore = defineStore('terms', () => {
  const byVocabulary = reactive<Record<Vocabulary, Term[]>>({
    operation_type: [],
    category: [],
    method_payment: [],
    caisse: [],
  })
  const loaded = new Set<Vocabulary>()
  const loading = ref(false)

  async function load(vocabulary: Vocabulary, force = false) {
    if (loaded.has(vocabulary) && !force) return byVocabulary[vocabulary]
    loading.value = true
    try {
      byVocabulary[vocabulary] = (await termsService.fetchTerms(vocabulary)).sort(
        (a, b) => a.weight - b.weight,
      )
      loaded.add(vocabulary)
      return byVocabulary[vocabulary]
    } finally {
      loading.value = false
    }
  }

  function label(vocabulary: Vocabulary, id: string): string {
    return byVocabulary[vocabulary].find((t) => t.id === id)?.name ?? ''
  }

  async function save(vocabulary: Vocabulary, payload: { name: string; description: string }, id?: string) {
    const savedId = await termsService.saveTerm(vocabulary, payload, id)
    await load(vocabulary, true)
    return savedId
  }

  async function remove(vocabulary: Vocabulary, id: string) {
    await termsService.removeTerm(vocabulary, id)
    byVocabulary[vocabulary] = byVocabulary[vocabulary].filter((t) => t.id !== id)
  }

  return { byVocabulary, loading, load, label, save, remove }
})
