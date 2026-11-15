export type KaspiState = 'NEW' | 'SIGN_REQUIRED' | 'PICKUP' | 'DELIVERY' | 'KASPI_DELIVERY' | 'ARCHIVE'
export type KaspiAction = 'accept' | 'cancel' | 'assemble' | 'send_code' | 'complete'
export type KaspiMode = 'one_c' | 'standalone'

export interface KaspiOrder {
  id: number
  code: string
  state: KaspiState
  state_label: string
  status: string
  status_label: string
  delivery: 'pickup' | 'kaspi_delivery' | 'own_delivery'
  delivery_label: string
  express: boolean
  payment_mode: string | null
  credit_term: number | null
  total: string
  delivery_cost_for_seller: string
  customer_name: string | null
  customer_phone: string | null
  delivery_address: string | null
  store_id: string | null
  created_at: string
  planned_delivery_at: string | null
  courier_planned_at: string | null
  courier_transmitted_at: string | null
  waybill_number: string | null
  number_of_space: number | null
  has_waybill: boolean
  cancellation: string | null
  cancellation_comment: string | null
  code_sent_at: string | null
  problem: string | null
  items_count?: number
  actions: KaspiAction[]
  core_order?: { id: number; number: string; status: string; status_label: string } | null
  entries?: {
    id: number
    sku: string | null
    name: string
    category: string | null
    quantity: number
    price: string
    total: string
    store_id: string | null
    product: { id: number; sku: string; name: string; unit: string } | null
    stock: number | null
  }[]
  events?: { type: string; text: string; by: string | null; at: string }[]
}

export interface KaspiOrdersPage {
  data: KaspiOrder[]
  meta: {
    current_page: number
    last_page: number
    total: number
    states: { value: KaspiState; label: string; count: number }[]
    problems: number
    last_synced_at: string | null
    mode: KaspiMode
  }
}

export interface KaspiProductRow {
  id: number | null
  product_id: number
  sku: string
  name: string
  brand: string | null
  unit: string
  base_price: string
  own_price: string | null
  price: string
  price_source: '1c' | 'manual'
  is_published: boolean
  linked: boolean
  stocks: Record<string, number>
  stock_total: number
  sold_30d: number
  master_id: string | null
}

export interface KaspiProductsPage {
  data: KaspiProductRow[]
  meta: {
    current_page: number
    last_page: number
    total: number
    mode: KaspiMode
    price_from_1c: boolean
    stores: { store_id: string; name: string }[]
    counts: Record<'all' | 'out_of_stock' | 'unpublished' | 'unlinked', number>
  }
}

export interface KaspiDashboard {
  summary: {
    revenue: string
    previous_revenue: string
    orders: number
    average: string
    delivery_cost: string
    cancel_rate: number
    awaiting: number
    problems: number
  }
  daily: { date: string; orders: number; revenue: string }[]
  by_delivery: { channel: string; label: string; orders: number; revenue: string }[]
  top_products: { sku: string; name: string; product_id: number | null; quantity: number; revenue: string }[]
  out_of_stock: KaspiProductRow[]
}

export interface KaspiSyncRun {
  id: number
  trigger: string
  started_at: string
  finished_at: string | null
  requests: number
  fetched: number
  created: number
  updated: number
  error: string | null
}

export interface KaspiStoreRow {
  id?: number
  store_id: string
  name: string
  warehouse_id: number
  is_active: boolean
}

export interface KaspiSettings {
  mode: KaspiMode
  modes: { value: KaspiMode; label: string }[]
  api_url: string
  api_url_custom: string | null
  token_set: boolean
  token_hint: string | null
  merchant_id: string | null
  company_name: string | null
  price_type_id: number | null
  notify_user_id: number | null
  poll_minutes: number
  sync_days: number
  auto_accept: boolean
  is_enabled: boolean
  last_synced_at: string | null
  pricelist_url: string
  stores: KaspiStoreRow[]
  warehouses: { id: number; name: string }[]
  price_types: { id: number; name: string }[]
  staff: { id: number; name: string; telegram: boolean }[]
  runs: KaspiSyncRun[]
}
