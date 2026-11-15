<script setup lang="ts">
import { BarElement, CategoryScale, Chart, Filler, LinearScale, LineElement, PointElement, Tooltip, ArcElement } from 'chart.js'
import { computed, ref, watch } from 'vue'
import { Doughnut, Line } from 'vue-chartjs'
import { api, toQuery } from '../api/client'
import type { Dashboard } from '../api/types'
import Spinner from '../components/Spinner.vue'
import { formatCompact, formatMoney, percentChange } from '../lib/format'
import { useAuthStore } from '../stores/auth'

Chart.register(LineElement, PointElement, LinearScale, CategoryScale, BarElement, Filler, Tooltip, ArcElement)

const auth = useAuthStore()
const days = ref(30)
const data = ref<Dashboard | null>(null)
const loading = ref(false)

async function load() {
  loading.value = true
  data.value = await api<Dashboard>(`/manager/dashboard${toQuery({ days: days.value, mine: auth.mine })}`)
  loading.value = false
}

watch([days, () => auth.mine], load, { immediate: true })

const change = computed(() =>
  data.value ? percentChange(data.value.summary.revenue, data.value.summary.previous_revenue) : null,
)

const statusColors: Record<string, string> = {
  new: '#0ea5e9',
  confirmed: '#6366f1',
  assembling: '#f59e0b',
  shipped: '#8b5cf6',
  completed: '#10b981',
  cancelled: '#94a3b8',
}

const lineData = computed(() => ({
  labels: data.value?.daily.map((d) => d.date.slice(8, 10) + '.' + d.date.slice(5, 7)) ?? [],
  datasets: [
    {
      data: data.value?.daily.map((d) => Number(d.revenue)) ?? [],
      borderColor: '#3b6fe0',
      backgroundColor: 'rgba(59,111,224,0.08)',
      fill: true,
      tension: 0.3,
      pointRadius: 0,
      pointHoverRadius: 4,
      borderWidth: 2,
    },
  ],
}))

const lineOptions = {
  responsive: true,
  maintainAspectRatio: false,
  interaction: { mode: 'index' as const, intersect: false },
  plugins: { tooltip: { callbacks: { label: (ctx: { parsed: { y: number | null } }) => formatMoney(ctx.parsed.y ?? 0) } } },
  scales: {
    x: { grid: { display: false }, ticks: { maxTicksLimit: 8, color: '#94a3b8' } },
    y: { grid: { color: '#f1f5f9' }, ticks: { color: '#94a3b8', callback: (value: string | number) => formatCompact(value) } },
  },
}

const statusData = computed(() => ({
  labels: data.value?.by_status.map((s) => s.label) ?? [],
  datasets: [
    {
      data: data.value?.by_status.map((s) => s.orders) ?? [],
      backgroundColor: data.value?.by_status.map((s) => statusColors[s.status]) ?? [],
      borderWidth: 0,
    },
  ],
}))
</script>

<template>
  <div class="space-y-6">
    <div class="flex flex-wrap items-center gap-3">
      <h1 class="text-xl font-semibold text-slate-900">Сводка</h1>
      <div class="ml-auto flex rounded-lg bg-white p-0.5 ring-1 ring-slate-200">
        <button
          v-for="option in [7, 30, 90, 365]"
          :key="option"
          class="rounded-md px-3 py-1 text-sm"
          :class="days === option ? 'bg-slate-900 text-white' : 'text-slate-600 hover:bg-slate-100'"
          @click="days = option"
        >
          {{ option === 365 ? 'Год' : `${option} дн.` }}
        </button>
      </div>
    </div>

    <Spinner v-if="!data" />
    <template v-else>
      <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4" :class="{ 'opacity-60': loading }">
        <div class="card p-5">
          <div class="text-sm text-slate-500">Выручка</div>
          <div class="mt-1 text-2xl font-semibold text-slate-900 tabular-nums">{{ formatMoney(data.summary.revenue) }}</div>
          <div v-if="change !== null" class="mt-1 text-xs" :class="change >= 0 ? 'text-emerald-600' : 'text-red-600'">
            {{ change >= 0 ? '▲' : '▼' }} {{ Math.abs(change) }}% к прошлому периоду
          </div>
        </div>
        <div class="card p-5">
          <div class="text-sm text-slate-500">Заказов</div>
          <div class="mt-1 text-2xl font-semibold text-slate-900 tabular-nums">{{ data.summary.orders }}</div>
          <div class="mt-1 text-xs text-slate-500">от {{ data.summary.companies }} клиентов</div>
        </div>
        <div class="card p-5">
          <div class="text-sm text-slate-500">Средний чек</div>
          <div class="mt-1 text-2xl font-semibold text-slate-900 tabular-nums">{{ formatMoney(data.summary.average) }}</div>
        </div>
        <RouterLink :to="{ name: 'orders', query: { status: 'new' } }" class="card p-5 transition hover:ring-brand-500">
          <div class="text-sm text-slate-500">Ждут подтверждения</div>
          <div
            class="mt-1 text-2xl font-semibold tabular-nums"
            :class="data.summary.awaiting ? 'text-sky-600' : 'text-slate-900'"
          >
            {{ data.summary.awaiting }}
          </div>
          <div class="mt-1 text-xs text-brand-600">Открыть новые заказы →</div>
        </RouterLink>
      </div>

      <div class="grid gap-4 xl:grid-cols-[1fr_320px]">
        <div class="card p-5">
          <h2 class="mb-4 font-semibold text-slate-800">Выручка по дням</h2>
          <div class="h-64"><Line :data="lineData" :options="lineOptions" /></div>
        </div>
        <div class="card p-5">
          <h2 class="mb-4 font-semibold text-slate-800">Заказы по статусам</h2>
          <div class="mx-auto h-40 w-40">
            <Doughnut :data="statusData" :options="{ plugins: { legend: { display: false } }, cutout: '70%' }" />
          </div>
          <ul class="mt-4 space-y-1.5 text-sm">
            <li v-for="item in data.by_status" :key="item.status" class="flex items-center gap-2">
              <span class="size-2.5 rounded-full" :style="{ background: statusColors[item.status] }" />
              <span class="text-slate-600">{{ item.label }}</span>
              <span class="ml-auto text-slate-900 tabular-nums">{{ item.orders }}</span>
            </li>
          </ul>
        </div>
      </div>

      <div class="grid gap-4 lg:grid-cols-2">
        <div class="card overflow-hidden">
          <h2 class="px-5 pt-5 pb-3 font-semibold text-slate-800">Топ товаров</h2>
          <table class="w-full text-sm">
            <tbody class="divide-y divide-slate-100">
              <tr v-for="(product, index) in data.top_products" :key="product.product_id">
                <td class="w-8 py-2.5 pl-5 text-slate-400 tabular-nums">{{ index + 1 }}</td>
                <td class="py-2.5 pr-3">
                  <div class="text-slate-800">{{ product.name }}</div>
                  <div class="text-xs text-slate-500">{{ product.sku }} · {{ product.quantity }} {{ product.unit }}</div>
                </td>
                <td class="py-2.5 pr-5 text-right font-medium whitespace-nowrap tabular-nums">
                  {{ formatMoney(product.revenue) }}
                </td>
              </tr>
            </tbody>
          </table>
        </div>
        <div class="card overflow-hidden">
          <h2 class="px-5 pt-5 pb-3 font-semibold text-slate-800">Топ клиентов</h2>
          <table class="w-full text-sm">
            <tbody class="divide-y divide-slate-100">
              <tr v-for="(company, index) in data.top_companies" :key="company.id">
                <td class="w-8 py-2.5 pl-5 text-slate-400 tabular-nums">{{ index + 1 }}</td>
                <td class="py-2.5 pr-3">
                  <RouterLink :to="{ name: 'company', params: { id: company.id } }" class="text-slate-800 hover:text-brand-700">{{
                    company.name
                  }}</RouterLink>
                  <div class="text-xs text-slate-500">{{ company.orders }} заказов</div>
                </td>
                <td class="py-2.5 pr-5 text-right font-medium whitespace-nowrap tabular-nums">
                  {{ formatMoney(company.revenue) }}
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
    </template>
  </div>
</template>
