import { useState, type FormEvent } from 'react'
import { Link, useNavigate } from 'react-router'
import { ApiError } from '../api/client'
import { useCart, useCartMutations, usePlaceOrder } from '../api/hooks'
import { useAuth } from '../auth/context'
import { ExcelImport } from '../components/ExcelImport'
import { QuantityInput } from '../components/QuantityInput'
import { Button, Card, Empty, Spinner } from '../components/ui'
import { formatMoney, plural } from '../lib/format'

const today = new Date().toISOString().slice(0, 10)

export function CartPage() {
  const { user } = useAuth()
  const { data: cart, isLoading } = useCart()
  const { put, remove, clear } = useCartMutations()
  const placeOrder = usePlaceOrder()
  const navigate = useNavigate()
  const [comment, setComment] = useState('')
  const [address, setAddress] = useState(user?.company?.legal_address ?? '')
  const [date, setDate] = useState('')

  if (isLoading) return <Spinner />
  const submit = (event: FormEvent) => {
    event.preventDefault()
    placeOrder.mutate(
      { comment: comment || undefined, delivery_address: address || undefined, desired_date: date || undefined },
      { onSuccess: (order) => navigate(`/orders/${order.id}`, { state: { placed: true } }) },
    )
  }

  const error = placeOrder.error instanceof ApiError ? placeOrder.error : null
  const empty = !cart?.count
  const contract = user?.company?.contract

  return (
    <div className="grid items-start gap-6 lg:grid-cols-[1fr_360px]">
      <div className="space-y-4">
        {empty || !cart ? (
          <Card>
            <Empty title="Корзина пуста">
              <Link to="/" className="text-brand-600 hover:underline">
                Перейти в каталог
              </Link>
              , повторить прошлый заказ или загрузить список из Excel
            </Empty>
          </Card>
        ) : (
          <>
            <div className="flex items-center justify-between">
              <h1 className="text-xl font-semibold text-slate-900">
                Корзина: {cart.count} {plural(cart.count, 'позиция', 'позиции', 'позиций')}
              </h1>
              <Button variant="danger" onClick={() => clear.mutate()}>
                Очистить
              </Button>
            </div>

            <Card className="divide-y divide-slate-100">
              {cart.items.map((item) => (
                <div key={item.product.id} className="flex flex-wrap items-center gap-4 px-4 py-3">
                  <div className="min-w-0 flex-1">
                    <Link to={`/product/${item.product.slug}`} className="font-medium text-slate-800 hover:text-brand-700">
                      {item.product.name}
                    </Link>
                    <div className="text-xs text-slate-500">
                      Арт. {item.product.sku} · {formatMoney(item.product.price)} за {item.product.unit}
                    </div>
                    {item.warnings.map((warning) => (
                      <div key={warning} className="mt-1 text-xs text-amber-700">
                        {warning}
                      </div>
                    ))}
                  </div>
                  <QuantityInput
                    value={item.quantity}
                    min={item.product.min_quantity}
                    step={item.product.min_quantity > 1 ? item.product.min_quantity : 1}
                    onChange={(quantity) => put.mutate({ productId: item.product.id, quantity })}
                  />
                  <div className="w-32 text-right font-semibold text-slate-900 tabular-nums">{formatMoney(item.total)}</div>
                  <button
                    aria-label="Удалить"
                    onClick={() => remove.mutate(item.product.id)}
                    className="rounded-md px-2 py-1 text-slate-400 hover:bg-red-50 hover:text-red-600"
                  >
                    ✕
                  </button>
                </div>
              ))}
            </Card>
          </>
        )}

        <ExcelImport />
      </div>

      {cart && !empty && (
        <form onSubmit={submit} className="space-y-4">
          <Card className="space-y-4 p-6">
            <div className="flex items-baseline justify-between">
              <span className="text-slate-600">Итого</span>
              <span className="text-2xl font-semibold text-slate-900 tabular-nums">{formatMoney(cart.total)}</span>
            </div>
            {contract && (
              <p className="text-xs text-slate-500">
                Договор {contract.number}, {contract.price_type}
                {Number(contract.discount_percent) > 0 && `, скидка ${Number(contract.discount_percent)}%`}
              </p>
            )}
            <label className="block text-sm">
              <span className="font-medium text-slate-700">Адрес доставки</span>
              <input
                value={address}
                onChange={(event) => setAddress(event.target.value)}
                className="mt-1 w-full rounded-lg border-0 px-3 py-2 ring-1 ring-slate-300 outline-none focus:ring-2 focus:ring-brand-500"
              />
            </label>
            <label className="block text-sm">
              <span className="font-medium text-slate-700">Желаемая дата</span>
              <input
                type="date"
                value={date}
                min={today}
                onChange={(event) => setDate(event.target.value)}
                className="mt-1 w-full rounded-lg border-0 px-3 py-2 ring-1 ring-slate-300 outline-none focus:ring-2 focus:ring-brand-500"
              />
            </label>
            <label className="block text-sm">
              <span className="font-medium text-slate-700">Комментарий</span>
              <textarea
                rows={3}
                value={comment}
                onChange={(event) => setComment(event.target.value)}
                className="mt-1 w-full rounded-lg border-0 px-3 py-2 ring-1 ring-slate-300 outline-none focus:ring-2 focus:ring-brand-500"
              />
            </label>
            {error && (
              <div className="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700">
                <p className="font-medium">{error.message}</p>
                {Object.values(error.errors)
                  .flat()
                  .map((message) => (
                    <p key={message}>{message}</p>
                  ))}
              </div>
            )}
            <Button type="submit" className="w-full py-2.5" disabled={placeOrder.isPending}>
              {placeOrder.isPending ? 'Оформляем…' : 'Оформить заказ'}
            </Button>
          </Card>
        </form>
      )}
    </div>
  )
}
