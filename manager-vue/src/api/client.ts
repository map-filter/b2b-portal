const API_BASE = `${import.meta.env.VITE_APP_BASE ?? '/'}api`
const TOKEN_KEY = 'b2b.manager.token'

export class ApiError extends Error {
  status: number
  errors: Record<string, string[]>

  constructor(status: number, message: string, errors: Record<string, string[]> = {}) {
    super(message)
    this.status = status
    this.errors = errors
  }

  get first(): string {
    return Object.values(this.errors)[0]?.[0] ?? this.message
  }
}

export const tokenStore = {
  get: () => localStorage.getItem(TOKEN_KEY),
  set: (token: string) => localStorage.setItem(TOKEN_KEY, token),
  clear: () => localStorage.removeItem(TOKEN_KEY),
}

export function toQuery(params: Record<string, string | number | boolean | undefined | null>): string {
  const search = new URLSearchParams()
  Object.entries(params).forEach(([key, value]) => {
    if (value !== undefined && value !== null && value !== '' && value !== false)
      search.append(key, String(value === true ? 1 : value))
  })
  const result = search.toString()
  return result ? `?${result}` : ''
}

export async function api<T>(path: string, options: RequestInit & { json?: unknown } = {}): Promise<T> {
  const headers = new Headers(options.headers)
  headers.set('Accept', 'application/json')
  const token = tokenStore.get()
  if (token) headers.set('Authorization', `Bearer ${token}`)

  let body = options.body
  if (options.json !== undefined) {
    headers.set('Content-Type', 'application/json')
    body = JSON.stringify(options.json)
  }

  const response = await fetch(`${API_BASE}${path}`, { ...options, headers, body })

  if (response.status === 401) {
    tokenStore.clear()
    window.dispatchEvent(new Event('b2b:unauthorized'))
  }

  if (!response.ok) {
    const payload = await response.json().catch(() => ({}))
    throw new ApiError(response.status, payload.message ?? 'Ошибка сервера', payload.errors ?? {})
  }

  return response.status === 204 ? (undefined as T) : response.json()
}

export async function download(path: string, filename: string) {
  const response = await fetch(`${API_BASE}${path}`, { headers: { Authorization: `Bearer ${tokenStore.get()}` } })
  if (!response.ok) throw new ApiError(response.status, 'Не удалось скачать файл')
  const url = URL.createObjectURL(await response.blob())
  Object.assign(document.createElement('a'), { href: url, download: filename }).click()
  URL.revokeObjectURL(url)
}
