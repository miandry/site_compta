import { api } from './api'
import type { Vocabulary } from '@/schema'
import { num, scalar, stripTags } from '@/utils/format'
import type { Row } from '@/utils/format'

export interface Term {
  id: string
  name: string
  description: string
  weight: number
}

interface SaveResponse {
  item?: number | string
  status: boolean | string
  message?: string
}

export async function fetchTerms(vocabulary: Vocabulary): Promise<Term[]> {
  const { data } = await api.get<Row[]>(`/api_solutions/api/v1/term/${vocabulary}`)
  const rows = Array.isArray(data) ? data : []
  return rows
    .filter((row) => scalar(row, 'status') !== '0')
    .map((row) => ({
      id: scalar(row, 'tid'),
      name: scalar(row, 'name'),
      description: stripTags(scalar(row, 'description')),
      weight: num(row, 'weight'),
    }))
}

export async function saveTerm(
  vocabulary: Vocabulary,
  payload: { name: string; description: string },
  id?: string,
): Promise<string> {
  const { data } = await api.post<SaveResponse>('/api_solutions/save', {
    entity_type: 'taxonomy_term',
    bundle: vocabulary,
    ...(id ? { tid: Number(id) } : {}),
    name: payload.name.trim(),
    description: payload.description,
    status: 1,
  })
  if (data.status !== true || data.item == null) {
    throw new Error(data.message || "Échec de l'enregistrement du terme")
  }
  return String(data.item)
}

export async function removeTerm(vocabulary: Vocabulary, id: string): Promise<void> {
  const { data } = await api.post<SaveResponse>('/api_solutions/save', {
    entity_type: 'taxonomy_term',
    bundle: vocabulary,
    tid: Number(id),
    status: 0,
  })
  if (data.status !== true) throw new Error(data.message || 'Échec de la suppression du terme')
}
