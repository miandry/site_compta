import { defineStore } from 'pinia'
import { reactive } from 'vue'
import type { BundleName, DeriveContext, LookupItem } from '@/schema'
import { listEntities } from '@/services/entities'
import { useTermsStore } from './terms'

type LookupBundle = 'person'

const LOOKUP_FIELDS: Record<LookupBundle, string[]> = {
  person: ['nid', 'title', 'field_phone_number'],
}

/** Listes de sélection (personnes) partagées entre les vues. */
export const useLookupsStore = defineStore('lookups', () => {
  const items = reactive<Record<LookupBundle, LookupItem[]>>({ person: [] })
  const loaded = new Set<LookupBundle>()

  async function load(bundle: BundleName, force = false) {
    if (!(bundle in items)) return []
    const key = bundle as LookupBundle
    if (loaded.has(key) && !force) return items[key]
    const { items: records } = await listEntities(key, { limit: 'all', fields: LOOKUP_FIELDS[key] })
    items[key] = records.map((r) => ({ id: r.id, label: r.title, values: { ...r.values, title: r.title } }))
    loaded.add(key)
    return items[key]
  }

  function invalidate(bundle: BundleName) {
    loaded.delete(bundle as LookupBundle)
  }

  function find(bundle: BundleName, id: string): LookupItem | undefined {
    return (items as Record<string, LookupItem[]>)[bundle]?.find((i) => i.id === id)
  }

  function deriveContext(): DeriveContext {
    const terms = useTermsStore()
    return {
      termLabel: (vocabulary, id) => terms.label(vocabulary, id),
      lookup: (bundle, id) => find(bundle, id),
    }
  }

  return { items, load, invalidate, find, deriveContext }
})
