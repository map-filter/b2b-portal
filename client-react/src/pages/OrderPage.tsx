import { Link, useLocation, useNavigate, useParams } from 'react-router'
import { download } from '../api/client'
import { useOrder, useRepeatOrder } from '../api/hooks'
import { Button, Card, Empty, Spinner, StatusBadge } from '../components/ui'
import { formatDate, formatDateTime, formatMoney } from '../lib/format'

export function OrderPage() {
  const { id = '' } = useParams()
  const location = useLocation()
  const navigate = useNavigate()
  const { data: order, isLoading, isError } = useOrder(id)
  const repeat = useRepeatOrder()

  if (isLoading) return <Spinner />
  if (isError || !order) return <Empty title="Заказ не найден или нет доступа" />

  return (
    <div className="space-y-4">
      {location.state?.placed && (
        <div className="rounded-xl bg-emerald-50 px-4 py-3 text-sm text-emerald-800 ring-1 ring-emerald-200">
          Заказ {order.number} оформлен. Менеджер подтвердит его в течение рабочего дня.
        </div>
      )}

      <div className="flex flex-wrap items-center gap-3">
        <Link to="/orders" className="text-sm text-slate-500 hover:text-slate-800">
          ← Заказы
        </Link>
        <h1 className="text-xl font-semibold text-slate-900">Заказ {order.number}</h1>
        <StatusBadge status={order.status} label={order.status_label} />
        <div className="ml-auto flex gap-2">
          <Button variant="secondary" onClick={() => download(`/orders/${order.id}/invoice`, `Счёт ${order.number}.pdf`)}>
            Счёт PDF
          </Button>
          <Button
            variant="secondary"
            disabled={repeat.isPending}
            onClick={() => repeat.mutate(order.id, { onSuccess: () => navigate('/cart') })}
          >
            Повторить заказ
          </Button>
        </div>
      </div>

      <div className="grid items-start gap-6 lg:grid-cols-[1fr_320px]">
        <Card className="overflow-hidden">
          <table className="w-full text-sm">
            <thead className="bg-slate-50 text-left text-xs tracking-wide text-slate-500 uppercase">
              <tr>
                <th className="px-4 py-2.5 font-medium">Товар</th>
                <th className="px-4 py-2.5 text-right font-medium">Кол-во</th>
                <th className="hidden px-4 py-2.5 text-right font-medium sm:table-cell">Цена</th>
                <th className="px-4 py-2.5 text-right font-medium">Сумма</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-slate-100">
              {order.items?.map((item) => (
                <tr key={item.product_id}>
                  <td className="px-4 py-3">
                    <div className="font-medium text-slate-800">{item.name}</div>
                    <div className="text-xs text-slate-500">Арт. {item.sku}</div>
                  </td>
                  <td className="px-4 py-3 text-right whitespace-nowrap tabular-nums">
                    {item.quantity} {item.unit}
                  </td>
                  <td className="hidden px-4 py-3 text-right tabular-nums sm:table-cell">{formatMoney(item.price)}</td>
                  <td className="px-4 py-3 text-right font-medium tabular-nums">{formatMoney(item.total)}</td>
                </tr>
              ))}
            </tbody>
            <tfoot>
              <tr className="bg-slate-50">
                <td colSpan={4} className="px-4 py-3 text-right text-base font-semibold text-slate-900 tabular-nums">
                  Итого {formatMoney(order.total)}
                </td>
              </tr>
            </tfoot>
          </table>
        </Card>

        <div className="space-y-4">
          <Card className="space-y-2 p-5 text-sm">
            <Row label="Создан" value={formatDateTime(order.created_at)} />
            <Row label="Автор" value={order.author} />
            <Row label="Договор" value={order.contract} />
            <Row label="Доставка" value={order.delivery_address} />
            {order.desired_date && <Row label="Желаемая дата" value={formatDate(order.desired_date)} />}
            {order.comment && <Row label="Комментарий" value={order.comment} />}
          </Card>

          <Card className="p-5">
            <h2 className="mb-3 text-sm font-semibold text-slate-800">История</h2>
            <ol className="space-y-3 border-l border-slate-200 pl-4">
              {order.history?.map((change, index) => (
                <li key={index} className="relative text-sm">
                  <span className="absolute top-1.5 -left-[21px] size-2.5 rounded-full bg-brand-500 ring-4 ring-white" />
                  <div className="font-medium text-slate-800">{change.status_label}</div>
                  <div className="text-xs text-slate-500">
                    {formatDateTime(change.at)}
                    {change.by && ` · ${change.by}`}
                  </div>
                  {change.comment && <div className="mt-0.5 text-slate-600">{change.comment}</div>}
                </li>
              ))}
            </ol>
          </Card>
        </div>
      </div>
    </div>
  )
}

function Row({ label, value }: { label: string; value?: string | null }) {
  return (
    <div className="flex justify-between gap-4">
      <span className="text-slate-500">{label}</span>
      <span className="text-right text-slate-800">{value ?? '-'}</span>
    </div>
  )
}
