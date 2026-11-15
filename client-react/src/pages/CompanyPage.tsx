import { useAuth } from '../auth/context'
import { Card } from '../components/ui'
import { formatDate, formatMoney } from '../lib/format'

export function CompanyPage() {
  const { user } = useAuth()
  const company = user?.company

  if (!company) return null

  return (
    <div className="space-y-4">
      <h1 className="text-xl font-semibold text-slate-900">{company.legal_name}</h1>

      <div className="grid gap-4 md:grid-cols-3">
        <Card className="space-y-2 p-5 text-sm">
          <h2 className="mb-2 font-semibold text-slate-800">Реквизиты</h2>
          <Row label="БИН" value={company.bin} />
          <Row label="Адрес" value={company.legal_address} />
        </Card>

        <Card className="space-y-2 p-5 text-sm">
          <h2 className="mb-2 font-semibold text-slate-800">Договор</h2>
          {company.contract ? (
            <>
              <Row label="Номер" value={company.contract.number} />
              <Row label="Вид цен" value={company.contract.price_type} />
              <Row label="Скидка" value={`${Number(company.contract.discount_percent)}%`} />
              <Row label="Действует до" value={company.contract.ends_at ? formatDate(company.contract.ends_at) : 'бессрочно'} />
            </>
          ) : (
            <p className="text-slate-500">Нет действующего договора</p>
          )}
          <Row
            label="Кредитный лимит"
            value={Number(company.credit_limit) > 0 ? formatMoney(company.credit_limit) : 'предоплата'}
          />
          <Row label="Отсрочка" value={company.payment_delay_days ? `${company.payment_delay_days} дн.` : 'нет'} />
        </Card>

        <Card className="space-y-2 p-5 text-sm">
          <h2 className="mb-2 font-semibold text-slate-800">Ваш менеджер</h2>
          {company.manager ? (
            <>
              <p className="font-medium text-slate-800">{company.manager.name}</p>
              <a href={`mailto:${company.manager.email}`} className="block text-brand-700 hover:underline">
                {company.manager.email}
              </a>
              {company.manager.phone && <p className="text-slate-600">{company.manager.phone}</p>}
            </>
          ) : (
            <p className="text-slate-500">Не назначен</p>
          )}
        </Card>
      </div>

      <Card className="p-5 text-sm">
        <h2 className="mb-2 font-semibold text-slate-800">Вы</h2>
        <p>
          {user.name}, {user.role_label.toLowerCase()} · {user.email}
        </p>
        <p className="mt-1 text-slate-500">
          {user.role === 'client_admin'
            ? 'Видите все заказы компании.'
            : 'Видите свои заказы. Все заказы компании видит руководитель.'}
        </p>
      </Card>
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
