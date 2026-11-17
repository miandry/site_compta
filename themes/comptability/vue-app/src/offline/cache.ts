import type { BundleName, LookupItem } from '@/schema'
import type { User } from '@/services/auth.service'
import type { EntityRecord } from '@/services/entities'
import type { Term } from '@/services/terms'
import type { Vocabulary } from '@/schema'
import { idbGet, idbSet } from './idb'

const USER_KEY = 'user'

export async function cacheUser(user: User | null): Promise<void> {
  await idbSet('meta', USER_KEY, user)
}

export async function getCachedUser(): Promise<User | null> {
  return (await idbGet<User | null>('meta', USER_KEY)) ?? null
}

export async function cacheTerms(vocabulary: Vocabulary, terms: Term[]): Promise<void> {
  await idbSet('terms', vocabulary, terms)
}

export async function getCachedTerms(vocabulary: Vocabulary): Promise<Term[] | undefined> {
  return idbGet<Term[]>('terms', vocabulary)
}

export async function cacheLookups(bundle: BundleName, items: LookupItem[]): Promise<void> {
  await idbSet('lookups', bundle, items)
}

export async function getCachedLookups(bundle: BundleName): Promise<LookupItem[] | undefined> {
  return idbGet<LookupItem[]>('lookups', bundle)
}

export interface CachedList {
  items: EntityRecord[]
  total: number
  savedAt: string
}

export function listCacheKey(bundle: BundleName): string {
  return `${bundle}:latest`
}

export async function cacheEntityList(bundle: BundleName, items: EntityRecord[], total: number): Promise<void> {
  // Only keep a recent snapshot for offline browsing (first page / latest).
  await idbSet('lists', listCacheKey(bundle), {
    items,
    total,
    savedAt: new Date().toISOString(),
  } satisfies CachedList)
}

export async function getCachedEntityList(bundle: BundleName): Promise<CachedList | undefined> {
  return idbGet<CachedList>('lists', listCacheKey(bundle))
}
