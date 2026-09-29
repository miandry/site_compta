import { api, buildListParams } from './api'
import type { Filter } from './api'
import type { BundleDef, BundleName, DeriveContext, FormValues } from '@/schema'
import { BUNDLES } from '@/schema'
import { bool, num, refs, scalar, toDateInput, toDrupalDateTime } from '@/utils/format'
import type { Row } from '@/utils/format'

const SAVE = '/api_solutions/save'
const UPLOAD = '/api_solutions/action/uploader'
const listUrl = (bundle: BundleName) => `/api_solutions/api/v2/node/${bundle}`

export interface FileRef {
  id: string
  url: string
}

export interface EntityRecord {
  id: string
  title: string
  created: string
  values: FormValues
  /** Libellés des références (terme / nœud) tels que renvoyés par l'API. */
  labels: Record<string, string>
  files: Record<string, FileRef[]>
}

interface ListResponse {
  rows: Row[]
  total: number | string
}

interface SaveResponse {
  item?: number | string
  status: boolean | string
  message?: string
}

export function emptyValues(def: BundleDef): FormValues {
  const values: FormValues = {}
  for (const field of def.fields) {
    switch (field.kind) {
      case 'money':
      case 'number':
        values[field.key] = ''
        break
      case 'boolean':
        values[field.key] = true
        break
      case 'image':
        values[field.key] = []
        break
      case 'options':
        values[field.key] = field.required && field.options ? Object.keys(field.options)[0] : ''
        break
      default:
        values[field.key] = ''
    }
  }
  return values
}

function imageRefs(row: Row, key: string): FileRef[] {
  const raw = row[key]
  if (!raw || typeof raw !== 'object') return []
  const list = Array.isArray(raw) ? raw : 'target_id' in raw ? [raw] : Object.values(raw)
  return list
    .filter((item): item is Row => Boolean(item) && typeof item === 'object')
    .map((item) => ({ id: String(item.target_id ?? item.fid ?? ''), url: String(item.url ?? item.image ?? '') }))
    .filter((item) => item.id)
}

export function normalize(def: BundleDef, row: Row): EntityRecord {
  const values: FormValues = {}
  const labels: Record<string, string> = {}
  const files: Record<string, FileRef[]> = {}

  for (const field of def.fields) {
    const key = field.key
    switch (field.kind) {
      case 'money':
      case 'number':
        values[key] = scalar(row, key) === '' ? '' : num(row, key)
        break
      case 'date':
        values[key] = toDateInput(scalar(row, key))
        break
      case 'boolean':
        values[key] = bool(row, key)
        break
      case 'term':
      case 'node': {
        const first = refs(row, key)[0]
        values[key] = first?.id ?? ''
        if (first) labels[key] = first.label
        break
      }
      case 'image':
        files[key] = imageRefs(row, key)
        values[key] = files[key].map((f) => f.id)
        break
      default:
        values[key] = scalar(row, key)
    }
  }

  return {
    id: scalar(row, 'nid'),
    title: scalar(row, 'title'),
    created: scalar(row, 'created'),
    values,
    labels,
    files,
  }
}

export function toPayload(def: BundleDef, values: FormValues, ctx: DeriveContext, id?: string) {
  const payload: Record<string, unknown> = {
    entity_type: 'node',
    bundle: def.bundle,
    status: 1,
    title: def.titleFrom ? def.titleFrom(values, ctx) : String(values.title ?? '').trim(),
  }
  if (id) payload.nid = Number(id)

  for (const field of def.fields) {
    if (field.key === 'title') continue
    const value = values[field.key]
    const filled = value !== '' && value !== null && value !== undefined
    switch (field.kind) {
      case 'money':
      case 'number':
        if (filled) payload[field.key] = Math.round(Number(value))
        break
      case 'date':
        if (filled) payload[field.key] = toDrupalDateTime(String(value))
        break
      case 'boolean':
        payload[field.key] = value ? 1 : 0
        break
      // Une référence vide n'est jamais envoyée : api_solutions créerait un terme/nœud sans nom.
      case 'term':
      case 'node':
      case 'options':
        if (filled) payload[field.key] = field.kind === 'options' ? value : Number(value)
        break
      case 'image':
        if (Array.isArray(value) && value.length) payload[field.key] = value.map(Number)
        break
      default:
        payload[field.key] = String(value ?? '')
    }
  }
  return payload
}

export interface ListQuery {
  page?: number
  limit?: number | 'all'
  search?: string
  filters?: Record<string, string>
  /** Période inclusive sur un champ date (AAAA-MM-JJ) ; une borne vide = ouverte. */
  dateRange?: { key: string; from?: string; to?: string }
  fields?: string[]
  sortField?: string
  sortOrder?: 'ASC' | 'DESC'
}

export async function listEntities(bundle: BundleName, query: ListQuery = {}) {
  const def = BUNDLES[bundle]
  const filters: Record<string, Filter> = { status: { val: 1 } }
  for (const [key, value] of Object.entries(query.filters ?? {})) {
    if (value !== '' && value != null) filters[key] = { val: value }
  }
  if (query.search) filters.title = { val: query.search, op: 'CONTAINS' }
  const range = query.dateRange
  if (range && (range.from || range.to)) {
    let [from, to] = [range.from, range.to]
    if (from && to && from > to) [from, to] = [to, from]
    // Borne haute en fin de journée : couvre aussi les champs datetime (AAAA-MM-JJTHH:MM:SS).
    const end = to ? `${to}T23:59:59` : undefined
    filters[range.key] =
      from && end ? { val: [from, end], op: 'BETWEEN' } : from ? { val: from, op: '>=' } : { val: end!, op: '<=' }
  }

  const params = buildListParams({
    page: query.page,
    limit: query.limit,
    sortField: query.sortField ?? def.sortField,
    sortOrder: query.sortOrder ?? def.sortOrder,
    filters,
    fields: query.fields ?? ['nid', 'title', 'created', ...def.fields.map((f) => f.key).filter((k) => k !== 'title')],
  })
  const { data } = await api.get<ListResponse>(`${listUrl(bundle)}?${params}`)
  const rows = Array.isArray(data.rows) ? data.rows : []
  return {
    items: rows.map((row) => normalize(def, row)),
    total: Number(data.total ?? rows.length),
  }
}

export async function getEntity(bundle: BundleName, id: string): Promise<EntityRecord | null> {
  const { items } = await listEntities(bundle, { limit: 1, filters: { nid: id } })
  return items[0] ?? null
}

export async function saveEntity(
  bundle: BundleName,
  values: FormValues,
  ctx: DeriveContext,
  id?: string,
): Promise<string> {
  const { data } = await api.post<SaveResponse>(SAVE, toPayload(BUNDLES[bundle], values, ctx, id))
  if (data.status !== true || data.item == null) {
    throw new Error(data.message || "Échec de l'enregistrement")
  }
  return String(data.item)
}

/** Suppression douce : dépublication (status = 0), comme le reste du thème. */
export async function removeEntity(bundle: BundleName, id: string): Promise<void> {
  const { data } = await api.post<SaveResponse>(SAVE, {
    entity_type: 'node',
    bundle,
    nid: Number(id),
    status: 0,
  })
  if (data.status !== true) throw new Error(data.message || 'Échec de la suppression')
}

export async function uploadFile(file: File): Promise<FileRef> {
  const body = new FormData()
  body.append('file', file, file.name)
  const { data } = await api.post<{ status: boolean; fid?: number; url?: string; message?: string }>(
    UPLOAD,
    body,
    { headers: { 'Content-Type': 'multipart/form-data' } },
  )
  if (!data.status || !data.fid) throw new Error(data.message || "Échec de l'envoi du fichier")
  return { id: String(data.fid), url: data.url ?? '' }
}
