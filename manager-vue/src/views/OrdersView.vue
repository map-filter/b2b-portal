<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { api, toQuery } from '../api/client'
import type { Order, OrderStatus, Paginated } from '../api/types'
import Pager from '../components/Pager.vue'
import Spinner from '../components/Spinner.vue'
import StatusBadge from '../components/StatusBadge.vue'
import { formatDateTime, formatMoney } from '../lib/format'
import { useAuthStore } from '../stores/auth'

const route = useRoute()
const router = useRouter()
const auth = useAuthStore()
const result = ref<Paginated<Order> | null>(null)
const loading = ref(false)
const search = ref(String(route.query.q ?? ''))

const statuses: { value: OrderStatus | ''; label: string }[] = [
  { value: '', label: 'Все' },
  { value: 'new', label: 'Новые' },
  { value: 'confirmed', label: 'Подтверждены' },
  { value: 'assembling', label: 'Комплектуются' },
  { value: 'shipped', label: 'Отгружены' },
  { value: 'completed', label: 'Выполнены' },
  { value: 'cancelled', label: 'Отменены' },
]

const filters = computed(() => ({
  status: String(route.query.status ?? ''),
  q: String(route.query.q ?? ''),
  company: route.query.company ? Number(route.query.company) : undefined,
  page: Number(route.query.page ?? 1),
}))

function setQuery(patch: Record<string, string | number | undefined>) {
  const query = { ...route.query, ...patch }
  if (!('page' in patch)) delete query.page
  Object.keys(query).forEach((key) => (query[key] === '' || query[key] === undefined) && delete query[key])
  router.replace({ query: query as Record<string, string> })
}

let timer: ReturnType<typeof setTimeout>
watch(search, (value) => {
  clearTimeout(timer)
  timer = setTimeout(() => setQuery({ q: value.trim() }), 350)
})

watch(
  [filters, () => auth.mine],
  async () => {
    loading.value = true
    result.value = await api<Paginated<Order>>(`/manager/orders${toQuery({ ...filters.value, mine: auth.mine })}`)
    loading.value = false
  },
  { immediate: true },
)
</script>

<template>
  <div class="space-y-4">
    <div class="flex flex-wrap items-center gap-3">
      <h1 class="text-xl font-semibold text-slate-900">Заказы</h1>
      <span v-if="result" class="text-sm text-slate-500">{{ result.meta.total }}</span>
      <input v-model="search" type="search" placeholder="Номер заказа или клиент" class="field ml-auto max-w-xs" />
    </div>

    <div class="flex flex-wrap gap-1">
      <button
        v-for="item in statuses"
        :key="item.value"
        class="rounded-full px-3 py-1 text-sm transition"
        :class="
          filters.status === item.value
            ? 'bg-slate-900 text-white'
            : 'bg-white text-slate-600 ring-1 ring-slate-200 hover:bg-slate-100'
        "
        @click="setQuery({ status: item.value })"
      >
        {{ item.label }}
      </button>
      <button
        v-if="filters.company"
        class="rounded-full bg-brand-50 px-3 py-1 text-sm text-brand-700"
        @click="setQuery({ company: undefined })"
      >
        Один клиент ✕
      </button>
    </div>

    <div class="card overflow-x-auto">
      <Spinner v-if="!result" />
      <p v-else-if="!result.data.length" class="py-16 text-center text-slate-500">Заказов нет</p>
      <table v-else class="w-full text-sm transition" :class="{ 'opacity-60': loading }">
        <thead class="bg-slate-50">
          <tr>
            <th class="th">Номер</th>
            <th class="th">Клиент</th>
            <th class="th hidden md:table-cell">Создан</th>
            <th class="th">Статус</th>
            <th class="th hidden text-right sm:table-cell">Поз.</th>
            <th class="th text-right">Сумма</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-slate-100">
          <tr
            v-for="order in result.data"
            :key="order.id"
            class="cursor-pointer hover:bg-slate-50"
            @click="router.push({ name: 'order', params: { id: order.id } })"
          >
            <td class="px-4 py-3 font-medium whitespace-nowrap text-brand-700">{{ order.number }}</td>
            <td class="px-4 py-3">
              <div class="text-slate-800">{{ order.company?.name }}</div>
              <div class="text-xs text-slate-500">{{ order.author }}</div>
            </td>
            <td class="hidden px-4 py-3 whitespace-nowrap text-slate-600 md:table-cell">
              {{ formatDateTime(order.created_at) }}
            </td>
            <td class="px-4 py-3"><StatusBadge :status="order.status" :label="order.status_label" /></td>
            <td class="hidden px-4 py-3 text-right text-slate-600 tabular-nums sm:table-cell">{{ order.items_count }}</td>
            <td class="px-4 py-3 text-right font-medium whitespace-nowrap tabular-nums">{{ formatMoney(order.total) }}</td>
          </tr>
        </tbody>
      </table>
    </div>

    <Pager v-if="result" :page="result.meta.current_page" :last="result.meta.last_page" @change="(page) => setQuery({ page })" />
  </div>
</template>
