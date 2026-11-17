import { BUNDLES } from '@/schema'
import type { FormValues } from '@/schema'
import { api } from '@/services/api'
import { toPayload } from '@/services/entities'
import type { OutboxBulkJob, OutboxJob, OutboxRemoveJob, OutboxSaveJob } from './outbox'
import { listOutbox, putOutboxJob, removeOutboxJob } from './outbox'
import { isOnline } from './network'

const SAVE = '/api_solutions/save'

interface SaveResponse {
  item?: number | string
  status: boolean | string
  message?: string
}

type SyncListener = (state: { syncing: boolean; pending: number; lastError?: string }) => void

let syncing = false
const listeners = new Set<SyncListener>()

export function onSyncState(cb: SyncListener): () => void {
  listeners.add(cb)
  return () => listeners.delete(cb)
}

function emit(pending: number, lastError?: string) {
  for (const cb of listeners) cb({ syncing, pending, lastError })
}

async function postSave(payload: Record<string, unknown>): Promise<string> {
  const { data } = await api.post<SaveResponse>(SAVE, payload)
  if (data.status !== true || data.item == null) {
    throw new Error(data.message || "Échec de l'enregistrement")
  }
  return String(data.item)
}

async function flushSave(job: OutboxSaveJob): Promise<void> {
  const def = BUNDLES[job.bundle]
  const ctx = {
    termLabel: () => '',
    lookup: () => undefined,
  }
  const payload = toPayload(def, job.values, ctx, job.serverId)
  // Title already computed when enqueued.
  if (job.title) payload.title = job.title
  await postSave(payload)
  await removeOutboxJob(job.localId)
}

async function flushRemove(job: OutboxRemoveJob): Promise<void> {
  await postSave({
    entity_type: 'node',
    bundle: job.bundle,
    nid: Number(job.serverId),
    status: 0,
  })
  await removeOutboxJob(job.localId)
}

async function flushBulk(job: OutboxBulkJob): Promise<void> {
  const ctx = { termLabel: () => '', lookup: () => undefined }
  for (const id of job.removeIds) {
    await postSave({ entity_type: 'node', bundle: 'operation', nid: Number(id), status: 0 })
  }

  const snapshots: Array<{ id: string; v: number; l: string; d: string; p: string; c: string; k: string }> = []
  for (const line of job.lines) {
    const values: FormValues = {
      ...job.shared,
      field_mouvement_argent: line.mouvement,
      field_amount: line.amount,
      field_label: line.label,
      field_operation_type: '',
      field_method_payment: '',
      field_image_prof: [],
    }
    const id = await postSave(toPayload(BUNDLES.operation, values, ctx))
    const signed = line.mouvement === 'Sortie' ? -Math.abs(line.amount) : Math.abs(line.amount)
    snapshots.push({
      id,
      v: signed,
      l: line.label,
      d: String(job.shared.field_operation_date || ''),
      p: String(job.shared.field_person || ''),
      c: String(job.shared.field_category || ''),
      k: String(job.shared.field_caisse || ''),
    })
  }

  const net = snapshots.reduce((s, x) => s + x.v, 0)
  const lotValues: FormValues = {
    ...job.shared,
    field_lot_lines: job.text,
    field_lot_total: net,
    field_lot_operations: JSON.stringify(snapshots),
  }
  await postSave(toPayload(BUNDLES.operation_lot, lotValues, ctx, job.lotServerId))
  await removeOutboxJob(job.localId)
}

async function flushOne(job: OutboxJob): Promise<void> {
  job.status = 'syncing'
  await putOutboxJob(job)
  try {
    if (job.kind === 'save') await flushSave(job)
    else if (job.kind === 'remove') await flushRemove(job)
    else await flushBulk(job)
  }
  catch (err) {
    job.status = 'error'
    job.lastError = err instanceof Error ? err.message : String(err)
    await putOutboxJob(job)
    throw err
  }
}

/** Replay the outbox in order. Safe to call often; concurrent calls coalesce. */
export async function flushOutbox(): Promise<{ flushed: number; pending: number; error?: string }> {
  if (!isOnline() || syncing) {
    const pending = (await listOutbox()).length
    return { flushed: 0, pending }
  }
  syncing = true
  emit((await listOutbox()).length)
  let flushed = 0
  let error: string | undefined
  try {
    // Re-read each round so newly queued items wait for the next flush.
    for (;;) {
      const jobs = (await listOutbox()).filter((j) => j.status === 'pending' || j.status === 'error')
      if (!jobs.length || !isOnline()) break
      const job = jobs[0]
      try {
        await flushOne(job)
        flushed++
      }
      catch (err) {
        error = err instanceof Error ? err.message : String(err)
        break
      }
    }
  }
  finally {
    syncing = false
    const pending = (await listOutbox()).length
    emit(pending, error)
  }
  return { flushed, pending: (await listOutbox()).length, error }
}
