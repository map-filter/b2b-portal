import type { ButtonHTMLAttributes, ReactNode } from 'react'
import type { OrderStatus } from '../api/types'

type Variant = 'primary' | 'secondary' | 'ghost' | 'danger'

const variants: Record<Variant, string> = {
  primary: 'bg-brand-600 text-white hover:bg-brand-700 disabled:bg-brand-500/50',
  secondary: 'bg-white text-slate-700 ring-1 ring-slate-300 hover:bg-slate-50 disabled:text-slate-400',
  ghost: 'text-slate-600 hover:bg-slate-100 hover:text-slate-900',
  danger: 'text-red-600 hover:bg-red-50',
}

export function Button({
  variant = 'primary',
  className = '',
  ...props
}: ButtonHTMLAttributes<HTMLButtonElement> & { variant?: Variant }) {
  return (
    <button
      className={`inline-flex items-center justify-center gap-2 rounded-lg px-3.5 py-2 text-sm font-medium transition disabled:cursor-not-allowed ${variants[variant]} ${className}`}
      {...props}
    />
  )
}

export function Card({ children, className = '' }: { children: ReactNode; className?: string }) {
  return <div className={`rounded-xl bg-white shadow-sm ring-1 ring-slate-200 ${className}`}>{children}</div>
}

export function Spinner({ className = '' }: { className?: string }) {
  return (
    <div className={`flex justify-center py-12 ${className}`}>
      <div className="size-7 animate-spin rounded-full border-2 border-slate-200 border-t-brand-600" />
    </div>
  )
}

export function Empty({ title, children }: { title: string; children?: ReactNode }) {
  return (
    <div className="py-16 text-center">
      <p className="text-base font-medium text-slate-700">{title}</p>
      {children && <div className="mt-2 text-sm text-slate-500">{children}</div>}
    </div>
  )
}

const statusStyles: Record<OrderStatus, string> = {
  new: 'bg-sky-50 text-sky-700 ring-sky-200',
  confirmed: 'bg-indigo-50 text-indigo-700 ring-indigo-200',
  assembling: 'bg-amber-50 text-amber-700 ring-amber-200',
  shipped: 'bg-violet-50 text-violet-700 ring-violet-200',
  completed: 'bg-emerald-50 text-emerald-700 ring-emerald-200',
  cancelled: 'bg-slate-100 text-slate-500 ring-slate-200',
}

export function StatusBadge({ status, label }: { status: OrderStatus; label: string }) {
  return (
    <span className={`inline-flex rounded-full px-2.5 py-0.5 text-xs font-medium ring-1 ring-inset ${statusStyles[status]}`}>
      {label}
    </span>
  )
}

export function StockBadge({ stock, unit }: { stock?: number; unit: string }) {
  if (stock === undefined) return null
  if (stock <= 0) return <span className="text-xs text-slate-400">под заказ</span>

  return (
    <span className="inline-flex items-center gap-1.5 text-xs text-emerald-700">
      <span className="size-1.5 rounded-full bg-emerald-500" />
      {new Intl.NumberFormat('ru-RU').format(stock)} {unit}
    </span>
  )
}

export function Pagination({ page, last, onChange }: { page: number; last: number; onChange: (page: number) => void }) {
  if (last <= 1) return null

  return (
    <div className="flex items-center justify-center gap-2 py-4">
      <Button variant="secondary" disabled={page <= 1} onClick={() => onChange(page - 1)}>
        Назад
      </Button>
      <span className="px-2 text-sm text-slate-500">
        {page} из {last}
      </span>
      <Button variant="secondary" disabled={page >= last} onClick={() => onChange(page + 1)}>
        Вперёд
      </Button>
    </div>
  )
}
