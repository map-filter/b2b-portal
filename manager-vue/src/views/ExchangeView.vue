<script setup lang="ts">
import { onMounted, onUnmounted, ref } from 'vue'
import { api } from '../api/client'
import Spinner from '../components/Spinner.vue'
import { formatDateTime } from '../lib/format'

interface ImportRow {
  id: string
  file: string
  size_bytes: number
  duration_ms: number
  stats: Record<string, number>
  applied: Record<string, number>
  finished_at: string | null
  created_at: string
}

const data = ref<{ data: ImportRow[]; products_from_1c: number; endpoint: string } | null>(null)
const labels: Record<string, string> = {
  categories: 'групп',
  products: 'товаров',
  price_types: 'видов цен',
  warehouses: 'складов',
  prices: 'цен',
  stocks: 'остатков',
}

async function load() {
  data.value = await api('/manager/exchange/imports')
}

let timer: ReturnType<typeof setInterval>
onMounted(() => {
  load()
  timer = setInterval(load, 5000)
})
onUnmounted(() => clearInterval(timer))

const size = (bytes: number) => (bytes > 1 << 20 ? `${(bytes / (1 << 20)).toFixed(1)} МБ` : `${Math.ceil(bytes / 1024)} КБ`)
const entries = (row: ImportRow) => Object.entries(row.stats).filter(([, count]) => count > 0)
const progress = (row: ImportRow, key: string) =>
  Math.min(100, Math.round(((row.applied?.[key] ?? 0) / (row.stats[key] || 1)) * 100))
</script>

<template>
  <div class="space-y-4">
    <h1 class="text-xl font-semibold text-slate-900">Обмен с 1С</h1>

    <Spinner v-if="!data" />
    <template v-else>
      <div class="grid gap-4 md:grid-cols-3">
        <div class="card p-5 md:col-span-2">
          <h2 class="mb-2 font-semibold text-slate-800">Подключение в 1С</h2>
          <p class="text-sm text-slate-600">
            Стандартный обмен с сайтом (CommerceML 2.08): в 1С укажите адрес, логин и пароль. Каталог, цены и остатки приходят
            пачками через RabbitMQ, заказы портала забираются 1С в режиме «Выгрузка заказов».
          </p>
          <div class="mt-3 rounded-lg bg-slate-50 px-3 py-2 font-mono text-sm break-all text-slate-700">{{ data.endpoint }}</div>
        </div>
        <div class="card p-5">
          <div class="text-sm text-slate-500">Товаров из 1С в каталоге</div>
          <div class="mt-1 text-2xl font-semibold tabular-nums">{{ data.products_from_1c.toLocaleString('ru-RU') }}</div>
        </div>
      </div>

      <div class="card overflow-x-auto">
        <p v-if="!data.data.length" class="py-12 text-center text-sm text-slate-500">Загрузок ещё не было</p>
        <table v-else class="w-full text-sm">
          <thead class="bg-slate-50">
            <tr>
              <th class="th">Файл</th>
              <th class="th">Когда</th>
              <th class="th text-right">Размер</th>
              <th class="th text-right">Разбор в Go</th>
              <th class="th">Применено в портале</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-slate-100">
            <tr v-for="row in data.data" :key="row.id">
              <td class="px-4 py-3 font-medium">{{ row.file }}</td>
              <td class="px-4 py-3 whitespace-nowrap text-slate-600">{{ formatDateTime(row.created_at) }}</td>
              <td class="px-4 py-3 text-right whitespace-nowrap tabular-nums">{{ size(row.size_bytes) }}</td>
              <td class="px-4 py-3 text-right whitespace-nowrap tabular-nums">{{ row.duration_ms }} мс</td>
              <td class="min-w-64 px-4 py-3">
                <div v-for="[key, count] in entries(row)" :key="key" class="flex items-center gap-2 py-0.5">
                  <span class="w-40 text-xs text-slate-600"
                    >{{ (row.applied?.[key] ?? 0).toLocaleString('ru-RU') }} / {{ count.toLocaleString('ru-RU') }}
                    {{ labels[key] }}</span
                  >
                  <div class="h-1.5 flex-1 overflow-hidden rounded-full bg-slate-100">
                    <div
                      class="h-full rounded-full"
                      :class="progress(row, key) === 100 ? 'bg-emerald-500' : 'bg-brand-500'"
                      :style="{ width: `${progress(row, key)}%` }"
                    />
                  </div>
                </div>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </template>
  </div>
</template>
