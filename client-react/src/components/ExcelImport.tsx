import { useRef } from 'react'
import { ApiError, download } from '../api/client'
import { useCartImport } from '../api/hooks'
import { plural } from '../lib/format'
import { Button, Card } from './ui'

export function ExcelImport() {
  const input = useRef<HTMLInputElement>(null)
  const importer = useCartImport()
  const result = importer.data

  return (
    <Card className="space-y-3 p-5">
      <div className="flex flex-wrap items-center gap-3">
        <div className="min-w-0 flex-1">
          <h2 className="font-semibold text-slate-800">Заказ из Excel</h2>
          <p className="text-sm text-slate-500">Файл с колонками «Артикул» и «Количество», позиции добавятся в корзину</p>
        </div>
        <Button variant="ghost" onClick={() => download('/cart/import/template', 'zakaz-shablon.xlsx')}>
          Шаблон
        </Button>
        <Button variant="secondary" disabled={importer.isPending} onClick={() => input.current?.click()}>
          {importer.isPending ? 'Загружаем…' : 'Загрузить файл'}
        </Button>
        <input
          ref={input}
          type="file"
          accept=".xlsx,.xls,.csv"
          className="hidden"
          onChange={(event) => {
            const file = event.target.files?.[0]
            if (file) importer.mutate(file)
            event.target.value = ''
          }}
        />
      </div>

      {importer.error && (
        <p className="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700">
          {importer.error instanceof ApiError
            ? (Object.values(importer.error.errors)[0]?.[0] ?? importer.error.message)
            : 'Ошибка загрузки'}
        </p>
      )}

      {result && (
        <div className="space-y-1 rounded-lg bg-slate-50 px-3 py-2 text-sm">
          <p className="text-emerald-700">
            Добавлено {result.added} {plural(result.added, 'позиция', 'позиции', 'позиций')}
          </p>
          {result.not_found.map((line) => (
            <p key={`n${line.row}`} className="text-amber-700">
              Строка {line.row}: артикул {line.sku} не найден
            </p>
          ))}
          {result.invalid.map((line) => (
            <p key={`i${line.row}`} className="text-amber-700">
              Строка {line.row}: {line.reason}
            </p>
          ))}
        </div>
      )}
    </Card>
  )
}
