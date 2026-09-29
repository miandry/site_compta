export type Row = Record<string, unknown>

export interface Ref {
  id: string
  label: string
}

const moneyFormatter = new Intl.NumberFormat('fr-FR', {
  minimumFractionDigits: 0,
  maximumFractionDigits: 0,
})

export function formatMoney(amount: number, currency = 'Ar'): string {
  return `${moneyFormatter.format(Number.isFinite(amount) ? amount : 0)} ${currency}`
}

/** Dates Drupal `datetime` (YYYY-MM-DDTHH:mm:ss) ou timestamps Unix. */
export function formatDate(value?: string | null): string {
  if (!value) return '—'
  const date = /^\d{9,11}$/.test(value) ? new Date(Number(value) * 1000) : new Date(value)
  if (Number.isNaN(date.getTime())) return value
  return date.toLocaleDateString('fr-FR', { day: '2-digit', month: 'short', year: 'numeric' })
}

export function todayInput(): string {
  const now = new Date()
  const pad = (n: number) => String(n).padStart(2, '0')
  return `${now.getFullYear()}-${pad(now.getMonth() + 1)}-${pad(now.getDate())}`
}

/** Valeur d'un champ `datetime` Drupal -> valeur d'un <input type="date">. */
export function toDateInput(value?: string | null): string {
  return value ? value.slice(0, 10) : ''
}

/**
 * <input type="date"> -> champ `datetime` Drupal. Midi pour éviter qu'un
 * décalage de fuseau fasse changer de jour.
 */
export function toDrupalDateTime(value: string): string {
  return `${value.slice(0, 10)}T12:00:00`
}

/** Valeur scalaire d'un champ renvoyé par entity_parser (string, [string], {value}). */
export function scalar(row: Row, key: string): string {
  let raw = row[key]
  if (Array.isArray(raw)) raw = raw[0]
  if (raw == null) return ''
  if (typeof raw === 'object') {
    const obj = raw as Row
    return String(obj.value ?? obj.url ?? '')
  }
  return String(raw)
}

export function num(row: Row, key: string): number {
  const n = Number(scalar(row, key))
  return Number.isFinite(n) ? n : 0
}

export function bool(row: Row, key: string): boolean {
  const v = scalar(row, key)
  return v === '1' || v === 'true'
}

/**
 * Référence entity_parser : {nid|tid, title} pour un seul élément,
 * ou map { "12": {nid, title} } / tableau pour plusieurs.
 */
export function refs(row: Row, key: string): Ref[] {
  const raw = row[key]
  if (raw == null || raw === '') return []
  const one = (value: unknown): Ref | null => {
    if (value == null) return null
    if (typeof value !== 'object') return { id: String(value), label: String(value) }
    const obj = value as Row
    const id = obj.nid ?? obj.tid ?? obj.uid ?? obj.target_id ?? obj.fid
    if (id == null) return null
    return { id: String(id), label: String(obj.title ?? obj.name ?? '') }
  }
  if (Array.isArray(raw)) return raw.map(one).filter((r): r is Ref => r !== null)
  const direct = one(raw)
  if (direct) return [direct]
  return Object.values(raw as Row)
    .map(one)
    .filter((r): r is Ref => r !== null)
}

export function ref(row: Row, key: string): Ref | null {
  return refs(row, key)[0] ?? null
}

/** Premier pourcentage trouvé dans un libellé (« TVA 20 % » -> 20). */
export function parseRate(label?: string): number {
  const match = label?.match(/(\d+(?:[.,]\d+)?)\s*%/)
  return match ? Number(match[1].replace(',', '.')) : 0
}

const AVATAR_TONES = [
  'bg-rose-100 text-rose-600',
  'bg-emerald-100 text-emerald-700',
  'bg-amber-100 text-amber-700',
  'bg-sky-100 text-sky-700',
  'bg-violet-100 text-violet-700',
]

/** Initiales + couleur stable dérivées d'un libellé (listes mobiles). */
export function avatarFor(label: string) {
  const source = label.trim() || '?'
  const initials = source
    .split(/\s+/)
    .slice(0, 2)
    .map((w) => w[0]?.toUpperCase() ?? '')
    .join('')
  const hash = [...source].reduce((sum, c) => sum + c.charCodeAt(0), 0)
  return { initials, tone: AVATAR_TONES[hash % AVATAR_TONES.length] }
}

export function stripTags(value: string): string {
  return value.replace(/<[^>]+>/g, '').trim()
}

export function extractErrorMessage(error: unknown): string {
  if (!error || typeof error !== 'object') return 'Une erreur est survenue.'
  const err = error as {
    response?: { data?: { message?: string; error?: string } }
    message?: string
  }
  const body = err.response?.data
  return body?.error || body?.message || err.message || 'Une erreur est survenue.'
}
