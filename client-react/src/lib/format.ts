const money = new Intl.NumberFormat('ru-RU', {
  style: 'currency',
  currency: 'KZT',
  currencyDisplay: 'narrowSymbol',
  maximumFractionDigits: 2,
})
const number = new Intl.NumberFormat('ru-RU', { maximumFractionDigits: 3 })
const date = new Intl.DateTimeFormat('ru-RU', { day: '2-digit', month: '2-digit', year: 'numeric' })
const dateTime = new Intl.DateTimeFormat('ru-RU', {
  day: '2-digit',
  month: '2-digit',
  year: 'numeric',
  hour: '2-digit',
  minute: '2-digit',
})

export const formatMoney = (value: string | number) => money.format(Number(value))
export const formatNumber = (value: number) => number.format(value)
export const formatDate = (value: string) => date.format(new Date(value))
export const formatDateTime = (value: string) => dateTime.format(new Date(value))

export function plural(count: number, one: string, few: string, many: string) {
  const mod10 = count % 10
  const mod100 = count % 100
  if (mod10 === 1 && mod100 !== 11) return one
  if (mod10 >= 2 && mod10 <= 4 && (mod100 < 12 || mod100 > 14)) return few
  return many
}
