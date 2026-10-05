import { api } from './api'

/** Fournisseurs IA du module Drupal mz_api_integration. */
export type MzAiProviderId = 'gemini' | 'claude' | 'chatgpt'

export interface MzAiUsage {
  requests: number
  input_tokens: number
  output_tokens: number
  cost_usd: number
  cost_usd_formatted: string
  last_request: number | null
  last_request_label: string | null
}

export interface MzAiProvider {
  id: MzAiProviderId
  label: string
  model: string
  configured: boolean
  usage: MzAiUsage
}

export interface MzAiSettings {
  status: boolean
  message?: string
  ai_provider: MzAiProviderId
  /** Fournisseur imposé par $settings['mz_ai_provider'] dans settings.php. */
  locked_by_settings: boolean
  providers: MzAiProvider[]
  usage_note: string
}

const URL = '/api_solutions/mz_api_integration/ai-settings'

export async function fetchMzAiSettings(): Promise<MzAiSettings> {
  const { data } = await api.get<MzAiSettings>(URL)
  return data
}

export async function saveMzAiProvider(provider: MzAiProviderId): Promise<MzAiSettings> {
  const { data } = await api.post<MzAiSettings>(URL, { ai_provider: provider })
  return data
}
