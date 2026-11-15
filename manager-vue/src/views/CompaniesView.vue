<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { api, toQuery } from '../api/client'
import type { Company } from '../api/types'
import Spinner from '../components/Spinner.vue'
import { formatDate, formatMoney } from '../lib/format'
import { useAuthStore } from '../stores/auth'

const auth = useAuthStore()
const companies = ref<Company[] | null>(null)
const search = ref('')

watch(
  () => auth.mine,
  async () =>
    (companies.value = await api<{ data: Company[] }>(`/manager/companies${toQuery({ mine: auth.mine })}`).then((r) => r.data)),
  { immediate: true },
)

const visible = computed(() => {
  const term = search.value.trim().toLowerCase()
  return (companies.value ?? []).filter((c) => !term || c.name.toLowerCase().includes(term) || c.bin.includes(term))
})
</script>

<template>
  <div class="space-y-4">
    <div class="flex flex-wrap items-center gap-3">
      <h1 class="text-xl font-semibold text-slate-900">Клиенты</h1>
      <input v-model="search" type="search" placeholder="Название или БИН" class="field ml-auto max-w-xs" />
    </div>

    <div class="card overflow-x-auto">
      <Spinner v-if="!companies" />
      <table v-else class="w-full text-sm">
        <thead class="bg-slate-50">
          <tr>
            <th class="th">Компания</th>
            <th class="th hidden lg:table-cell">Договор</th>
            <th class="th hidden md:table-cell">Менеджер</th>
            <th class="th text-right">В работе</th>
            <th class="th hidden text-right sm:table-cell">Последний заказ</th>
            <th class="th text-right">Выручка</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-slate-100">
          <tr v-for="company in visible" :key="company.id" class="hover:bg-slate-50">
            <td class="px-4 py-3">
              <RouterLink
                :to="{ name: 'company', params: { id: company.id } }"
                class="font-medium text-slate-800 hover:text-brand-700"
                >{{ company.name }}</RouterLink
              >
              <div class="text-xs text-slate-500">
                БИН {{ company.bin }}<span v-if="!company.is_active" class="ml-2 text-red-600">отключена</span>
              </div>
            </td>
            <td class="hidden px-4 py-3 text-slate-600 lg:table-cell">
              {{ company.contract?.price_type
              }}<span v-if="Number(company.contract?.discount_percent)"
                >, −{{ Number(company.contract?.discount_percent) }}%</span
              >
            </td>
            <td class="hidden px-4 py-3 text-slate-600 md:table-cell">{{ company.manager?.name ?? '-' }}</td>
            <td
              class="px-4 py-3 text-right tabular-nums"
              :class="company.open_orders ? 'font-medium text-sky-700' : 'text-slate-400'"
            >
              {{ company.open_orders }}
            </td>
            <td class="hidden px-4 py-3 text-right whitespace-nowrap text-slate-600 sm:table-cell">
              {{ company.last_order_at ? formatDate(company.last_order_at) : '-' }}
            </td>
            <td class="px-4 py-3 text-right font-medium whitespace-nowrap tabular-nums">{{ formatMoney(company.revenue) }}</td>
          </tr>
        </tbody>
      </table>
    </div>
  </div>
</template>
