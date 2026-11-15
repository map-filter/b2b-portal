import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { api, toQuery } from './client'
import type { Cart, Category, Facets, Order, Paginated, Product } from './types'

export interface CatalogFilters {
  q?: string
  category?: number
  brand?: string[]
  specs?: Record<string, string>
  in_stock?: boolean
  sort?: string
  page?: number
}

export const useCategories = () =>
  useQuery({
    queryKey: ['categories'],
    queryFn: () => api<{ data: Category[] }>('/catalog/categories').then((r) => r.data),
    staleTime: 5 * 60_000,
  })

export const useProducts = (filters: CatalogFilters) =>
  useQuery({
    queryKey: ['products', filters],
    queryFn: () => api<Paginated<Product>>(`/catalog/products${toQuery({ ...filters, per_page: 24 })}`),
    placeholderData: keepPreviousData,
  })

export const useFacets = (filters: Pick<CatalogFilters, 'q' | 'category' | 'in_stock'>) =>
  useQuery({
    queryKey: ['facets', filters],
    queryFn: () => api<Facets>(`/catalog/facets${toQuery(filters)}`),
    placeholderData: keepPreviousData,
  })

export const useProduct = (slug: string) =>
  useQuery({
    queryKey: ['product', slug],
    queryFn: () => api<{ data: Product }>(`/catalog/products/${slug}`).then((r) => r.data),
  })

export const useCart = () =>
  useQuery({
    queryKey: ['cart'],
    queryFn: () => api<Cart>('/cart'),
  })

export function useCartMutations() {
  const client = useQueryClient()
  const onSuccess = (cart: Cart) => client.setQueryData(['cart'], cart)

  return {
    put: useMutation({
      mutationFn: ({ productId, quantity }: { productId: number; quantity: number }) =>
        api<Cart>(`/cart/items/${productId}`, { method: 'PUT', json: { quantity } }),
      onSuccess,
    }),
    remove: useMutation({
      mutationFn: (productId: number) => api<Cart>(`/cart/items/${productId}`, { method: 'DELETE' }),
      onSuccess,
    }),
    clear: useMutation({
      mutationFn: () => api<Cart>('/cart', { method: 'DELETE' }),
      onSuccess,
    }),
  }
}

export const useOrders = (params: { status?: string; q?: string; page?: number }) =>
  useQuery({
    queryKey: ['orders', params],
    queryFn: () => api<Paginated<Order>>(`/orders${toQuery(params)}`),
    placeholderData: keepPreviousData,
  })

export const useOrder = (id: string) =>
  useQuery({
    queryKey: ['order', id],
    queryFn: () => api<{ data: Order }>(`/orders/${id}`).then((r) => r.data),
  })

export function usePlaceOrder() {
  const client = useQueryClient()

  return useMutation({
    mutationFn: (details: { comment?: string; delivery_address?: string; desired_date?: string }) =>
      api<{ data: Order }>('/orders', { method: 'POST', json: details }).then((r) => r.data),
    onSuccess: () => {
      client.invalidateQueries({ queryKey: ['cart'] })
      client.invalidateQueries({ queryKey: ['orders'] })
    },
  })
}

export function useRepeatOrder() {
  const client = useQueryClient()

  return useMutation({
    mutationFn: (id: number) => api<{ added: number; skipped: string[] }>(`/orders/${id}/repeat`, { method: 'POST' }),
    onSuccess: () => client.invalidateQueries({ queryKey: ['cart'] }),
  })
}

export interface ImportResult {
  added: number
  not_found: { row: number; sku: string }[]
  invalid: { row: number; reason: string }[]
}

export function useCartImport() {
  const client = useQueryClient()

  return useMutation({
    mutationFn: (file: File) => {
      const body = new FormData()
      body.append('file', file)
      return api<ImportResult>('/cart/import', { method: 'POST', body })
    },
    onSuccess: () => client.invalidateQueries({ queryKey: ['cart'] }),
  })
}
