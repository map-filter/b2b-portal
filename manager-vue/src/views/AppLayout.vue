<script setup lang="ts">
import { onBeforeUnmount, ref, watch } from 'vue'
import { api } from '../api/client'
import { useRouter } from 'vue-router'
import { useAuthStore } from '../stores/auth'

const auth = useAuthStore()
const router = useRouter()

const links = [
  { to: { name: 'dashboard' }, label: 'Сводка', icon: '◔' },
  { to: { name: 'orders' }, label: 'Заказы', icon: '☰' },
  { to: { name: 'companies' }, label: 'Клиенты', icon: '◫' },
  { to: { name: 'exchange' }, label: 'Обмен с 1С', icon: '⇄' },
]

const kaspiLinks = [
  { to: { name: 'kaspi-orders' }, label: 'Заказы', icon: '☰', badge: true },
  { to: { name: 'kaspi' }, label: 'Продажи', icon: '◔', badge: false },
  { to: { name: 'kaspi-products' }, label: 'Товары', icon: '▦', badge: false },
  { to: { name: 'kaspi-settings' }, label: 'Настройки', icon: '⚙', badge: false },
]

const kaspiNew = ref(0)

async function loadKaspiNew() {
  const page = await api<{ meta: { states: { value: string; count: number }[] } }>('/manager/kaspi/orders?state=NEW').catch(
    () => null,
  )
  kaspiNew.value = page?.meta.states.find((s) => s.value === 'NEW')?.count ?? 0
}

loadKaspiNew()
const kaspiPoll = setInterval(loadKaspiNew, 60000)
onBeforeUnmount(() => clearInterval(kaspiPoll))

watch(
  () => auth.user,
  (user) => !user && router.push({ name: 'login' }),
)

const telegramLink = ref<string | null>(null)

async function connectTelegram() {
  const result = await api<{ url: string }>('/telegram/link', { method: 'POST' })
  telegramLink.value = result.url
  window.open(result.url, '_blank', 'noopener')
}

async function logout() {
  await auth.logout()
  router.push({ name: 'login' })
}
</script>

<template>
  <div class="min-h-screen lg:pl-60">
    <aside
      class="flex min-w-0 items-center gap-4 bg-slate-900 px-4 py-3 text-slate-300 lg:fixed lg:inset-y-0 lg:left-0 lg:w-60 lg:flex-col lg:items-stretch lg:py-5"
    >
      <div class="flex items-center gap-2 font-semibold text-white lg:mb-6 lg:px-2">
        <span class="grid size-8 place-items-center rounded-lg bg-brand-600">⚡</span>
        <span class="hidden sm:inline">Панель менеджера</span>
      </div>
      <nav class="-my-1 flex min-w-0 gap-1 overflow-x-auto py-1 lg:my-0 lg:flex-col lg:overflow-visible lg:py-0">
        <RouterLink
          v-for="link in links"
          :key="link.label"
          :to="link.to"
          class="flex shrink-0 items-center gap-2 rounded-lg px-3 py-2 text-sm whitespace-nowrap hover:bg-slate-800 hover:text-white"
          exact-active-class="bg-slate-800 text-white"
        >
          <span class="hidden w-4 text-center text-slate-500 sm:inline">{{ link.icon }}</span>
          {{ link.label }}
        </RouterLink>
        <div class="hidden px-3 pt-4 pb-1 text-xs font-medium tracking-wide text-slate-500 uppercase lg:block">
          <span class="mr-1 inline-grid size-4 place-items-center rounded bg-[#f14635] text-[10px] text-white">K</span>Kaspi
          Магазин
        </div>
        <RouterLink
          v-for="link in kaspiLinks"
          :key="link.label"
          :to="link.to"
          class="flex shrink-0 items-center gap-2 rounded-lg px-3 py-2 text-sm whitespace-nowrap hover:bg-slate-800 hover:text-white"
          active-class="bg-slate-800 text-white"
        >
          <span class="hidden w-4 text-center text-slate-500 sm:inline">{{ link.icon }}</span>
          <span class="lg:hidden">Kaspi:</span> {{ link.label }}
          <span v-if="link.badge && kaspiNew" class="ml-auto rounded-full bg-[#f14635] px-1.5 text-xs text-white tabular-nums">{{
            kaspiNew
          }}</span>
        </RouterLink>
      </nav>
      <div class="ml-auto shrink-0 lg:mt-auto lg:ml-0 lg:px-2">
        <label class="mb-4 hidden items-center gap-2 text-xs text-slate-400 lg:flex">
          <input
            type="checkbox"
            class="accent-brand-500"
            :checked="auth.mine"
            @change="auth.setMine(($event.target as HTMLInputElement).checked)"
          />
          Только мои клиенты
        </label>
        <div class="hidden text-sm text-white lg:block">{{ auth.user?.name }}</div>
        <div class="hidden text-xs text-slate-500 lg:block">{{ auth.user?.role_label }}</div>
        <div v-if="auth.user?.telegram_available" class="mt-3 hidden text-xs lg:block">
          <span v-if="auth.user?.telegram_linked" class="text-emerald-400">✓ Заказы приходят в Telegram</span>
          <button v-else class="text-brand-100 hover:text-white" @click="connectTelegram">Новые заказы в Telegram →</button>
          <a v-if="telegramLink" :href="telegramLink" target="_blank" class="mt-1 block break-all text-slate-500">{{
            telegramLink
          }}</a>
        </div>
        <button class="text-sm text-slate-400 hover:text-white lg:mt-3" @click="logout">Выйти</button>
      </div>
    </aside>

    <div class="bg-amber-50 px-4 py-1 text-center text-xs text-amber-800">Демо-версия: компании, товары и цены выдуманы</div>
    <main class="mx-auto max-w-7xl px-4 py-6 lg:px-8">
      <RouterView />
    </main>
  </div>
</template>
