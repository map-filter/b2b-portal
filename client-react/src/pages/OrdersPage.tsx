import { Link, useSearchParams } from 'react-router'
import { useOrders } from '../api/hooks'
import type { OrderStatus } from '../api/types'
import { Card, Empty, Pagination, Spinner, StatusBadge } from '../components/ui'
import { formatDateTime, formatMoney } from '../lib/format'

const statuses: { value: OrderStatus | ''; label: string }[] = [
  { value: '', label: 'Все' },
  { value: 'new', label: 'Новые' },
  { value: 'confirmed', label: 'Подтверждены' },
  { value: 'assembling', label: 'Комплектуются' },
  { value: 'shipped', label: 'Отгружены' },
  { value: 'completed', label: 'Выполнены' },
  { value: 'cancelled', label: 'Отменены' },
]

export function OrdersPage() {
  const [params, setParams] = useSearchParams()
  const status = params.get('status') ?? ''
  const page = Number(params.get('page') ?? 1)
  const { data, isLoading, isPlaceholderData } = useOrders({ status: status || undefined, page })

  const set = (key: string, value: string) => {
    const next = new URLSearchParams(params)
    if (value) next.set(key, value)
    else next.delete(key)
    if (key !== 'page') next.delete('page')
    setParams(next)
  }

  return (
    <div className="space-y-4">
      <h1 className="text-xl font-semibold text-slate-900">Заказы</h1>

      <div className="flex flex-wrap gap-1">
        {statuses.map((item) => (
          <button
            key={item.value}
            onClick={() => set('status', item.value)}
            className={`rounded-full px-3 py-1 text-sm transition ${status === item.value ? 'bg-slate-900 text-white' : 'bg-white text-slate-600 ring-1 ring-slate-200 hover:bg-slate-100'}`}
          >
            {item.label}
          </button>
        ))}
      </div>

      <Card className="overflow-hidden">
        {isLoading ? (
          <Spinner />
        ) : !data?.data.length ? (
          <Empty title="Заказов нет" />
        ) : (
          <table className={`w-full text-sm transition ${isPlaceholderData ? 'opacity-60' : ''}`}>
            <thead className="bg-slate-50 text-left text-xs tracking-wide text-slate-500 uppercase">
              <tr>
                <th className="px-4 py-2.5 font-medium">Номер</th>
                <th className="hidden px-4 py-2.5 font-medium sm:table-cell">Дата</th>
                <th className="hidden px-4 py-2.5 font-medium md:table-cell">Автор</th>
                <th className="px-4 py-2.5 font-medium">Статус</th>
                <th className="hidden px-4 py-2.5 text-right font-medium sm:table-cell">Позиций</th>
                <th className="px-4 py-2.5 text-right font-medium">Сумма</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-slate-100">
              {data.data.map((order) => (
                <tr key={order.id} className="hover:bg-slate-50">
                  <td className="px-4 py-3">
                    <Link to={`/orders/${order.id}`} className="font-medium whitespace-nowrap text-brand-700 hover:underline">
                      {order.number}
                    </Link>
                    <div className="text-xs text-slate-500 sm:hidden">{formatDateTime(order.created_at)}</div>
                  </td>
                  <td className="hidden px-4 py-3 text-slate-600 sm:table-cell">{formatDateTime(order.created_at)}</td>
                  <td className="hidden px-4 py-3 text-slate-600 md:table-cell">{order.author}</td>
                  <td className="px-4 py-3">
                    <StatusBadge status={order.status} label={order.status_label} />
                  </td>
                  <td className="hidden px-4 py-3 text-right text-slate-600 tabular-nums sm:table-cell">{order.items_count}</td>
                  <td className="px-4 py-3 text-right font-medium whitespace-nowrap text-slate-900 tabular-nums">{formatMoney(order.total)}</td>
                </tr>
              ))}
            </tbody>
          </table>
        )}
      </Card>

      {data && <Pagination page={data.meta.current_page} last={data.meta.last_page} onChange={(next) => set('page', String(next))} />}
    </div>
  )
}
