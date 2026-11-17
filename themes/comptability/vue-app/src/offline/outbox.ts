import type { BundleName, FormValues } from '@/schema'
import type { EntityRecord } from '@/services/entities'
import { idbDelete, idbGet, idbGetAll, idbSet } from './idb'

export type OutboxStatus = 'pending' | 'syncing' | 'error'

export interface OutboxSaveJob {
  kind: 'save'
  localId: string
  bundle: BundleName
  values: FormValues
  /** Server nid when updating an existing node; omitted for create. */
  serverId?: string
  title: string
  labels: Record<string, string>
  createdAt: string
  status: OutboxStatus
  lastError?: string
}

export interface OutboxRemoveJob {
  kind: 'remove'
  localId: string
  bundle: BundleName
  serverId: string
  title: string
  createdAt: string
  status: OutboxStatus
  lastError?: string
}

/** One saisie multiple replayed as deletes → ops → lot when back online. */
export interface OutboxBulkJob {
  kind: 'bulk'
  localId: string
  shared: FormValues
  lines: Array<{ amount: number; label: string; mouvement: string }>
  text: string
  lotServerId?: string
  removeIds: string[]
  createdAt: string
  status: OutboxStatus
  lastError?: string
  title: string
}

export type OutboxJob = OutboxSaveJob | OutboxRemoveJob | OutboxBulkJob

export function isOfflineId(id?: string | null): boolean {
  return Boolean(id && String(id).startsWith('offline:'))
}

export function newOfflineId(): string {
  return `offline:${crypto.randomUUID()}`
}

export async function listOutbox(): Promise<OutboxJob[]> {
  const rows = await idbGetAll<OutboxJob>('outbox')
  return rows
    .map((r) => r.value)
    .sort((a, b) => a.createdAt.localeCompare(b.createdAt))
}

export async function getOutboxJob(localId: string): Promise<OutboxJob | undefined> {
  return idbGet<OutboxJob>('outbox', localId)
}

export async function putOutboxJob(job: OutboxJob): Promise<void> {
  await idbSet('outbox', job.localId, job)
}

export async function removeOutboxJob(localId: string): Promise<void> {
  await idbDelete('outbox', localId)
}

export async function pendingCount(): Promise<number> {
  return (await listOutbox()).filter((j) => j.status !== 'syncing').length
}

/** Pending creates/edits for a bundle, as list rows. */
export async function pendingRecords(bundle: BundleName): Promise<EntityRecord[]> {
  const jobs = await listOutbox()
  return jobs
    .filter((j): j is OutboxSaveJob => j.kind === 'save' && j.bundle === bundle)
    .map((j) => ({
      id: j.localId,
      title: j.title || 'En attente de sync',
      created: j.createdAt,
      values: { ...j.values },
      labels: { ...j.labels },
      files: {},
    }))
}

export async function enqueueSave(input: {
  bundle: BundleName
  values: FormValues
  serverId?: string
  localId?: string
  title: string
  labels: Record<string, string>
}): Promise<string> {
  const localId = input.localId && isOfflineId(input.localId) ? input.localId : newOfflineId()
  const existing = await getOutboxJob(localId)
  const job: OutboxSaveJob = {
    kind: 'save',
    localId,
    bundle: input.bundle,
    values: { ...input.values },
    serverId: input.serverId || (existing && existing.kind === 'save' ? existing.serverId : undefined),
    title: input.title,
    labels: { ...input.labels },
    createdAt: existing?.createdAt ?? new Date().toISOString(),
    status: 'pending',
  }
  await putOutboxJob(job)
  return localId
}

export async function enqueueRemove(input: {
  bundle: BundleName
  serverId: string
  title: string
}): Promise<string> {
  const localId = newOfflineId()
  await putOutboxJob({
    kind: 'remove',
    localId,
    bundle: input.bundle,
    serverId: input.serverId,
    title: input.title,
    createdAt: new Date().toISOString(),
    status: 'pending',
  })
  return localId
}

export async function enqueueBulk(input: {
  shared: FormValues
  lines: Array<{ amount: number; label: string; mouvement: string }>
  text: string
  lotServerId?: string
  removeIds: string[]
  title: string
}): Promise<string> {
  const localId = newOfflineId()
  await putOutboxJob({
    kind: 'bulk',
    localId,
    shared: { ...input.shared },
    lines: input.lines.map((l) => ({ ...l })),
    text: input.text,
    lotServerId: input.lotServerId,
    removeIds: [...input.removeIds],
    createdAt: new Date().toISOString(),
    status: 'pending',
    title: input.title,
  })
  return localId
}
