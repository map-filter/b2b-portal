<script setup lang="ts">
import { computed, reactive, ref, watch } from 'vue'
import { ApiError, api, toQuery } from '../api/client'
import type { CompanyDetails, ContractPrice, Paginated, ProductLookup } from '../api/types'
import Spinner from '../components/Spinner.vue'
import { formatDate, formatMoney } from '../lib/format'

const props = defineProps<{ id: string }>()
const company = ref<CompanyDetails | null>(null)
const prices = ref<ContractPrice[]>([])
const tab = ref<'terms' | 'users' | 'prices'>('terms')
const notice = ref<{ type: 'ok' | 'error'; text: string } | null>(null)

const form = reactive({
  manager_id: null as number | null,
  credit_limit: '0',
  payment_delay_days: 0,
  is_active: true,
  contract: { price_type_id: 0, discount_percent: '0', ends_at: '' as string | null },
})

function fill(data: CompanyDetails) {
  company.value = data
  form.manager_id = data.manager?.id ?? null
  form.credit_limit = data.credit_limit
  form.payment_delay_days = data.payment_delay_days
  form.is_active = data.is_active
  form.contract.price_type_id = data.contract?.price_type_id ?? 0
  form.contract.discount_percent = data.contract?.discount_percent ?? '0'
  form.contract.ends_at = data.contract?.ends_at ?? ''
}

async function load() {
  const [details, contractPrices] = await Promise.all([
    api<{ data: CompanyDetails }>(`/manager/companies/${props.id}`),
    api<{ data: ContractPrice[] }>(`/manager/companies/${props.id}/prices`),
  ])
  fill(details.data)
  prices.value = contractPrices.data
}

watch(() => props.id, load, { immediate: true })

function flash(type: 'ok' | 'error', text: string) {
  notice.value = { type, text }
  setTimeout(() => (notice.value = null), 4000)
}

async function save() {
  try {
    const result = await api<{ data: CompanyDetails }>(`/manager/companies/${props.id}`, {
      method: 'PUT',
      json: { ...form, contract: { ...form.contract, ends_at: form.contract.ends_at || null } },
    })
    fill(result.data)
    flash('ok', 'Условия сохранены')
  } catch (exception) {
    flash('error', exception instanceof ApiError ? exception.first : 'Ошибка')
  }
}

const usedCredit = computed(() => {
  if (!company.value || Number(company.value.credit_limit) <= 0) return null
  return Math.min(100, Math.round((Number(company.value.open_total) / Number(company.value.credit_limit)) * 100))
})

const newUser = reactive({ name: '', email: '', phone: '', role: 'client_buyer' })
const createdPassword = ref<string | null>(null)

async function addUser() {
  try {
    const result = await api<{ data: { email: string; password: string } }>(`/manager/companies/${props.id}/users`, {
      method: 'POST',
      json: newUser,
    })
    createdPassword.value = `${result.data.email} / ${result.data.password}`
    Object.assign(newUser, { name: '', email: '', phone: '', role: 'client_buyer' })
    await load()
  } catch (exception) {
    flash('error', exception instanceof ApiError ? exception.first : 'Ошибка')
  }
}

async function toggleUser(userId: number) {
  await api(`/manager/companies/${props.id}/users/${userId}/toggle`, { method: 'PATCH' })
  await load()
}

const lookup = ref('')
const found = ref<ProductLookup[]>([])
let timer: ReturnType<typeof setTimeout>

watch(lookup, (value) => {
  clearTimeout(timer)
  if (value.trim().length < 2) return (found.value = [])
  timer = setTimeout(async () => {
    found.value = await api<Paginated<ProductLookup>>(`/catalog/products${toQuery({ q: value.trim(), per_page: 8 })}`).then(
      (r) => r.data,
    )
  }, 300)
})

async function setPrice(productId: number, price: string | number) {
  try {
    prices.value = await api<{ data: ContractPrice[] }>(`/manager/companies/${props.id}/prices/${productId}`, {
      method: 'PUT',
      json: { price: Number(price) },
    }).then((r) => r.data)
    lookup.value = ''
    found.value = []
  } catch (exception) {
    flash('error', exception instanceof ApiError ? exception.first : 'Ошибка')
  }
}

async function removePrice(productId: number) {
  await api(`/manager/companies/${props.id}/prices/${productId}`, { method: 'DELETE' })
  prices.value = prices.value.filter((p) => p.product_id !== productId)
}

const discount = (price: ContractPrice) => Math.round((1 - Number(price.price) / Number(price.standard_price)) * 100)
</script>

<template>
  <Spinner v-if="!company" />
  <div v-else class="space-y-4">
    <div class="flex flex-wrap items-center gap-3">
      <RouterLink :to="{ name: 'companies' }" class="text-sm text-slate-500 hover:text-slate-800">← Клиенты</RouterLink>
      <h1 class="text-xl font-semibold text-slate-900">{{ company.legal_name }}</h1>
      <span v-if="!company.is_active" class="rounded-full bg-red-50 px-2.5 py-0.5 text-xs text-red-700">отключена</span>
      <RouterLink :to="{ name: 'orders', query: { company: company.id } }" class="btn btn-secondary ml-auto"
        >Заказы клиента</RouterLink
      >
    </div>

    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
      <div class="card p-4">
        <div class="text-xs text-slate-500">Выручка за всё время</div>
        <div class="text-lg font-semibold tabular-nums">{{ formatMoney(company.revenue) }}</div>
      </div>
      <div class="card p-4">
        <div class="text-xs text-slate-500">Заказов в работе</div>
        <div class="text-lg font-semibold tabular-nums">{{ company.open_orders }} на {{ formatMoney(company.open_total) }}</div>
      </div>
      <div class="card p-4">
        <div class="text-xs text-slate-500">Кредитный лимит</div>
        <div class="text-lg font-semibold tabular-nums">
          {{ Number(company.credit_limit) > 0 ? formatMoney(company.credit_limit) : 'предоплата' }}
        </div>
        <div v-if="usedCredit !== null" class="mt-2 h-1.5 overflow-hidden rounded-full bg-slate-100">
          <div
            class="h-full rounded-full"
            :class="usedCredit > 80 ? 'bg-red-500' : 'bg-brand-500'"
            :style="{ width: `${usedCredit}%` }"
          />
        </div>
      </div>
      <div class="card p-4">
        <div class="text-xs text-slate-500">Последний заказ</div>
        <div class="text-lg font-semibold">{{ company.last_order_at ? formatDate(company.last_order_at) : 'нет' }}</div>
      </div>
    </div>

    <div class="flex gap-1 border-b border-slate-200">
      <button
        v-for="item in [
          { key: 'terms', label: 'Условия' },
          { key: 'users', label: `Сотрудники (${company.users.length})` },
          { key: 'prices', label: `Индивидуальные цены (${prices.length})` },
        ] as const"
        :key="item.key"
        class="-mb-px border-b-2 px-4 py-2 text-sm"
        :class="
          tab === item.key
            ? 'border-brand-600 font-medium text-brand-700'
            : 'border-transparent text-slate-500 hover:text-slate-800'
        "
        @click="tab = item.key"
      >
        {{ item.label }}
      </button>
    </div>

    <p
      v-if="notice"
      class="rounded-lg px-3 py-2 text-sm"
      :class="notice.type === 'ok' ? 'bg-emerald-50 text-emerald-800' : 'bg-red-50 text-red-700'"
    >
      {{ notice.text }}
    </p>

    <form v-if="tab === 'terms'" class="grid gap-4 lg:grid-cols-2" @submit.prevent="save">
      <div class="card space-y-3 p-5 text-sm">
        <h2 class="font-semibold text-slate-800">Реквизиты</h2>
        <div class="flex justify-between gap-4">
          <span class="text-slate-500">БИН</span><span>{{ company.bin }}</span>
        </div>
        <div class="flex justify-between gap-4">
          <span class="text-slate-500">Адрес</span><span class="text-right">{{ company.legal_address }}</span>
        </div>
        <div class="flex justify-between gap-4">
          <span class="text-slate-500">Телефон</span><span>{{ company.phone }}</span>
        </div>
        <div class="flex justify-between gap-4">
          <span class="text-slate-500">Email</span><span>{{ company.email }}</span>
        </div>
        <label class="block pt-2">
          <span class="text-slate-500">Менеджер</span>
          <select v-model="form.manager_id" class="field mt-1">
            <option :value="null">Не назначен</option>
            <option v-for="manager in company.managers" :key="manager.id" :value="manager.id">{{ manager.name }}</option>
          </select>
        </label>
        <label class="flex items-center gap-2 pt-1">
          <input v-model="form.is_active" type="checkbox" class="accent-brand-600" />
          Компания активна (может заказывать)
        </label>
      </div>

      <div class="card space-y-3 p-5 text-sm">
        <h2 class="font-semibold text-slate-800">Договор {{ company.contract?.number }}</h2>
        <div class="grid grid-cols-2 gap-3">
          <label class="block">
            <span class="text-slate-500">Вид цен</span>
            <select v-model="form.contract.price_type_id" class="field mt-1">
              <option v-for="type in company.price_types" :key="type.id" :value="type.id">{{ type.name }}</option>
            </select>
          </label>
          <label class="block">
            <span class="text-slate-500">Скидка, %</span>
            <input v-model="form.contract.discount_percent" type="number" step="0.5" min="0" max="50" class="field mt-1" />
          </label>
          <label class="block">
            <span class="text-slate-500">Кредитный лимит, ₸</span>
            <input v-model="form.credit_limit" type="number" min="0" step="10000" class="field mt-1" />
          </label>
          <label class="block">
            <span class="text-slate-500">Отсрочка, дней</span>
            <input v-model.number="form.payment_delay_days" type="number" min="0" max="365" class="field mt-1" />
          </label>
          <label class="col-span-2 block">
            <span class="text-slate-500">Договор действует до</span>
            <input v-model="form.contract.ends_at" type="date" class="field mt-1" />
          </label>
        </div>
        <button class="btn btn-primary">Сохранить</button>
      </div>
    </form>

    <div v-else-if="tab === 'users'" class="grid items-start gap-4 lg:grid-cols-[1fr_340px]">
      <div class="card overflow-x-auto">
        <table class="w-full text-sm">
          <thead class="bg-slate-50">
            <tr>
              <th class="th">Сотрудник</th>
              <th class="th">Роль</th>
              <th class="th text-right">Доступ</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-slate-100">
            <tr v-for="user in company.users" :key="user.id">
              <td class="px-4 py-3">
                <div :class="user.is_active ? 'text-slate-800' : 'text-slate-400 line-through'">{{ user.name }}</div>
                <div class="text-xs text-slate-500">{{ user.email }}</div>
              </td>
              <td class="px-4 py-3 text-slate-600">{{ user.role_label }}</td>
              <td class="px-4 py-3 text-right">
                <button class="btn btn-secondary py-1 text-xs" @click="toggleUser(user.id)">
                  {{ user.is_active ? 'Заблокировать' : 'Включить' }}
                </button>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
      <form class="card space-y-3 p-5 text-sm" @submit.prevent="addUser">
        <h2 class="font-semibold text-slate-800">Добавить сотрудника клиента</h2>
        <input v-model="newUser.name" class="field" placeholder="Имя" required />
        <input v-model="newUser.email" type="email" class="field" placeholder="Email" required />
        <input v-model="newUser.phone" class="field" placeholder="Телефон" />
        <select v-model="newUser.role" class="field">
          <option value="client_buyer">Закупщик</option>
          <option value="client_admin">Руководитель</option>
        </select>
        <button class="btn btn-primary w-full">Создать доступ</button>
        <p v-if="createdPassword" class="rounded-lg bg-emerald-50 px-3 py-2 text-emerald-800">
          Доступ создан, передайте клиенту:<br /><code class="font-mono">{{ createdPassword }}</code>
        </p>
      </form>
    </div>

    <div v-else class="space-y-4">
      <div class="card relative p-4">
        <input v-model="lookup" class="field" placeholder="Найти товар по названию или артикулу, чтобы назначить цену" />
        <div
          v-if="found.length"
          class="absolute inset-x-4 top-full z-10 mt-1 divide-y divide-slate-100 rounded-lg bg-white shadow-lg ring-1 ring-slate-200"
        >
          <form
            v-for="product in found"
            :key="product.id"
            class="flex items-center gap-3 px-3 py-2 text-sm"
            @submit.prevent="setPrice(product.id, ($event.target as HTMLFormElement).price.value)"
          >
            <div class="min-w-0 flex-1">
              <div class="truncate text-slate-800">{{ product.name }}</div>
              <div class="text-xs text-slate-500">{{ product.sku }} · базовая {{ formatMoney(product.base_price) }}</div>
            </div>
            <input
              name="price"
              type="number"
              step="0.01"
              min="0.01"
              class="field w-32"
              :placeholder="product.base_price"
              required
            />
            <button class="btn btn-primary py-1.5">Назначить</button>
          </form>
        </div>
      </div>

      <div class="card overflow-x-auto">
        <p v-if="!prices.length" class="py-12 text-center text-sm text-slate-500">
          Индивидуальных цен нет, клиент покупает по виду цен договора
        </p>
        <table v-else class="w-full text-sm">
          <thead class="bg-slate-50">
            <tr>
              <th class="th">Товар</th>
              <th class="th text-right">Базовая</th>
              <th class="th text-right">По договору</th>
              <th class="th text-right">Индивидуальная</th>
              <th class="th"></th>
            </tr>
          </thead>
          <tbody class="divide-y divide-slate-100">
            <tr v-for="price in prices" :key="price.product_id">
              <td class="px-4 py-2.5">
                <div class="text-slate-800">{{ price.name }}</div>
                <div class="text-xs text-slate-500">{{ price.sku }}</div>
              </td>
              <td class="px-4 py-2.5 text-right whitespace-nowrap text-slate-500 tabular-nums">
                {{ formatMoney(price.base_price) }}
              </td>
              <td class="px-4 py-2.5 text-right whitespace-nowrap text-slate-500 tabular-nums">
                {{ formatMoney(price.standard_price) }}
              </td>
              <td class="px-4 py-2.5 text-right">
                <div class="flex items-center justify-end gap-2">
                  <span class="text-xs whitespace-nowrap" :class="discount(price) > 0 ? 'text-emerald-600' : 'text-red-600'"
                    >{{ discount(price) > 0 ? '−' : '+' }}{{ Math.abs(discount(price)) }}%</span
                  >
                  <input
                    :value="price.price"
                    type="number"
                    step="0.01"
                    class="field w-32 text-right tabular-nums"
                    @change="setPrice(price.product_id, ($event.target as HTMLInputElement).value)"
                  />
                </div>
              </td>
              <td class="px-4 py-2.5 text-right">
                <button
                  class="rounded-md px-2 py-1 text-slate-400 hover:bg-red-50 hover:text-red-600"
                  aria-label="Удалить"
                  @click="removePrice(price.product_id)"
                >
                  ✕
                </button>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</template>
