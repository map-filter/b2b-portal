<script setup lang="ts">
import { ref } from 'vue'
import { ApiError, api } from '../../api/client'
import type { KaspiSettings, KaspiStoreRow } from '../../api/kaspi'
import Spinner from '../../components/Spinner.vue'
import { formatDateTime } from '../../lib/format'

const settings = ref<KaspiSettings | null>(null)
const form = ref<Record<string, unknown>>({})
const stores = ref<KaspiStoreRow[]>([])
const token = ref('')
const saving = ref(false)
const syncing = ref(false)
const message = ref<{ ok: boolean; text: string } | null>(null)

function apply(data: KaspiSettings) {
  settings.value = data
  form.value = {
    mode: data.mode,
    api_url: data.api_url_custom ?? '',
    merchant_id: data.merchant_id ?? '',
    company_name: data.company_name ?? '',
    price_type_id: data.price_type_id,
    notify_user_id: data.notify_user_id,
    poll_minutes: data.poll_minutes,
    sync_days: data.sync_days,
    auto_accept: data.auto_accept,
    is_enabled: data.is_enabled,
  }
  stores.value = data.stores.map((s) => ({ ...s }))
}

api<{ data: KaspiSettings }>('/manager/kaspi/settings').then((r) => apply(r.data))

function notify(ok: boolean, text: string) {
  message.value = { ok, text }
  setTimeout(() => (message.value = null), 6000)
}

async function save() {
  saving.value = true
  try {
    const json = {
      ...form.value,
      api_url: form.value.api_url || null,
      stores: stores.value,
      ...(token.value ? { api_token: token.value } : {}),
    }
    apply(await api<{ data: KaspiSettings }>('/manager/kaspi/settings', { method: 'PUT', json }).then((r) => r.data))
    token.value = ''
    notify(true, 'Настройки сохранены')
  } catch (exception) {
    notify(false, exception instanceof ApiError ? exception.first : 'Не сохранилось')
  } finally {
    saving.value = false
  }
}

async function syncNow() {
  syncing.value = true
  try {
    const run = await api<{ data: { fetched: number; created: number; updated: number } }>('/manager/kaspi/sync', {
      method: 'POST',
    })
    notify(true, `Kaspi ответил: заказов ${run.data.fetched}, новых ${run.data.created}, изменилось ${run.data.updated}`)
    apply(await api<{ data: KaspiSettings }>('/manager/kaspi/settings').then((r) => r.data))
  } catch (exception) {
    notify(false, exception instanceof ApiError ? exception.first : 'Kaspi не ответил')
  } finally {
    syncing.value = false
  }
}

function addStore() {
  stores.value.push({ store_id: '', name: '', warehouse_id: settings.value?.warehouses[0]?.id ?? 0, is_active: true })
}
</script>

<template>
  <Spinner v-if="!settings" />
  <div v-else class="max-w-4xl space-y-5">
    <div class="flex flex-wrap items-center gap-3">
      <h1 class="text-xl font-semibold text-slate-900">Настройки Kaspi</h1>
      <div class="ml-auto flex gap-2">
        <button class="btn btn-secondary" :disabled="syncing" @click="syncNow">
          {{ syncing ? 'Проверяю…' : 'Проверить связь' }}
        </button>
        <button class="btn btn-primary" :disabled="saving" @click="save">Сохранить</button>
      </div>
    </div>

    <p
      v-if="message"
      class="rounded-lg px-3 py-2 text-sm"
      :class="message.ok ? 'bg-emerald-50 text-emerald-800' : 'bg-red-50 text-red-700'"
    >
      {{ message.text }}
    </p>

    <section class="card space-y-3 p-5">
      <h2 class="font-semibold text-slate-800">Режим работы</h2>
      <label
        v-for="mode in settings.modes"
        :key="mode.value"
        class="flex cursor-pointer items-start gap-3 rounded-lg p-3 ring-1 ring-slate-200 has-checked:ring-brand-500"
      >
        <input v-model="form.mode" type="radio" :value="mode.value" class="mt-1 accent-brand-600" />
        <span>
          <span class="block font-medium text-slate-800">{{ mode.value === 'one_c' ? 'С 1С' : 'Без 1С' }}</span>
          <span class="text-sm text-slate-500">{{ mode.label }}</span>
        </span>
      </label>
      <label v-if="form.mode === 'one_c'" class="block text-sm">
        <span class="text-slate-600">Цена для Kaspi из типа цен 1С</span>
        <select v-model="form.price_type_id" class="field mt-1">
          <option :value="null">Не брать из 1С, задаю в разделе «Товары Kaspi»</option>
          <option v-for="type in settings.price_types" :key="type.id" :value="type.id">{{ type.name }}</option>
        </select>
      </label>
    </section>

    <section class="card grid gap-4 p-5 sm:grid-cols-2">
      <h2 class="font-semibold text-slate-800 sm:col-span-2">Подключение к Kaspi API</h2>
      <label class="block text-sm sm:col-span-2">
        <span class="text-slate-600">Токен из кабинета продавца (Настройки → Токен API)</span>
        <input
          v-model="token"
          type="password"
          autocomplete="off"
          class="field mt-1"
          :placeholder="settings.token_set ? `Сохранён: ${settings.token_hint}` : 'Не задан'"
        />
      </label>
      <label class="block text-sm">
        <span class="text-slate-600">Адрес API</span>
        <input v-model="form.api_url" class="field mt-1" :placeholder="settings.api_url" />
      </label>
      <label class="block text-sm">
        <span class="text-slate-600">ID продавца (merchantid в прайс-листе)</span>
        <input v-model="form.merchant_id" class="field mt-1" />
      </label>
      <label class="block text-sm">
        <span class="text-slate-600">Название магазина в прайс-листе</span>
        <input v-model="form.company_name" class="field mt-1" />
      </label>
      <label class="block text-sm">
        <span class="text-slate-600">Опрашивать Kaspi каждые, мин.</span>
        <input v-model.number="form.poll_minutes" type="number" min="1" max="60" class="field mt-1" />
      </label>
      <label class="block text-sm">
        <span class="text-slate-600">Глубина синхронизации, дней</span>
        <input v-model.number="form.sync_days" type="number" min="1" max="90" class="field mt-1" />
      </label>
      <div class="space-y-2 text-sm">
        <label class="flex items-center gap-2"
          ><input v-model="form.is_enabled" type="checkbox" class="accent-brand-600" /> Забирать заказы автоматически</label
        >
        <label class="flex items-center gap-2"
          ><input v-model="form.auto_accept" type="checkbox" class="accent-brand-600" /> Автоприём, если всё есть на складе</label
        >
      </div>
      <label class="block text-sm sm:col-span-2">
        <span class="text-slate-600">Новые заказы в Telegram</span>
        <select v-model="form.notify_user_id" class="field mt-1">
          <option :value="null">Никому</option>
          <option v-for="user in settings.staff" :key="user.id" :value="user.id">
            {{ user.name }}{{ user.telegram ? '' : ' (Telegram не привязан)' }}
          </option>
        </select>
      </label>
      <div class="rounded-lg bg-slate-50 p-3 text-sm sm:col-span-2">
        <div class="text-slate-600">Ссылка на прайс-лист для кабинета Kaspi</div>
        <code class="mt-1 block break-all text-slate-800">{{ settings.pricelist_url }}</code>
      </div>
    </section>

    <section class="card space-y-3 p-5">
      <div class="flex items-center">
        <h2 class="font-semibold text-slate-800">Склады Kaspi (точки выдачи)</h2>
        <button class="btn btn-secondary ml-auto" @click="addStore">Добавить</button>
      </div>
      <p class="text-sm text-slate-500">storeId из кабинета Kaspi и склад портала, с которого берутся остатки для этой точки.</p>
      <div v-for="(store, index) in stores" :key="index" class="grid gap-2 sm:grid-cols-[120px_1fr_1fr_auto]">
        <input v-model="store.store_id" class="field" placeholder="PP1" />
        <input v-model="store.name" class="field" placeholder="Название" />
        <select v-model="store.warehouse_id" class="field">
          <option v-for="w in settings.warehouses" :key="w.id" :value="w.id">{{ w.name }}</option>
        </select>
        <button class="btn btn-secondary" title="Удалить" @click="stores.splice(index, 1)">✕</button>
      </div>
    </section>

    <section class="card overflow-x-auto">
      <h2 class="px-5 pt-5 pb-3 font-semibold text-slate-800">Журнал синхронизации</h2>
      <table class="w-full text-sm">
        <thead class="bg-slate-50">
          <tr>
            <th class="th">Время</th>
            <th class="th">Запуск</th>
            <th class="th text-right">Запросов</th>
            <th class="th text-right">Заказов</th>
            <th class="th text-right">Новых</th>
            <th class="th text-right">Изменилось</th>
            <th class="th">Итог</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-slate-100">
          <tr v-for="run in settings.runs" :key="run.id">
            <td class="px-4 py-2 whitespace-nowrap">{{ formatDateTime(run.started_at) }}</td>
            <td class="px-4 py-2 text-slate-500">{{ run.trigger === 'manual' ? 'вручную' : 'по расписанию' }}</td>
            <td class="px-4 py-2 text-right tabular-nums">{{ run.requests }}</td>
            <td class="px-4 py-2 text-right tabular-nums">{{ run.fetched }}</td>
            <td class="px-4 py-2 text-right tabular-nums">{{ run.created }}</td>
            <td class="px-4 py-2 text-right tabular-nums">{{ run.updated }}</td>
            <td class="px-4 py-2" :class="run.error ? 'text-red-600' : 'text-emerald-700'">{{ run.error ?? 'ок' }}</td>
          </tr>
        </tbody>
      </table>
    </section>
  </div>
</template>
