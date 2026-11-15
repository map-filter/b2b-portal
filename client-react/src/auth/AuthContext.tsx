import { useQuery, useQueryClient } from '@tanstack/react-query'
import { useCallback, useEffect, type ReactNode } from 'react'
import { api, tokenStore } from '../api/client'
import type { User } from '../api/types'

import { AuthContext } from './context'

export function AuthProvider({ children }: { children: ReactNode }) {
  const client = useQueryClient()

  const { data: user, isLoading } = useQuery({
    queryKey: ['me'],
    queryFn: () => (tokenStore.get() ? api<{ data: User }>('/auth/me').then((r) => r.data) : null),
    retry: false,
    staleTime: Infinity,
  })

  useEffect(() => {
    const reset = () => client.setQueryData(['me'], null)
    window.addEventListener('b2b:unauthorized', reset)
    return () => window.removeEventListener('b2b:unauthorized', reset)
  }, [client])

  const login = useCallback(
    async (email: string, password: string) => {
      const result = await api<{ token: string; user: User }>('/auth/login', {
        method: 'POST',
        json: { email, password, device: 'client-web' },
      })
      tokenStore.set(result.token)
      client.removeQueries({ predicate: (query) => query.queryKey[0] !== 'me' })
      client.setQueryData(['me'], result.user)
      return result.user
    },
    [client],
  )

  const logout = useCallback(async () => {
    await api('/auth/logout', { method: 'POST' }).catch(() => undefined)
    tokenStore.clear()
    client.setQueryData(['me'], null)
    client.removeQueries({ predicate: (query) => query.queryKey[0] !== 'me' })
  }, [client])

  return <AuthContext.Provider value={{ user: user ?? null, loading: isLoading, login, logout }}>{children}</AuthContext.Provider>
}
