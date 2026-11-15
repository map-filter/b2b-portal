<script setup lang="ts">
import { ArcElement, BarElement, CategoryScale, Chart, Filler, LinearScale, LineElement, PointElement, Tooltip } from 'chart.js'
import { computed, ref, watch } from 'vue'
import { Bar, Doughnut } from 'vue-chartjs'
import { api } from '../../api/client'
import type { KaspiDashboard } from '../../api/kaspi'
import Spinner from '../../components/Spinner.vue'
import { formatCompact, formatMoney, percentChange } from '../../lib/format'

Chart.register(BarElement, LineElement, PointElement, LinearScale, CategoryScale, Filler, Tooltip, ArcElement)

const days = ref(30)
const data = ref<KaspiDashboard | null>(null)
const loading = ref(false)

watch(
  days,
  async () => {
    loading.value = true
    data.value = await api<KaspiDashboard>(`/manager/kaspi/dashboard?days=${days.value}`)
    loading.value = false
  },
  { immediate: true },
)

const change = computed(() =>
  data.value ? percentChange(data.value.summary.revenue, data.value.summary.previous_revenue) : null,
)

const channelColors: Record<string, string> = { kaspi_delivery: '#f14635', pickup: '#3b6fe0', own_delivery: '#f59e0b' }

const barData = computed(() => ({
  labels: data.value?.daily.map((d) => d.date.slice(8, 10) + '.' + d.date.slice(5, 7)) ?? [],
  datasets: [
    {
      label: 'Выручка',
      data: data.value?.daily.map((d) => Number(d.revenue)) ?? [],
      backgroundColor: 'rgba(241,70,53,0.75)',
      borderRadius: 3,
      maxBarThickness: 18,
    },
  ],
}))

const barOptions = {
  responsive: true,
  maintainAspectRatio: false,
  plugins: {
    tooltip: {
      callbacks: {
        label: (ctx: { parsed: { y: number | null }; dataIndex: number }) =>
          `${formatMoney(ctx.parsed.y ?? 0)} · заказов ${data.value?.daily[ctx.dataIndex]?.orders ?? 0}`,
      },
    },
  },
  scales: {
    x: { grid: { display: false }, ticks: { maxTicksLimit: 10, color: '#94a3b8' } },
    y: { grid: { color: '#f1f5f9' }, ticks: { color: '#94a3b8', callback: (value: string | number) => formatCompact(value) } },
  },
}

const channelData = computed(() => ({
  labels: data.value?.by_delivery.map((d) => d.label) ?? [],
  datasets: [
    {
      data: data.value?.by_delivery.map((d) => d.orders) ?? [],
      backgroundColor: data.value?.by_delivery.map((d) => channelColors[d.channel]) ?? [],
      borderWidth: 0,
    },
  ],
}))
</script>

<template>
  <div class="space-y-6">
    <div class="flex flex-wrap items-center gap-3">
      <h1 class="text-xl font-semibold text-slate-900">Продажи на Kaspi</h1>
      <div class="ml-auto flex rounded-lg bg-white p-0.5 ring-1 ring-slate-200">
        <button
          v-for="option in [7, 30, 90]"
          :key="option"
          class="rounded-md px-3 py-1 text-sm"
          :class="days === option ? 'bg-slate-900 text-white' : 'text-slate-600 hover:bg-slate-100'"
          @click="days = option"
        >
          {{ option }} дн.
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
          <div class="mt-1 text-xs text-slate-500">средний чек {{ formatMoney(data.summary.average) }}</div>
        </div>
        <div class="card p-5">
          <div class="text-sm text-slate-500">Отмены и возвраты</div>
          <div
            class="mt-1 text-2xl font-semibold tabular-nums"
            :class="data.summary.cancel_rate > 10 ? 'text-red-600' : 'text-slate-900'"
          >
            {{ data.summary.cancel_rate }}%
          </div>
          <div class="mt-1 text-xs text-slate-500">Kaspi Доставка обошлась в {{ formatMoney(data.summary.delivery_cost) }}</div>
        </div>
        <RouterLink :to="{ name: 'kaspi-orders', query: { state: 'NEW' } }" class="card p-5 transition hover:ring-brand-500">
          <div class="text-sm text-slate-500">Ждут принятия</div>
          <div
            class="mt-1 text-2xl font-semibold tabular-nums"
            :class="data.summary.awaiting ? 'text-red-600' : 'text-slate-900'"
          >
            {{ data.summary.awaiting }}
          </div>
          <div class="mt-1 text-xs text-brand-600">Открыть новые заказы →</div>
        </RouterLink>
      </div>

      <div class="grid gap-4 xl:grid-cols-[1fr_320px]">
        <div class="card p-5">
          <h2 class="mb-4 font-semibold text-slate-800">Выручка по дням</h2>
          <div class="h-64"><Bar :data="barData" :options="barOptions" /></div>
        </div>
        <div class="card p-5">
          <h2 class="mb-4 font-semibold text-slate-800">Способ доставки</h2>
          <div class="mx-auto h-40 w-40">
            <Doughnut :data="channelData" :options="{ plugins: { legend: { display: false } }, cutout: '70%' }" />
          </div>
          <ul class="mt-4 space-y-1.5 text-sm">
            <li v-for="item in data.by_delivery" :key="item.channel" class="flex items-center gap-2">
              <span class="size-2.5 rounded-full" :style="{ background: channelColors[item.channel] }" />
              <span class="text-slate-600">{{ item.label }}</span>
              <span class="ml-auto text-slate-900 tabular-nums">{{ item.orders }}</span>
            </li>
          </ul>
        </div>
      </div>

      <div class="grid gap-4 lg:grid-cols-2">
        <div class="card overflow-hidden">
          <h2 class="px-5 pt-5 pb-3 font-semibold text-slate-800">Топ товаров на Kaspi</h2>
          <table class="w-full text-sm">
            <tbody class="divide-y divide-slate-100">
              <tr v-for="(product, index) in data.top_products" :key="product.sku ?? index">
                <td class="w-8 py-2.5 pl-5 text-slate-400 tabular-nums">{{ index + 1 }}</td>
                <td class="py-2.5 pr-3">
                  <div class="text-slate-800">{{ product.name }}</div>
                  <div class="text-xs text-slate-500">{{ product.sku }} · {{ product.quantity }} шт.</div>
                </td>
                <td class="py-2.5 pr-5 text-right font-medium whitespace-nowrap tabular-nums">
                  {{ formatMoney(product.revenue) }}
                </td>
              </tr>
            </tbody>
          </table>
        </div>
        <div class="card overflow-hidden">
          <div class="flex items-center px-5 pt-5 pb-3">
            <h2 class="font-semibold text-slate-800">Выставлены, но закончились</h2>
            <RouterLink :to="{ name: 'kaspi-products', query: { filter: 'out_of_stock' } }" class="ml-auto text-xs text-brand-600"
              >Все →</RouterLink
            >
          </div>
          <p v-if="!data.out_of_stock.length" class="px-5 pb-5 text-sm text-slate-500">Все выставленные товары есть на складе</p>
          <table v-else class="w-full text-sm">
            <tbody class="divide-y divide-slate-100">
              <tr v-for="row in data.out_of_stock" :key="row.product_id">
                <td class="py-2.5 pl-5">
                  <div class="text-slate-800">{{ row.name }}</div>
                  <div class="text-xs text-slate-500">{{ row.sku }}</div>
                </td>
                <td class="py-2.5 pr-5 text-right text-xs whitespace-nowrap text-slate-500">
                  {{ row.sold_30d ? `продано ${row.sold_30d} за 30 дн.` : 'продаж не было' }}
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
    </template>
  </div>
</template>
