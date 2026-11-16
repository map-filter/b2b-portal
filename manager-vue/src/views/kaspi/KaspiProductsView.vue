<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { ApiError, api, download, toQuery } from '../../api/client'
import type { KaspiProductRow, KaspiProductsPage, KaspiSettings } from '../../api/kaspi'
import Pager from '../../components/Pager.vue'
import Spinner from '../../components/Spinner.vue'
import { formatMoney } from '../../lib/format'

type Filter = 'all' | 'out_of_stock' | 'unpublished' | 'unlinked'

const route = useRoute()
const router = useRouter()
const result = ref<KaspiProductsPage | null>(null)
const loading = ref(false)
const search = ref(String(route.query.q ?? ''))
const selected = ref<number[]>([])
const message = ref<{ ok: boolean; text: string } | null>(null)
const priceListUrl = ref('')
const fileInput = ref<HTMLInputElement | null>(null)

const tabs: { value: Filter; label: string }[] = [
  { value: 'all', label: 'На Kaspi' },
  { value: 'out_of_stock', label: 'Нет в наличии' },
  { value: 'unpublished', label: 'Скрыты' },
  { value: 'unlinked', label: 'Не выставлены' },
]

const filters = computed(() => ({
  filter: (String(route.query.filter ?? 'all') as Filter) || 'all',
  q: String(route.query.q ?? ''),
  page: Number(route.query.page ?? 1),
}))
const standalone = computed(() => result.value?.meta.mode === 'standalone')

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
  result.value = await api<KaspiProductsPage>(`/manager/kaspi/products${toQuery(filters.value)}`)
  selected.value = []
  loading.value = false
}

watch(filters, load, { immediate: true })
api<{ data: KaspiSettings }>('/manager/kaspi/settings').then((r) => (priceListUrl.value = r.data.pricelist_url))

function notify(ok: boolean, text: string) {
  message.value = { ok, text }
  setTimeout(() => (message.value = null), 5000)
}

async function save(row: KaspiProductRow, patch: Record<string, unknown>) {
  try {
    await api(`/manager/kaspi/products/${row.id}`, { method: 'PATCH', json: patch })
    await load()
  } catch (exception) {
    notify(false, exception instanceof ApiError ? exception.first : 'Не сохранилось')
  }
}

function savePrice(row: KaspiProductRow, event: Event) {
  const value = Number((event.target as HTMLInputElement).value)
  if (value > 0 && value !== Number(row.price)) save(row, { price: value })
}

function saveStock(row: KaspiProductRow, storeId: string, event: Event) {
  const value = Number((event.target as HTMLInputElement).value)
  if (value >= 0 && value !== row.stocks[storeId]) save(row, { stocks: { [storeId]: value } })
}

async function link(ids: number[]) {
  const response = await api<{ linked: number }>('/manager/kaspi/products', { method: 'POST', json: { product_ids: ids } })
  notify(true, `Выставлено на Kaspi: ${response.linked}. Kaspi заберёт их с прайс-листом.`)
  await load()
}

async function unlink(row: KaspiProductRow) {
  await api(`/manager/kaspi/products/${row.id}`, { method: 'DELETE' })
  await load()
}

async function importFile(event: Event) {
  const file = (event.target as HTMLInputElement).files?.[0]
  if (!file) return
  const body = new FormData()
  body.append('file', file)
  try {
    const r = await api<{ updated: number; linked: number; not_found: { sku: string }[]; invalid: { reason: string }[] }>(
      '/manager/kaspi/products/import',
      { method: 'POST', body },
    )
    const extra = [
      r.linked ? `новых на Kaspi ${r.linked}` : '',
      r.not_found.length
        ? `не найдено ${r.not_found.length} (${r.not_found
            .slice(0, 3)
            .map((x) => x.sku)
            .join(', ')})`
        : '',
      r.invalid.length ? `с ошибкой ${r.invalid.length}` : '',
    ].filter(Boolean)
    notify(true, `Обновлено ${r.updated}${extra.length ? ', ' + extra.join(', ') : ''}`)
    await load()
  } catch (exception) {
    notify(false, exception instanceof ApiError ? exception.first : 'Файл не загрузился')
  } finally {
    if (fileInput.value) fileInput.value.value = ''
  }
}

async function copyUrl() {
  await navigator.clipboard?.writeText(priceListUrl.value).catch(() => undefined)
  notify(
    true,
    'Ссылка на прайс-лист скопирована: вставьте её в кабинете Kaspi, раздел «Товары → Загрузить прайс-лист → по ссылке»',
  )
}

const allSelected = computed({
  get: () => !!result.value?.data.length && selected.value.length === result.value.data.length,
  set: (value) => (selected.value = value ? (result.value?.data.map((r) => r.product_id) ?? []) : []),
})
</script>

<template>
  <div class="space-y-4">
    <div class="flex flex-wrap items-center gap-3">
      <h1 class="text-xl font-semibold text-slate-900">Товары Kaspi</h1>
      <span v-if="result" class="text-sm text-slate-500">{{ result.meta.total }}</span>
      <div class="ml-auto flex flex-wrap gap-2">
        <button class="btn btn-secondary" @click="copyUrl">Ссылка на прайс-лист</button>
        <button class="btn btn-secondary" @click="download('/manager/kaspi/products/template', 'kaspi-tovary.xlsx')">
          Выгрузить в Excel
        </button>
        <button class="btn btn-primary" @click="fileInput?.click()">Загрузить Excel</button>
        <input ref="fileInput" type="file" accept=".xlsx,.xls,.csv" class="hidden" @change="importFile" />
      </div>
    </div>

    <p class="text-sm text-slate-500">
      <template v-if="standalone"
        >Режим без 1С: остатки правятся прямо в таблице или файлом Excel (Артикул, Цена, Остаток).</template
      >
      <template v-else
        >Остатки приходят из 1С через обмен, в Kaspi уходят прайс-листом.
        {{ result?.meta.price_from_1c ? 'Цены берутся из типа цен 1С.' : 'Цены для Kaspi задаются здесь.' }}</template
      >
    </p>

    <p
      v-if="message"
      class="rounded-lg px-3 py-2 text-sm"
      :class="message.ok ? 'bg-emerald-50 text-emerald-800' : 'bg-red-50 text-red-700'"
    >
      {{ message.text }}
    </p>

    <div class="flex flex-wrap items-center gap-2">
      <div class="flex flex-wrap gap-1">
        <button
          v-for="tab in tabs"
          :key="tab.value"
          class="flex items-center gap-1.5 rounded-full px-3 py-1 text-sm transition"
          :class="
            filters.filter === tab.value
              ? 'bg-slate-900 text-white'
              : 'bg-white text-slate-600 ring-1 ring-slate-200 hover:bg-slate-100'
          "
          @click="setQuery({ filter: tab.value === 'all' ? undefined : tab.value })"
        >
          {{ tab.label }}
          <span
            class="rounded-full px-1.5 text-xs tabular-nums"
            :class="
              tab.value === 'out_of_stock' && result?.meta.counts.out_of_stock
                ? 'bg-red-500 text-white'
                : 'bg-slate-100 text-slate-500'
            "
            >{{ result?.meta.counts[tab.value] ?? '' }}</span
          >
        </button>
      </div>
      <input v-model="search" type="search" placeholder="Название, артикул, бренд" class="field ml-auto max-w-xs" />
    </div>

    <div
      v-if="filters.filter === 'unlinked' && selected.length"
      class="flex items-center gap-3 rounded-lg bg-brand-50 px-4 py-2 text-sm"
    >
      Выбрано {{ selected.length }}
      <button class="btn btn-primary ml-auto" @click="link(selected)">Выставить на Kaspi</button>
    </div>

    <div class="card overflow-x-auto">
      <Spinner v-if="!result" />
      <p v-else-if="!result.data.length" class="py-16 text-center text-slate-500">Товаров нет</p>
      <table v-else class="w-full text-sm transition" :class="{ 'opacity-60': loading }">
        <thead class="bg-slate-50">
          <tr>
            <th v-if="filters.filter === 'unlinked'" class="th w-8"><input v-model="allSelected" type="checkbox" /></th>
            <th class="th">Товар</th>
            <th class="th text-right">Цена Kaspi</th>
            <th v-for="store in result.meta.stores" :key="store.store_id" class="th text-right" :title="store.name">
              Остаток {{ store.store_id }}
            </th>
            <th class="th hidden text-right md:table-cell">Продано 30 дн.</th>
            <th class="th text-right"></th>
          </tr>
        </thead>
        <tbody class="divide-y divide-slate-100">
          <tr
            v-for="row in result.data"
            :key="row.product_id"
            :class="{ 'bg-slate-50/60 text-slate-500': row.linked && !row.is_published }"
          >
            <td v-if="filters.filter === 'unlinked'" class="px-4 py-3">
              <input v-model="selected" type="checkbox" :value="row.product_id" />
            </td>
            <td class="px-4 py-3">
              <div class="text-slate-800">{{ row.name }}</div>
              <div class="text-xs text-slate-500">
                {{ row.sku }}<template v-if="row.brand"> · {{ row.brand }}</template>
              </div>
            </td>
            <td class="px-4 py-3 text-right whitespace-nowrap">
              <input
                v-if="row.linked && row.price_source === 'manual'"
                :value="Math.round(Number(row.price))"
                type="number"
                min="1"
                class="field w-28 text-right tabular-nums"
                @change="savePrice(row, $event)"
              />
              <span v-else class="tabular-nums">{{ formatMoney(row.price) }}</span>
              <div v-if="row.price_source === '1c'" class="text-xs text-slate-400">из 1С</div>
            </td>
            <td
              v-for="store in result.meta.stores"
              :key="store.store_id"
              class="px-4 py-3 text-right whitespace-nowrap tabular-nums"
            >
              <input
                v-if="standalone && row.linked"
                :value="row.stocks[store.store_id] ?? 0"
                type="number"
                min="0"
                class="field w-20 text-right"
                :class="{ 'ring-red-300': !row.stocks[store.store_id] }"
                @change="saveStock(row, store.store_id, $event)"
              />
              <span v-else :class="{ 'font-medium text-red-600': !row.stocks[store.store_id] }">{{
                row.stocks[store.store_id] ?? 0
              }}</span>
            </td>
            <td class="hidden px-4 py-3 text-right tabular-nums md:table-cell">{{ row.sold_30d || '' }}</td>
            <td class="px-4 py-3 text-right whitespace-nowrap">
              <template v-if="row.linked">
                <button class="text-xs text-brand-700 hover:underline" @click="save(row, { is_published: !row.is_published })">
                  {{ row.is_published ? 'Скрыть' : 'Показать' }}
                </button>
                <button class="ml-3 text-xs text-slate-400 hover:text-red-600" title="Убрать с Kaspi" @click="unlink(row)">
                  ✕
                </button>
              </template>
              <button v-else class="text-xs text-brand-700 hover:underline" @click="link([row.product_id])">Выставить</button>
            </td>
          </tr>
        </tbody>
      </table>
    </div>

    <Pager v-if="result" :page="result.meta.current_page" :last="result.meta.last_page" @change="(page) => setQuery({ page })" />
  </div>
</template>
