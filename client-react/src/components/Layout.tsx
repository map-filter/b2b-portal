import { NavLink, Outlet } from 'react-router'
import { useCart } from '../api/hooks'
import { useAuth } from '../auth/context'
import { formatMoney } from '../lib/format'

const linkClass = ({ isActive }: { isActive: boolean }) =>
  `rounded-lg px-3 py-2 text-sm font-medium transition ${isActive ? 'bg-brand-50 text-brand-700' : 'text-slate-600 hover:text-slate-900'}`

export function Layout() {
  const { user, logout } = useAuth()
  const { data: cart } = useCart()

  return (
    <div className="min-h-screen">
      <div className="bg-amber-50 px-4 py-1 text-center text-xs text-amber-800">
        Демо-версия: компании, товары и цены выдуманы
      </div>
      <header className="sticky top-0 z-20 border-b border-slate-200 bg-white/90 backdrop-blur">
        <div className="mx-auto flex max-w-7xl flex-wrap items-center gap-x-6 gap-y-1 px-4 py-2 md:h-16 md:flex-nowrap md:py-0">
          <NavLink to="/" className="flex items-center gap-2 font-semibold text-slate-900">
            <span className="grid size-8 place-items-center rounded-lg bg-brand-600 text-white">⚡</span>
            <span className="hidden sm:inline">ЭлектроОпт B2B</span>
          </NavLink>

          <nav className="order-last -mx-3 flex w-full items-center gap-1 md:order-none md:mx-0 md:w-auto">
            <NavLink to="/" end className={linkClass}>
              Каталог
            </NavLink>
            <NavLink to="/orders" className={linkClass}>
              Заказы
            </NavLink>
            <NavLink to="/company" className={linkClass}>
              Компания
            </NavLink>
          </nav>

          <div className="ml-auto flex items-center gap-3 md:gap-4">
            <NavLink
              to="/cart"
              className="flex items-center gap-2 rounded-lg bg-brand-600 px-3 py-2 text-sm font-medium text-white hover:bg-brand-700"
            >
              <span>Корзина</span>
              {cart && cart.count > 0 && (
                <span className="rounded-md bg-white/20 px-1.5 tabular-nums">
                  {cart.count} · {formatMoney(cart.total)}
                </span>
              )}
            </NavLink>
            <div className="hidden text-right leading-tight md:block">
              <div className="text-sm font-medium text-slate-800">{user?.name}</div>
              <div className="text-xs text-slate-500">{user?.company?.name}</div>
            </div>
            <button onClick={logout} className="text-sm text-slate-500 hover:text-slate-900">
              Выйти
            </button>
          </div>
        </div>
      </header>

      <main className="mx-auto max-w-7xl px-4 py-6">
        <Outlet />
      </main>
    </div>
  )
}
