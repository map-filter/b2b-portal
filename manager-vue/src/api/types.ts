export type OrderStatus = 'new' | 'confirmed' | 'assembling' | 'shipped' | 'completed' | 'cancelled'

export interface User {
  id: number
  name: string
  email: string
  role: 'admin' | 'manager' | 'client_admin' | 'client_buyer'
  role_label: string
  telegram_linked: boolean
  telegram_available: boolean
}

export interface Paginated<T> {
  data: T[]
  meta: { current_page: number; last_page: number; total: number }
}

export interface Order {
  id: number
  number: string
  status: OrderStatus
  status_label: string
  next_statuses: { value: OrderStatus; label: string }[]
  total: string
  items_count?: number
  comment: string | null
  delivery_address: string | null
  desired_date: string | null
  created_at: string
  author?: string
  company?: { id: number; name: string }
  contract?: string
  crm_deal_id?: string | null
  items?: { product_id: number; sku: string; name: string; unit: string; quantity: number; price: string; total: string }[]
  history?: { status: OrderStatus; status_label: string; by: string | null; comment: string | null; at: string }[]
}

export interface Contract {
  id: number
  number: string
  price_type_id: number
  price_type: string
  discount_percent: string
  starts_at: string
  ends_at: string | null
}

export interface Company {
  id: number
  name: string
  legal_name: string
  bin: string
  legal_address: string | null
  phone: string | null
  email: string | null
  is_active: boolean
  credit_limit: string
  payment_delay_days: number
  manager: { id: number; name: string } | null
  contract: Contract | null
  users_count: number | null
  open_orders: number
  revenue: string
  last_order_at: string | null
}

export interface CompanyDetails extends Company {
  open_total: string
  users: { id: number; name: string; email: string; phone: string | null; role: string; role_label: string; is_active: boolean }[]
  price_types: { id: number; name: string; code: string }[]
  managers: { id: number; name: string }[]
}

export interface ContractPrice {
  product_id: number
  sku: string
  name: string
  unit: string
  base_price: string
  standard_price: string
  price: string
}

export interface Dashboard {
  period: { from: string; to: string }
  summary: { orders: number; revenue: string; average: string; companies: number; previous_revenue: string; awaiting: number }
  daily: { date: string; revenue: string }[]
  by_status: { status: OrderStatus; label: string; orders: number; revenue: string }[]
  top_products: { product_id: number; sku: string; name: string; quantity: number; unit: string; revenue: string }[]
  top_companies: { id: number; name: string; orders: number; revenue: string }[]
}

export interface ProductLookup {
  id: number
  sku: string
  name: string
  unit: string
  base_price: string
}
