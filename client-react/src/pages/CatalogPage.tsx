import { useEffect, useMemo, useState, type ReactNode } from 'react'
import { Link, useSearchParams } from 'react-router'
import { useCart, useCartMutations, useCategories, useFacets, useProducts, type CatalogFilters } from '../api/hooks'
import type { Category, Product } from '../api/types'
import { QuantityInput } from '../components/QuantityInput'
import { Button, Card, Empty, Pagination, Spinner, StockBadge } from '../components/ui'
import { formatMoney } from '../lib/format'

function readFilters(params: URLSearchParams): CatalogFilters {
  const specs: Record<string, string> = {}
  params.forEach((value, key) => {
    const match = key.match(/^spec:(.+)$/)
    if (match) specs[match[1]] = value
  })

  return {
    q: params.get('q') ?? undefined,
    category: params.get('category') ? Number(params.get('category')) : undefined,
    brand: params.getAll('brand'),
    specs,
    in_stock: params.get('in_stock') === '1',
    sort: params.get('sort') ?? undefined,
    page: params.get('page') ? Number(params.get('page')) : undefined,
  }
}

export function CatalogPage() {
  const [params, setParams] = useSearchParams()
  const filters = useMemo(() => readFilters(params), [params])
  const products = useProducts(filters)
  const facets = useFacets({ q: filters.q, category: filters.category, in_stock: filters.in_stock })
  const { data: categories } = useCategories()

  const update = (mutate: (next: URLSearchParams) => void) => {
    const next = new URLSearchParams(params)
    mutate(next)
    if (!next.has('page-keep')) next.delete('page')
    next.delete('page-keep')
    setParams(next)
  }

  const selectCategory = (id?: number) =>
    update((next) => {
      ;[...next.keys()].filter((key) => key.startsWith('spec:') || key === 'brand').forEach((key) => next.delete(key))
      if (id) next.set('category', String(id))
      else next.delete('category')
    })

  const [panelOpen, setPanelOpen] = useState(false)

  const activeCategory = categories?.flatMap((root) => [root, ...(root.children ?? [])]).find((c) => c.id === filters.category)

  return (
    <div className="grid gap-6 lg:grid-cols-[240px_1fr]">
      <button
        type="button"
        onClick={() => setPanelOpen((open) => !open)}
        className="flex items-center justify-between rounded-lg bg-white px-4 py-2.5 text-sm font-medium text-slate-700 ring-1 ring-slate-200 lg:hidden"
      >
        <span>Категории и фильтры</span>
        <span className="text-slate-400">{panelOpen ? '▲' : '▼'}</span>
      </button>

      <aside className={`space-y-6 ${panelOpen ? '' : 'hidden'} lg:block`}>
        <CategoryTree categories={categories} active={filters.category} onSelect={selectCategory} />

        {facets.data &&
          (facets.data.brands.length > 1 || (filters.category !== undefined && Object.keys(facets.data.specs).length > 0)) && (
            <div className="space-y-5">
              {facets.data.brands.length > 1 && (
                <FilterGroup title="Бренд">
                  {facets.data.brands.map((brand) => (
                    <label key={brand} className="flex items-center gap-2 py-0.5 text-sm text-slate-700">
                      <input
                        type="checkbox"
                        className="rounded accent-brand-600"
                        checked={filters.brand?.includes(brand) ?? false}
                        onChange={(event) =>
                          update((next) => {
                            const current = next.getAll('brand').filter((value) => value !== brand)
                            next.delete('brand')
                            ;(event.target.checked ? [...current, brand] : current).forEach((value) =>
                              next.append('brand', value),
                            )
                          })
                        }
                      />
                      {brand}
                    </label>
                  ))}
                </FilterGroup>
              )}

              {filters.category !== undefined &&
                Object.entries(facets.data.specs).map(([name, values]) => (
                  <FilterGroup key={name} title={name}>
                    <select
                      className="w-full rounded-lg border-0 bg-white px-2 py-1.5 text-sm ring-1 ring-slate-300"
                      value={filters.specs?.[name] ?? ''}
                      onChange={(event) =>
                        update((next) =>
                          event.target.value ? next.set(`spec:${name}`, event.target.value) : next.delete(`spec:${name}`),
                        )
                      }
                    >
                      <option value="">Любое</option>
                      {values.map((value) => (
                        <option key={value} value={value}>
                          {value}
                        </option>
                      ))}
                    </select>
                  </FilterGroup>
                ))}
            </div>
          )}
      </aside>

      <section className="min-w-0 space-y-4">
        <div className="flex flex-wrap items-center gap-3">
          <SearchBox value={filters.q ?? ''} onSearch={(q) => update((next) => (q ? next.set('q', q) : next.delete('q')))} />
          <label className="flex items-center gap-2 text-sm text-slate-600">
            <input
              type="checkbox"
              className="rounded accent-brand-600"
              checked={filters.in_stock ?? false}
              onChange={(event) => update((next) => (event.target.checked ? next.set('in_stock', '1') : next.delete('in_stock')))}
            />
            В наличии
          </label>
          <select
            className="rounded-lg border-0 bg-white px-3 py-2 text-sm ring-1 ring-slate-300"
            value={filters.sort ?? 'sku'}
            onChange={(event) => update((next) => next.set('sort', event.target.value))}
          >
            <option value="sku">По порядку</option>
            <option value="name">По названию</option>
            <option value="price_asc">Сначала дешевле</option>
            <option value="price_desc">Сначала дороже</option>
          </select>
        </div>

        <div className="flex items-baseline justify-between">
          <h1 className="text-xl font-semibold text-slate-900">{activeCategory?.name ?? 'Все товары'}</h1>
          {products.data && <span className="text-sm text-slate-500">Найдено: {products.data.meta.total}</span>}
        </div>

        <Card className="overflow-hidden">
          {products.isLoading ? (
            <Spinner />
          ) : !products.data?.data.length ? (
            <Empty title="Ничего не нашлось">Попробуйте изменить фильтры или запрос</Empty>
          ) : (
            <div className={`divide-y divide-slate-100 transition ${products.isPlaceholderData ? 'opacity-60' : ''}`}>
              {products.data.data.map((product) => (
                <ProductRow key={product.id} product={product} />
              ))}
            </div>
          )}
        </Card>

        {products.data && (
          <Pagination
            page={products.data.meta.current_page}
            last={products.data.meta.last_page}
            onChange={(page) =>
              update((next) => {
                next.set('page', String(page))
                next.set('page-keep', '1')
              })
            }
          />
        )}
      </section>
    </div>
  )
}

function CategoryTree({
  categories,
  active,
  onSelect,
}: {
  categories?: Category[]
  active?: number
  onSelect: (id?: number) => void
}) {
  const item = (selected: boolean) =>
    `block w-full rounded-md px-2 py-1 text-left text-sm transition ${selected ? 'bg-brand-50 font-medium text-brand-700' : 'text-slate-600 hover:bg-slate-100'}`

  return (
    <nav className="space-y-3">
      <button className={item(!active)} onClick={() => onSelect()}>
        Все товары
      </button>
      {categories?.map((root) => (
        <div key={root.id}>
          <button className={`${item(active === root.id)} font-semibold text-slate-800`} onClick={() => onSelect(root.id)}>
            {root.name}
          </button>
          <div className="mt-0.5 ml-2 border-l border-slate-200 pl-2">
            {root.children?.map((child) => (
              <button key={child.id} className={item(active === child.id)} onClick={() => onSelect(child.id)}>
                {child.name}
                <span className="ml-1 text-xs text-slate-400">{child.products_count}</span>
              </button>
            ))}
          </div>
        </div>
      ))}
    </nav>
  )
}

function FilterGroup({ title, children }: { title: string; children: ReactNode }) {
  return (
    <div>
      <p className="mb-1.5 text-xs font-semibold tracking-wide text-slate-500 uppercase">{title}</p>
      {children}
    </div>
  )
}

function SearchBox({ value, onSearch }: { value: string; onSearch: (value: string) => void }) {
  const [draft, setDraft] = useState(value)
  const [synced, setSynced] = useState(value)

  if (synced !== value) {
    setSynced(value)
    setDraft(value)
  }

  useEffect(() => {
    if (draft === value) return
    const timer = setTimeout(() => onSearch(draft.trim()), 350)
    return () => clearTimeout(timer)
  }, [draft, value, onSearch])

  return (
    <input
      type="search"
      placeholder="Поиск по названию или артикулу"
      value={draft}
      onChange={(event) => setDraft(event.target.value)}
      className="min-w-64 flex-1 rounded-lg border-0 bg-white px-3 py-2 text-sm ring-1 ring-slate-300 outline-none focus:ring-2 focus:ring-brand-500"
    />
  )
}

function ProductRow({ product }: { product: Product }) {
  const { data: cart } = useCart()
  const { put } = useCartMutations()
  const inCart = cart?.items.find((item) => item.product.id === product.id)
  const step = product.min_quantity > 1 ? product.min_quantity : 1
  const [quantity, setQuantity] = useState(Math.max(product.min_quantity, 1))
  const discounted = Number(product.price) < Number(product.base_price)

  return (
    <div className="grid grid-cols-[1fr_auto] items-center gap-x-6 gap-y-3 px-4 py-3 md:grid-cols-[1fr_140px_150px_auto]">
      <div className="min-w-0">
        <Link to={`/product/${product.slug}`} className="font-medium text-slate-800 hover:text-brand-700">
          {product.name}
        </Link>
        <div className="mt-0.5 flex flex-wrap gap-x-3 text-xs text-slate-500">
          <span>Арт. {product.sku}</span>
          {product.brand && <span>{product.brand}</span>}
          {product.min_quantity > 1 && (
            <span>
              от {product.min_quantity} {product.unit}
            </span>
          )}
        </div>
      </div>

      <div className="hidden md:block">
        <StockBadge stock={product.stock} unit={product.unit} />
      </div>

      <div className="text-right">
        <div className="font-semibold text-slate-900 tabular-nums">{formatMoney(product.price)}</div>
        {discounted && <div className="text-xs text-slate-400 line-through tabular-nums">{formatMoney(product.base_price)}</div>}
        <div className="text-xs text-slate-400">за {product.unit}</div>
      </div>

      <div className="col-span-2 flex items-center justify-end gap-2 md:col-span-1">
        {inCart ? (
          <>
            <QuantityInput
              value={inCart.quantity}
              min={product.min_quantity}
              step={step}
              onChange={(next) => put.mutate({ productId: product.id, quantity: next })}
            />
            <span className="w-28 text-xs text-emerald-700">✓ в корзине</span>
          </>
        ) : (
          <>
            <QuantityInput value={quantity} min={product.min_quantity} step={step} onChange={setQuantity} />
            <Button
              className="w-28 whitespace-nowrap"
              disabled={put.isPending && put.variables?.productId === product.id}
              onClick={() => put.mutate({ productId: product.id, quantity })}
            >
              В корзину
            </Button>
          </>
        )}
      </div>
    </div>
  )
}
