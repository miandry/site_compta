import axios from 'axios'
import type { AxiosError } from 'axios'

export const TOKEN_KEY = 'api_solutions_token'

const baseURL = window.drupalSettings?.path?.baseUrl || '/'

/**
 * Même origine que Drupal : le cookie HTTP-Only `auth_token` posé par
 * /api_solutions/user/login suffit. Le Bearer et le `token` dans le corps
 * restent en secours (MAMP/Apache supprime souvent l'en-tête Authorization).
 */
export const api = axios.create({
  baseURL,
  withCredentials: true,
  headers: {
    'Content-Type': 'application/json',
    Accept: 'application/json',
  },
})

const AUTH_ENDPOINTS = ['/user/login', '/user/register', '/user/forgot-password']

api.interceptors.request.use((config) => {
  if (AUTH_ENDPOINTS.some((path) => config.url?.includes(path))) return config
  const token = getStoredToken()
  if (!token) return config
  config.headers.Authorization = `Bearer ${token}`
  if (config.url?.endsWith('/save') && config.data && typeof config.data === 'object') {
    config.data = { token, ...config.data }
  }
  return config
})

api.interceptors.response.use(
  (response) => response,
  (error: AxiosError) => {
    const url = error.config?.url ?? ''
    if (error.response?.status === 401 && !AUTH_ENDPOINTS.some((path) => url.includes(path))) {
      clearAuthStorage()
      if (!window.location.hash.includes('/connexion')) {
        window.location.hash = '#/connexion'
      }
    }
    return Promise.reject(error)
  },
)

export function getStoredToken(): string | null {
  return localStorage.getItem(TOKEN_KEY)
}

export function setStoredToken(token: string) {
  localStorage.setItem(TOKEN_KEY, token)
}

export function clearAuthStorage() {
  localStorage.removeItem(TOKEN_KEY)
}

export interface Filter {
  val: string | number | Array<string | number>
  op?: string
}

export interface ListOptions {
  page?: number
  limit?: number | 'all'
  sortField?: string
  sortOrder?: 'ASC' | 'DESC'
  filters?: Record<string, Filter>
  fields?: string[]
}

/**
 * Paramètres de /api_solutions/api/v2/{entity}/{bundle} :
 * offset = taille de page, pager = index de page (0-based) ou "all".
 */
export function buildListParams(options: ListOptions): URLSearchParams {
  const params = new URLSearchParams()
  if (options.limit === 'all') {
    params.set('pager', 'all')
  } else {
    const limit = options.limit ?? 20
    const page = options.page ?? 1
    params.set('offset', String(limit))
    if (page > 1) params.set('pager', String(page - 1))
  }
  if (options.sortField) {
    params.set('sort[val]', options.sortField)
    params.set('sort[op]', options.sortOrder ?? 'DESC')
  }
  for (const [field, filter] of Object.entries(options.filters ?? {})) {
    if (Array.isArray(filter.val)) {
      filter.val.forEach((v) => params.append(`filters[${field}][val][]`, String(v)))
      params.set(`filters[${field}][op]`, filter.op ?? 'IN')
    } else {
      params.set(`filters[${field}][val]`, String(filter.val))
      if (filter.op) params.set(`filters[${field}][op]`, filter.op)
    }
  }
  options.fields?.forEach((field) => params.append('fields[]', field))
  return params
}
