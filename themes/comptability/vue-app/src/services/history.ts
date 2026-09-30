import { api } from './api'
import type { BundleName } from '@/schema'

export interface RevisionEntry {
  vid: number
  date: string
  user: string
  message: string
  amount: number | null
  mouvement: string | null
}

/** Révisions d'un contenu, la plus récente en premier (/comptabilty/api/{bundle}/{id}/revisions). */
export async function fetchHistory(bundle: BundleName, id: string): Promise<RevisionEntry[]> {
  const { data } = await api.get<{ rows?: RevisionEntry[] }>(`/comptabilty/api/${bundle}/${id}/revisions`)
  return Array.isArray(data.rows) ? data.rows : []
}
