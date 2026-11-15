import { useState } from 'react'
import { Link, useParams } from 'react-router'
import { useCart, useCartMutations, useProduct } from '../api/hooks'
import { QuantityInput } from '../components/QuantityInput'
import { Button, Card, Empty, Spinner, StockBadge } from '../components/ui'
import { formatMoney, formatNumber } from '../lib/format'

export function ProductPage() {
  const { slug = '' } = useParams()
  const { data: product, isLoading, isError } = useProduct(slug)
  const { data: cart } = useCart()
  const { put } = useCartMutations()
  const [quantity, setQuantity] = useState<number | null>(null)

  if (isLoading) return <Spinner />
  if (isError || !product) return <Empty title="Товар не найден" />

  const inCart = cart?.items.find((item) => item.product.id === product.id)
  const value = quantity ?? inCart?.quantity ?? Math.max(product.min_quantity, 1)
  const step = product.min_quantity > 1 ? product.min_quantity : 1

  return (
    <div className="space-y-4">
      <nav className="text-sm text-slate-500">
        <Link to="/" className="hover:text-slate-800">
          Каталог
        </Link>
        {product.category && (
          <>
            {' / '}
            <Link to={`/?category=${product.category.id}`} className="hover:text-slate-800">
              {product.category.name}
            </Link>
          </>
        )}
      </nav>

      <div className="grid items-start gap-6 lg:grid-cols-[1fr_360px]">
        <Card className="p-6">
          <h1 className="text-2xl font-semibold text-slate-900">{product.name}</h1>
          <div className="mt-2 flex gap-4 text-sm text-slate-500">
            <span>Артикул {product.sku}</span>
            {product.brand && <span>Бренд {product.brand}</span>}
          </div>

          <h2 className="mt-8 mb-3 font-semibold text-slate-800">Характеристики</h2>
          <dl className="divide-y divide-slate-100 rounded-lg ring-1 ring-slate-200">
            {Object.entries(product.specs).map(([name, specValue]) => (
              <div key={name} className="grid grid-cols-2 px-4 py-2 text-sm">
                <dt className="text-slate-500">{name}</dt>
                <dd className="text-slate-800">{specValue}</dd>
              </div>
            ))}
            <div className="grid grid-cols-2 px-4 py-2 text-sm">
              <dt className="text-slate-500">В упаковке</dt>
              <dd className="text-slate-800">
                {product.pack_quantity} {product.unit}
              </dd>
            </div>
          </dl>

          {product.description && <p className="mt-6 text-sm leading-relaxed text-slate-600">{product.description}</p>}
        </Card>

        <div className="space-y-4">
          <Card className="space-y-4 p-6">
            <div>
              <div className="text-3xl font-semibold text-slate-900 tabular-nums">{formatMoney(product.price)}</div>
              <div className="text-sm text-slate-500">
                за {product.unit}, цена по вашему договору
                {Number(product.price) < Number(product.base_price) && (
                  <span className="ml-1 line-through">{formatMoney(product.base_price)}</span>
                )}
              </div>
            </div>
            {product.min_quantity > 1 && (
              <p className="text-sm text-amber-700">
                Минимальная партия {product.min_quantity} {product.unit}
              </p>
            )}
            <div className="flex items-center gap-3">
              <QuantityInput value={value} min={product.min_quantity} step={step} onChange={setQuantity} />
              <span className="text-sm text-slate-600 tabular-nums">= {formatMoney(Number(product.price) * value)}</span>
            </div>
            <Button
              className="w-full py-2.5"
              disabled={put.isPending}
              onClick={() => put.mutate({ productId: product.id, quantity: value })}
            >
              {inCart ? 'Обновить в корзине' : 'Добавить в корзину'}
            </Button>
            {inCart && (
              <p className="text-center text-sm text-emerald-700">
                В корзине {inCart.quantity} {product.unit}
              </p>
            )}
          </Card>

          <Card className="p-6">
            <div className="mb-3 flex items-center justify-between">
              <h2 className="font-semibold text-slate-800">Остатки</h2>
              <StockBadge stock={product.stock} unit={product.unit} />
            </div>
            <ul className="space-y-1.5 text-sm">
              {product.warehouses?.map((warehouse) => (
                <li key={warehouse.name} className="flex justify-between">
                  <span className="text-slate-600">{warehouse.name}</span>
                  <span className={warehouse.quantity > 0 ? 'text-slate-800 tabular-nums' : 'text-slate-400'}>
                    {warehouse.quantity > 0 ? `${formatNumber(warehouse.quantity)} ${product.unit}` : 'нет'}
                  </span>
                </li>
              ))}
            </ul>
          </Card>
        </div>
      </div>
    </div>
  )
}
