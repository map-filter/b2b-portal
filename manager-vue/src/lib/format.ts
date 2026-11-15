const money = new Intl.NumberFormat('ru-RU', {
  style: 'currency',
  currency: 'KZT',
  currencyDisplay: 'narrowSymbol',
  maximumFractionDigits: 2,
})
const compact = new Intl.NumberFormat('ru-RU', { notation: 'compact', maximumFractionDigits: 1 })
const date = new Intl.DateTimeFormat('ru-RU', { day: '2-digit', month: '2-digit', year: 'numeric' })
const dateTime = new Intl.DateTimeFormat('ru-RU', {
  day: '2-digit',
  month: '2-digit',
  year: '2-digit',
  hour: '2-digit',
  minute: '2-digit',
})

export const formatMoney = (value: string | number) => money.format(Number(value))
export const formatCompact = (value: string | number) => `${compact.format(Number(value))} ₸`
export const formatDate = (value: string) => date.format(new Date(value))
export const formatDateTime = (value: string) => dateTime.format(new Date(value))

export function percentChange(current: string, previous: string): number | null {
  const prev = Number(previous)
  if (!prev) return null
  return Math.round(((Number(current) - prev) / prev) * 100)
}
