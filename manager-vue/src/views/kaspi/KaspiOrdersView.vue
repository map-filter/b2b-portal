<script setup lang="ts">
import { computed, onBeforeUnmount, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { ApiError, api, toQuery } from '../../api/client'
import type { KaspiOrdersPage } from '../../api/kaspi'
import KaspiStatusBadge from '../../components/KaspiStatusBadge.vue'
import Pager from '../../components/Pager.vue'
import Spinner from '../../components/Spinner.vue'
import { formatDateTime, formatMoney } from '../../lib/format'

const route = useRoute()
const router = useRouter()
const result = ref<KaspiOrdersPage | null>(null)
const loading = ref(false)
const syncing = ref(false)
const syncError = ref<string | null>(null)
const search = ref(String(route.query.q ?? ''))

const filters = computed(() => ({
  state: String(route.query.state ?? ''),
  q: String(route.query.q ?? ''),
  problem: route.query.problem === '1',
  page: Number(route.query.page ?? 1),
}))

const deliveryIcons: Record<string, string> = { pickup: '🏬', kaspi_delivery: '🚚', own_delivery: '🛵' }

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

async function load() {
  loading.value = true
  result.value = await api<KaspiOrdersPage>(`/manager/kaspi/orders${toQuery(filters.value)}`)
  loading.value = false
}

watch(filters, load, { immediate: true })

const poll = setInterval(load, 30000)
onBeforeUnmount(() => clearInterval(poll))

async function syncNow() {
  syncing.value = true
  syncError.value = null
  try {
    await api('/manager/kaspi/sync', { method: 'POST' })
    await load()
  } catch (exception) {
    syncError.value = exception instanceof ApiError ? exception.first : 'Kaspi не ответил'
  } finally {
    syncing.value = false
  }
}
</script>

<template>
  <div class="space-y-4">
    <div class="flex flex-wrap items-center gap-3">
      <h1 class="text-xl font-semibold text-slate-900">Заказы Kaspi</h1>
      <span v-if="result" class="text-sm text-slate-500">{{ result.meta.total }}</span>
      <span
        v-if="result?.meta.mode === 'standalone'"
        class="rounded-full bg-amber-50 px-2.5 py-0.5 text-xs text-amber-800 ring-1 ring-amber-200"
        >Режим без 1С</span
      >
      <div class="ml-auto flex flex-wrap items-center gap-2">
        <span v-if="result?.meta.last_synced_at" class="text-xs text-slate-500">
          Обновлено {{ formatDateTime(result.meta.last_synced_at) }}
        </span>
        <button class="btn btn-secondary" :disabled="syncing" @click="syncNow">
          {{ syncing ? 'Запрашиваю Kaspi…' : 'Обновить из Kaspi' }}
        </button>
      </div>
    </div>
    <p v-if="syncError" class="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700">{{ syncError }}</p>

    <button
      v-if="result?.meta.problems"
      class="flex w-full items-center gap-2 rounded-lg bg-amber-50 px-4 py-2.5 text-left text-sm text-amber-900 ring-1 ring-amber-200"
      @click="setQuery({ problem: filters.problem ? undefined : '1' })"
    >
      ⚠ {{ result.meta.problems }} заказ(ов) с товарами, которых нет в каталоге: они не ушли в 1С.
      <span class="ml-auto font-medium">{{ filters.problem ? 'Показать все' : 'Показать' }}</span>
    </button>

    <div class="flex flex-wrap items-center gap-2">
      <div class="flex flex-wrap gap-1">
        <button
          class="rounded-full px-3 py-1 text-sm transition"
          :class="!filters.state ? 'bg-slate-900 text-white' : 'bg-white text-slate-600 ring-1 ring-slate-200 hover:bg-slate-100'"
          @click="setQuery({ state: '' })"
        >
          Все
        </button>
        <button
          v-for="state in result?.meta.states ?? []"
          :key="state.value"
          class="flex items-center gap-1.5 rounded-full px-3 py-1 text-sm transition"
          :class="
            filters.state === state.value
              ? 'bg-slate-900 text-white'
              : 'bg-white text-slate-600 ring-1 ring-slate-200 hover:bg-slate-100'
          "
          @click="setQuery({ state: state.value })"
        >
          {{ state.label }}
          <span
            class="rounded-full px-1.5 text-xs tabular-nums"
            :class="state.value === 'NEW' && state.count ? 'bg-red-500 text-white' : 'bg-slate-100 text-slate-500'"
            >{{ state.count }}</span
          >
        </button>
      </div>
      <input v-model="search" type="search" placeholder="Номер, покупатель, телефон" class="field ml-auto max-w-xs" />
    </div>

    <div class="card overflow-x-auto">
      <Spinner v-if="!result" />
      <p v-else-if="!result.data.length" class="py-16 text-center text-slate-500">Заказов нет</p>
      <table v-else class="w-full text-sm transition" :class="{ 'opacity-60': loading }">
        <thead class="bg-slate-50">
          <tr>
            <th class="th">Заказ</th>
            <th class="th">Покупатель</th>
            <th class="th hidden md:table-cell">Доставка</th>
            <th class="th">Статус</th>
            <th class="th hidden lg:table-cell">1С</th>
            <th class="th text-right">Сумма</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-slate-100">
          <tr
            v-for="order in result.data"
            :key="order.id"
            class="cursor-pointer hover:bg-slate-50"
            @click="router.push({ name: 'kaspi-order', params: { id: order.id } })"
          >
            <td class="px-4 py-3 whitespace-nowrap">
              <div class="font-medium text-brand-700">{{ order.code }}</div>
              <div class="text-xs text-slate-500">{{ formatDateTime(order.created_at) }}</div>
            </td>
            <td class="px-4 py-3">
              <div class="text-slate-800">{{ order.customer_name }}</div>
              <div class="text-xs text-slate-500">{{ order.items_count }} поз.</div>
            </td>
            <td class="hidden px-4 py-3 md:table-cell">
              <span class="mr-1">{{ deliveryIcons[order.delivery] }}</span>{{ order.delivery_label }}
              <span v-if="order.express" class="ml-1 rounded bg-red-50 px-1 text-xs text-red-700">экспресс</span>
              <div v-if="order.waybill_number" class="text-xs text-slate-500">{{ order.waybill_number }}</div>
            </td>
            <td class="px-4 py-3">
              <KaspiStatusBadge :status="order.status" :label="order.status_label" />
              <div v-if="order.problem" class="mt-1 text-xs text-amber-700">⚠ товар не сопоставлен</div>
            </td>
            <td class="hidden px-4 py-3 text-xs whitespace-nowrap lg:table-cell">
              <span v-if="order.core_order" class="text-slate-700">{{ order.core_order.number }}</span>
              <span v-else class="text-slate-400">-</span>
            </td>
            <td class="px-4 py-3 text-right font-medium whitespace-nowrap tabular-nums">{{ formatMoney(order.total) }}</td>
          </tr>
        </tbody>
      </table>
    </div>

    <Pager v-if="result" :page="result.meta.current_page" :last="result.meta.last_page" @change="(page) => setQuery({ page })" />
  </div>
</template>
