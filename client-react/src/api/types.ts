export type Role = 'admin' | 'manager' | 'client_admin' | 'client_buyer'

export interface User {
  id: number
  name: string
  email: string
  phone: string | null
  role: Role
  role_label: string
  company: Company | null
}

export interface Company {
  id: number
  name: string
  legal_name: string
  bin: string
  legal_address: string | null
  credit_limit: string
  payment_delay_days: number
  manager: { name: string; email: string; phone: string | null } | null
  contract: { number: string; ends_at: string | null; discount_percent: string; price_type: string } | null
}

export interface Category {
  id: number
  name: string
  slug: string
  products_count: number | null
  children?: Category[]
}

export interface Product {
  id: number
  sku: string
  name: string
  slug: string
  brand: string | null
  unit: string
  min_quantity: number
  pack_quantity: number
  base_price: string
  price: string
  stock?: number
  category?: { id: number; name: string }
  specs: Record<string, string | number>
  description?: string | null
  warehouses?: { name: string; quantity: number }[]
}

export interface Paginated<T> {
  data: T[]
  meta: { current_page: number; last_page: number; total: number; per_page: number }
}

export interface Facets {
  brands: string[]
  specs: Record<string, string[]>
  total: number
}

export interface CartItem {
  product: Product
  quantity: number
  total: string
  warnings: string[]
}

export interface Cart {
  items: CartItem[]
  total: string
  count: number
}

export type OrderStatus = 'new' | 'confirmed' | 'assembling' | 'shipped' | 'completed' | 'cancelled'

export interface OrderItem {
  product_id: number
  sku: string
  name: string
  unit: string
  quantity: number
  price: string
  total: string
}

export interface Order {
  id: number
  number: string
  status: OrderStatus
  status_label: string
  total: string
  items_count?: number
  comment: string | null
  delivery_address: string | null
  desired_date: string | null
  created_at: string
  author?: string
  contract?: string
  items?: OrderItem[]
  history?: { status: OrderStatus; status_label: string; by: string | null; comment: string | null; at: string }[]
}
