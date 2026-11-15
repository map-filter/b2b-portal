const TOKEN_KEY = 'b2b.token'

export class ApiError extends Error {
  status: number
  errors: Record<string, string[]>

  constructor(status: number, message: string, errors: Record<string, string[]> = {}) {
    super(message)
    this.status = status
    this.errors = errors
  }
}

export const tokenStore = {
  get: () => localStorage.getItem(TOKEN_KEY),
  set: (token: string) => localStorage.setItem(TOKEN_KEY, token),
  clear: () => localStorage.removeItem(TOKEN_KEY),
}

type Query = Record<string, string | number | boolean | string[] | Record<string, string> | undefined | null>

export function toQuery(params: Query): string {
  const search = new URLSearchParams()

  for (const [key, value] of Object.entries(params)) {
    if (value === undefined || value === null || value === '' || value === false) continue

    if (Array.isArray(value)) {
      value.forEach((item) => search.append(`${key}[]`, item))
    } else if (typeof value === 'object') {
      Object.entries(value).forEach(([inner, innerValue]) => innerValue && search.append(`${key}[${inner}]`, innerValue))
    } else {
      search.append(key, String(value))
    }
  }

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

  const response = await fetch(`/api${path}`, { ...options, headers, body })

  if (response.status === 401) {
    tokenStore.clear()
    window.dispatchEvent(new Event('b2b:unauthorized'))
  }

  if (!response.ok) {
    const payload = await response.json().catch(() => ({}))
    throw new ApiError(response.status, payload.message ?? 'Ошибка сервера', payload.errors ?? {})
  }

  if (response.status === 204) return undefined as T

  return response.json()
}
