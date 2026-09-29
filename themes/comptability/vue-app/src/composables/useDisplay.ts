import type { BundleDef, FieldDef } from '@/schema'
import { fieldDef, isDebitLabel } from '@/schema'
import type { EntityRecord } from '@/services/entities'
import { useLookupsStore } from '@/stores/lookups'
import { useTermsStore } from '@/stores/terms'
import { formatDate, formatMoney } from '@/utils/format'

/** Libellé affichable d'un champ (termes et nœuds résolus via les stores). */
export function useDisplay() {
  const lookups = useLookupsStore()
  const terms = useTermsStore()

  function text(field: FieldDef, record: EntityRecord): string {
    const v = record.values[field.key]
    if (field.key === 'title') return record.title
    switch (field.kind) {
      case 'money':
        return v === '' ? '' : formatMoney(Number(v))
      case 'date':
        return v ? formatDate(String(v)) : ''
      case 'options':
        return field.options?.[String(v)] ?? (v ? String(v) : '')
      case 'term':
        return (field.vocabulary && terms.label(field.vocabulary, String(v))) || record.labels[field.key] || ''
      case 'node':
        return (field.target && lookups.find(field.target, String(v))?.label) || record.labels[field.key] || ''
      case 'image':
        return Array.isArray(v) && v.length ? (field.multiple === false ? 'Oui' : `${v.length} image(s)`) : ''
      default:
        return v == null ? '' : String(v)
    }
  }

  /** +1 entrée, -1 sortie, 0 si le bundle n'a pas de sens. */
  function sign(def: BundleDef, record: EntityRecord): 1 | -1 | 0 {
    if (!def.directionKey) return 0
    return isDebitLabel(text(fieldDef(def, def.directionKey), record)) ? -1 : 1
  }

  /** Montant avec signe + / − et classe de couleur associée. */
  function signedMoney(def: BundleDef, record: EntityRecord, amount: number) {
    const s = sign(def, record)
    if (!s) return { text: formatMoney(amount), tone: 'text-neu-dark' }
    return {
      text: `${s > 0 ? '+' : '−'}${formatMoney(amount)}`,
      tone: s > 0 ? 'text-emerald-700' : 'text-rose-600',
    }
  }

  return { text, sign, signedMoney }
}
