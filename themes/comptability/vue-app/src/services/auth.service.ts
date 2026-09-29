import { api, clearAuthStorage, getStoredToken, setStoredToken } from './api'

export interface User {
  id: string
  name: string
  email: string
  roles: string[]
}

interface LoginResponse {
  status: boolean
  message?: string
  error?: string
  token?: string
  id?: number | string
  name?: string
  mail?: string
  roles?: string[]
}

interface CheckAuthResponse {
  authenticated: boolean
  message?: string
  user?: { id: number | string; name: string; mail: string; roles: string[] }
}

export async function login(username: string, password: string): Promise<User> {
  const { data } = await api.post<LoginResponse>('/api_solutions/user/login', {
    name: username.trim(),
    pass: password,
  })
  if (!data.status || !data.token) {
    throw Object.assign(new Error(data.error || data.message || 'Échec de la connexion'), {
      response: { data },
    })
  }
  setStoredToken(data.token)
  return {
    id: String(data.id ?? ''),
    name: data.name ?? '',
    email: data.mail ?? '',
    roles: data.roles ?? [],
  }
}

export async function logout(): Promise<void> {
  try {
    await api.post('/api_solutions/user/logout')
  } catch {
    // Le cookie est supprimé côté serveur ; on nettoie le local quoi qu'il arrive.
  } finally {
    clearAuthStorage()
  }
}

export async function fetchCurrentUser(): Promise<User | null> {
  const token = getStoredToken()
  const { data } = await api.get<CheckAuthResponse>('/api_solutions/user/check-auth', {
    params: token ? { token } : undefined,
  })
  if (!data.authenticated || !data.user) return null
  return {
    id: String(data.user.id),
    name: data.user.name,
    email: data.user.mail,
    roles: data.user.roles ?? [],
  }
}
