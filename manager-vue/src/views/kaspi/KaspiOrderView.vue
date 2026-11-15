<script setup lang="ts">
import { ref, watch } from 'vue'
import { ApiError, api, download } from '../../api/client'
import type { KaspiOrder } from '../../api/kaspi'
import KaspiStatusBadge from '../../components/KaspiStatusBadge.vue'
import Spinner from '../../components/Spinner.vue'
import { formatDateTime, formatMoney } from '../../lib/format'

const props = defineProps<{ id: string }>()
const order = ref<KaspiOrder | null>(null)
const pending = ref(false)
const error = ref<string | null>(null)
const places = ref(1)
const code = ref('')
const cancelOpen = ref(false)
const reason = ref('')
const reasonComment = ref('')
const reasons = ref<{ value: string; label: string }[]>([])

watch(
  () => props.id,
  async (id) => {
    order.value = await api<{ data: KaspiOrder }>(`/manager/kaspi/orders/${id}`).then((r) => r.data)
  },
  { immediate: true },
)

api<{ data: { value: string; label: string }[] }>('/manager/kaspi/cancel-reasons').then((r) => {
  reasons.value = r.data
  reason.value = r.data[0]?.value ?? ''
})

async function act(path: string, json?: unknown) {
  pending.value = true
  error.value = null
  try {
    order.value = await api<{ data: KaspiOrder }>(`/manager/kaspi/orders/${props.id}/${path}`, { method: 'POST', json }).then(
      (r) => r.data,
    )
    cancelOpen.value = false
    code.value = ''
  } catch (exception) {
    error.value = exception instanceof ApiError ? exception.first : 'Ошибка'
  } finally {
    pending.value = false
  }
}

const has = (action: string) => order.value?.actions.includes(action as never)

const eventDot: Record<string, string> = {
  kaspi: 'bg-red-500',
  action: 'bg-brand-500',
  one_c: 'bg-emerald-500',
  stock: 'bg-amber-500',
  auto: 'bg-violet-500',
  error: 'bg-red-700',
}
</script>

<template>
  <Spinner v-if="!order" />
  <div v-else class="space-y-4">
    <div class="flex flex-wrap items-center gap-3">
      <RouterLink :to="{ name: 'kaspi-orders' }" class="text-sm text-slate-500 hover:text-slate-800">← Заказы Kaspi</RouterLink>
      <h1 class="text-xl font-semibold text-slate-900">Заказ {{ order.code }}</h1>
      <KaspiStatusBadge :status="order.status" :label="order.status_label" />
      <span class="text-sm text-slate-500">{{ order.state_label }}</span>
      <button
        v-if="order.has_waybill"
        class="btn btn-secondary ml-auto"
        @click="download(`/manager/kaspi/orders/${order.id}/waybill`, `Накладная ${order.code}.pdf`)"
      >
        Накладная PDF
      </button>
    </div>

    <p v-if="order.problem" class="rounded-lg bg-amber-50 px-4 py-2.5 text-sm text-amber-900 ring-1 ring-amber-200">
      ⚠ {{ order.problem }}. Привяжите товар в разделе «Товары Kaspi», заказ уйдёт в 1С при следующем изменении.
    </p>

    <div class="grid items-start gap-6 xl:grid-cols-[minmax(0,1fr)_360px]">
      <div class="min-w-0 space-y-4">
        <div class="card overflow-x-auto">
          <table class="w-full text-sm">
            <thead class="bg-slate-50">
              <tr>
                <th class="th">Товар</th>
                <th class="th text-right">Кол-во</th>
                <th class="th hidden text-right sm:table-cell">На складе</th>
                <th class="th hidden text-right sm:table-cell">Цена</th>
                <th class="th text-right">Сумма</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
              <tr v-for="entry in order.entries" :key="entry.id">
                <td class="px-4 py-3">
                  <div class="text-slate-800">{{ entry.product?.name ?? entry.name }}</div>
                  <div class="text-xs text-slate-500">
                    {{ entry.sku ?? 'без артикула' }}
                    <span v-if="!entry.product" class="text-amber-700">· нет в каталоге</span>
                  </div>
                </td>
                <td class="px-4 py-3 text-right whitespace-nowrap tabular-nums">{{ entry.quantity }}</td>
                <td
                  class="hidden px-4 py-3 text-right whitespace-nowrap tabular-nums sm:table-cell"
                  :class="entry.stock !== null && entry.stock < entry.quantity ? 'font-medium text-red-600' : 'text-slate-600'"
                >
                  {{ entry.stock ?? '-' }}
                </td>
                <td class="hidden px-4 py-3 text-right whitespace-nowrap tabular-nums sm:table-cell">
                  {{ formatMoney(entry.price) }}
                </td>
                <td class="px-4 py-3 text-right font-medium whitespace-nowrap tabular-nums">{{ formatMoney(entry.total) }}</td>
              </tr>
            </tbody>
            <tfoot>
              <tr class="bg-slate-50">
                <td colspan="5" class="px-4 py-3 text-right text-base font-semibold tabular-nums">
                  Итого {{ formatMoney(order.total) }}
                </td>
              </tr>
            </tfoot>
          </table>
        </div>

        <div class="card p-5">
          <h2 class="mb-3 text-sm font-semibold text-slate-800">Журнал</h2>
          <ol class="space-y-3 border-l border-slate-200 pl-4">
            <li v-for="(event, index) in order.events" :key="index" class="relative text-sm">
              <span
                class="absolute top-1.5 -left-[21px] size-2.5 rounded-full ring-4 ring-white"
                :class="eventDot[event.type] ?? 'bg-slate-400'"
              />
              <div class="text-slate-800">{{ event.text }}</div>
              <div class="text-xs text-slate-500">
                {{ formatDateTime(event.at) }}<template v-if="event.by"> · {{ event.by }}</template>
              </div>
            </li>
          </ol>
        </div>
      </div>

      <div class="order-first space-y-4 xl:order-none">
        <div v-if="order.actions.length" class="card space-y-4 p-5">
          <h2 class="font-semibold text-slate-800">Действия в Kaspi</h2>

          <button v-if="has('accept')" class="btn btn-primary w-full" :disabled="pending" @click="act('accept')">
            Принять заказ
          </button>

          <div v-if="has('assemble')" class="space-y-2">
            <label class="block text-sm text-slate-600">Мест (коробок) для Kaspi Доставки</label>
            <div class="flex gap-2">
              <input v-model.number="places" type="number" min="1" max="50" class="field w-24" />
              <button class="btn btn-primary flex-1" :disabled="pending" @click="act('assemble', { places })">
                Сформировать накладную
              </button>
            </div>
          </div>

          <div v-if="has('send_code')" class="space-y-2">
            <p class="text-sm text-slate-600">
              Выдача: покупатель получит код в приложении Kaspi.kz и назовёт его.
              <span v-if="order.code_sent_at" class="block text-xs text-slate-500"
                >Код отправлен {{ formatDateTime(order.code_sent_at) }}</span
              >
            </p>
            <button class="btn btn-secondary w-full" :disabled="pending" @click="act('send-code')">
              {{ order.code_sent_at ? 'Отправить код ещё раз' : 'Отправить код покупателю' }}
            </button>
            <form v-if="has('complete')" class="flex gap-2" @submit.prevent="act('complete', { code })">
              <input v-model="code" inputmode="numeric" maxlength="6" placeholder="Код" class="field w-28 tracking-widest" />
              <button class="btn btn-primary flex-1" :disabled="pending || code.length < 4">Выдать заказ</button>
            </form>
          </div>

          <div v-if="has('cancel')" class="border-t border-slate-100 pt-3">
            <button v-if="!cancelOpen" class="text-sm text-red-600 hover:underline" @click="cancelOpen = true">
              Отменить заказ…
            </button>
            <form v-else class="space-y-2" @submit.prevent="act('cancel', { reason, comment: reasonComment || undefined })">
              <select v-model="reason" class="field">
                <option v-for="item in reasons" :key="item.value" :value="item.value">{{ item.label }}</option>
              </select>
              <textarea v-model="reasonComment" rows="2" maxlength="1000" class="field" placeholder="Комментарий для Kaspi" />
              <div class="flex gap-2">
                <button class="btn flex-1 text-red-600 ring-1 ring-red-200 hover:bg-red-50" :disabled="pending">
                  Отменить в Kaspi
                </button>
                <button type="button" class="btn btn-secondary" @click="cancelOpen = false">Назад</button>
              </div>
            </form>
          </div>

          <p v-if="error" class="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700">{{ error }}</p>
        </div>

        <div class="card space-y-2 p-5 text-sm">
          <div class="flex justify-between gap-4">
            <span class="text-slate-500">Покупатель</span><span class="text-right">{{ order.customer_name }}</span>
          </div>
          <div v-if="order.customer_phone" class="flex justify-between gap-4">
            <span class="text-slate-500">Телефон</span>
            <a :href="`tel:+${order.customer_phone}`" class="text-brand-700">+{{ order.customer_phone }}</a>
          </div>
          <div class="flex justify-between gap-4">
            <span class="text-slate-500">Доставка</span>
            <span class="text-right">{{ order.delivery_label }}<template v-if="order.express">, экспресс</template></span>
          </div>
          <div v-if="order.delivery_address" class="flex justify-between gap-4">
            <span class="text-slate-500">Адрес</span><span class="text-right">{{ order.delivery_address }}</span>
          </div>
          <div v-if="order.store_id" class="flex justify-between gap-4">
            <span class="text-slate-500">Склад Kaspi</span><span>{{ order.store_id }}</span>
          </div>
          <div class="flex justify-between gap-4">
            <span class="text-slate-500">Оформлен</span><span>{{ formatDateTime(order.created_at) }}</span>
          </div>
          <div v-if="order.planned_delivery_at" class="flex justify-between gap-4">
            <span class="text-slate-500">Плановая доставка</span><span>{{ formatDateTime(order.planned_delivery_at) }}</span>
          </div>
          <div v-if="order.courier_planned_at" class="flex justify-between gap-4">
            <span class="text-slate-500">Курьер Kaspi</span>
            <span class="text-right">{{
              order.courier_transmitted_at
                ? `забрал ${formatDateTime(order.courier_transmitted_at)}`
                : `план ${formatDateTime(order.courier_planned_at)}`
            }}</span>
          </div>
          <div v-if="order.waybill_number" class="flex justify-between gap-4">
            <span class="text-slate-500">Накладная</span><span>{{ order.waybill_number }}, мест {{ order.number_of_space }}</span>
          </div>
          <div class="flex justify-between gap-4">
            <span class="text-slate-500">Оплата</span>
            <span>{{ order.payment_mode === 'PAY_WITH_CREDIT' ? `Кредит, ${order.credit_term ?? '?'} мес.` : 'Оплачен' }}</span>
          </div>
          <div v-if="Number(order.delivery_cost_for_seller)" class="flex justify-between gap-4">
            <span class="text-slate-500">Доставка для продавца</span
            ><span>{{ formatMoney(order.delivery_cost_for_seller) }}</span>
          </div>
          <div v-if="order.cancellation" class="rounded-lg bg-slate-100 px-3 py-2 text-slate-700">
            Отмена: {{ order.cancellation
            }}<template v-if="order.cancellation_comment">. {{ order.cancellation_comment }}</template>
          </div>
          <div class="flex justify-between gap-4 border-t border-slate-100 pt-2">
            <span class="text-slate-500">Заказ в 1С</span>
            <RouterLink
              v-if="order.core_order"
              :to="{ name: 'order', params: { id: order.core_order.id } }"
              class="text-right text-brand-700 hover:underline"
              >{{ order.core_order.number }} · {{ order.core_order.status_label }}</RouterLink
            >
            <span v-else class="text-slate-400">не создан</span>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>
