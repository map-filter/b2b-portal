<script setup lang="ts">
import { ref, watch } from 'vue'
import { ApiError, api, download } from '../api/client'
import type { Order, OrderStatus } from '../api/types'
import Spinner from '../components/Spinner.vue'
import StatusBadge from '../components/StatusBadge.vue'
import { formatDate, formatDateTime, formatMoney } from '../lib/format'

const props = defineProps<{ id: string }>()
const order = ref<Order | null>(null)
const comment = ref('')
const pending = ref(false)
const error = ref<string | null>(null)

watch(
  () => props.id,
  async (id) => (order.value = await api<{ data: Order }>(`/manager/orders/${id}`).then((r) => r.data)),
  { immediate: true },
)

async function move(status: OrderStatus) {
  pending.value = true
  error.value = null
  try {
    order.value = await api<{ data: Order }>(`/manager/orders/${props.id}/status`, {
      method: 'PATCH',
      json: { status, comment: comment.value || undefined },
    }).then((r) => r.data)
    comment.value = ''
  } catch (exception) {
    error.value = exception instanceof ApiError ? exception.first : 'Ошибка'
  } finally {
    pending.value = false
  }
}
</script>

<template>
  <Spinner v-if="!order" />
  <div v-else class="space-y-4">
    <div class="flex flex-wrap items-center gap-3">
      <RouterLink :to="{ name: 'orders' }" class="text-sm text-slate-500 hover:text-slate-800">← Заказы</RouterLink>
      <h1 class="text-xl font-semibold text-slate-900">{{ order.number }}</h1>
      <StatusBadge :status="order.status" :label="order.status_label" />
      <button
        class="btn btn-secondary ml-auto"
        @click="download(`/manager/orders/${order.id}/invoice`, `Счёт ${order.number}.pdf`)"
      >
        Счёт PDF
      </button>
    </div>

    <div class="grid items-start gap-6 lg:grid-cols-[1fr_340px]">
      <div class="card overflow-x-auto">
        <table class="w-full text-sm">
          <thead class="bg-slate-50">
            <tr>
              <th class="th">Товар</th>
              <th class="th text-right">Кол-во</th>
              <th class="th text-right">Цена</th>
              <th class="th text-right">Сумма</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-slate-100">
            <tr v-for="item in order.items" :key="item.product_id">
              <td class="px-4 py-3">
                <div class="text-slate-800">{{ item.name }}</div>
                <div class="text-xs text-slate-500">{{ item.sku }}</div>
              </td>
              <td class="px-4 py-3 text-right whitespace-nowrap tabular-nums">{{ item.quantity }} {{ item.unit }}</td>
              <td class="px-4 py-3 text-right whitespace-nowrap tabular-nums">{{ formatMoney(item.price) }}</td>
              <td class="px-4 py-3 text-right font-medium whitespace-nowrap tabular-nums">{{ formatMoney(item.total) }}</td>
            </tr>
          </tbody>
          <tfoot>
            <tr class="bg-slate-50">
              <td colspan="4" class="px-4 py-3 text-right text-base font-semibold tabular-nums">
                Итого {{ formatMoney(order.total) }}
              </td>
            </tr>
          </tfoot>
        </table>
      </div>

      <div class="space-y-4">
        <div v-if="order.next_statuses.length" class="card space-y-3 p-5">
          <h2 class="font-semibold text-slate-800">Действия</h2>
          <input v-model="comment" class="field" placeholder="Комментарий к смене статуса" />
          <div class="flex flex-wrap gap-2">
            <button
              v-for="next in order.next_statuses"
              :key="next.value"
              class="btn"
              :class="next.value === 'cancelled' ? 'text-red-600 ring-1 ring-red-200 hover:bg-red-50' : 'btn-primary'"
              :disabled="pending"
              @click="move(next.value)"
            >
              {{ next.value === 'cancelled' ? 'Отменить' : next.label }}
            </button>
          </div>
          <p v-if="error" class="text-sm text-red-600">{{ error }}</p>
        </div>

        <div class="card space-y-2 p-5 text-sm">
          <div class="flex justify-between gap-4">
            <span class="text-slate-500">Клиент</span>
            <RouterLink
              :to="{ name: 'company', params: { id: order.company?.id } }"
              class="text-right text-brand-700 hover:underline"
              >{{ order.company?.name }}</RouterLink
            >
          </div>
          <div class="flex justify-between gap-4">
            <span class="text-slate-500">Автор</span><span class="text-right">{{ order.author }}</span>
          </div>
          <div class="flex justify-between gap-4">
            <span class="text-slate-500">Договор</span><span>{{ order.contract }}</span>
          </div>
          <div class="flex justify-between gap-4">
            <span class="text-slate-500">Создан</span><span>{{ formatDateTime(order.created_at) }}</span>
          </div>
          <div class="flex justify-between gap-4">
            <span class="text-slate-500">Доставка</span><span class="text-right">{{ order.delivery_address ?? '-' }}</span>
          </div>
          <div v-if="order.desired_date" class="flex justify-between gap-4">
            <span class="text-slate-500">Желаемая дата</span><span>{{ formatDate(order.desired_date) }}</span>
          </div>
          <div v-if="order.comment" class="rounded-lg bg-amber-50 px-3 py-2 text-amber-900">{{ order.comment }}</div>
          <div class="flex justify-between gap-4">
            <span class="text-slate-500">Сделка Битрикс24</span><span>{{ order.crm_deal_id ?? 'не создана' }}</span>
          </div>
        </div>

        <div class="card p-5">
          <h2 class="mb-3 text-sm font-semibold text-slate-800">История</h2>
          <ol class="space-y-3 border-l border-slate-200 pl-4">
            <li v-for="(change, index) in order.history" :key="index" class="relative text-sm">
              <span class="absolute top-1.5 -left-[21px] size-2.5 rounded-full bg-brand-500 ring-4 ring-white" />
              <div class="font-medium text-slate-800">{{ change.status_label }}</div>
              <div class="text-xs text-slate-500">
                {{ formatDateTime(change.at) }}<template v-if="change.by"> · {{ change.by }}</template>
              </div>
              <div v-if="change.comment" class="text-slate-600">{{ change.comment }}</div>
            </li>
          </ol>
        </div>
      </div>
    </div>
  </div>
</template>
