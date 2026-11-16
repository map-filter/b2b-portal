<script setup lang="ts">
import { ref, watch } from 'vue'
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
      class="flex items-center gap-4 bg-slate-900 px-4 py-3 text-slate-300 lg:fixed lg:inset-y-0 lg:left-0 lg:w-60 lg:flex-col lg:items-stretch lg:py-5"
    >
      <div class="flex items-center gap-2 font-semibold text-white lg:mb-6 lg:px-2">
        <span class="grid size-8 place-items-center rounded-lg bg-brand-600">⚡</span>
        <span class="hidden sm:inline">Панель менеджера</span>
      </div>
      <nav class="flex gap-1 lg:flex-col">
        <RouterLink
          v-for="link in links"
          :key="link.label"
          :to="link.to"
          class="flex items-center gap-2 rounded-lg px-3 py-2 text-sm hover:bg-slate-800 hover:text-white"
          exact-active-class="bg-slate-800 text-white"
        >
          <span class="hidden w-4 text-center text-slate-500 sm:inline">{{ link.icon }}</span>
          {{ link.label }}
        </RouterLink>
      </nav>
      <div class="ml-auto lg:mt-auto lg:ml-0 lg:px-2">
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

    <main class="mx-auto max-w-7xl px-4 py-6 lg:px-8">
      <RouterView />
    </main>
  </div>
</template>
