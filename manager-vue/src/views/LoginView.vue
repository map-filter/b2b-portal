<script setup lang="ts">
import { ref } from 'vue'
import { useRouter } from 'vue-router'
import { ApiError } from '../api/client'
import { useAuthStore } from '../stores/auth'

const auth = useAuthStore()
const router = useRouter()
const email = ref('manager@demo.kz')
const password = ref('demo12345')
const error = ref<string | null>(null)
const pending = ref(false)

async function submit() {
  pending.value = true
  error.value = null
  try {
    await auth.login(email.value, password.value)
    router.push({ name: 'dashboard' })
  } catch (exception) {
    error.value = exception instanceof ApiError ? exception.first : (exception as Error).message
  } finally {
    pending.value = false
  }
}
</script>

<template>
  <div class="grid min-h-screen place-items-center bg-slate-900 px-4">
    <div class="w-full max-w-sm">
      <div class="mb-6 text-center text-white">
        <div class="mx-auto mb-3 grid size-12 place-items-center rounded-xl bg-brand-600 text-2xl">⚡</div>
        <h1 class="text-xl font-semibold">Панель менеджера</h1>
        <p class="text-sm text-slate-400">ЭлектроОпт B2B</p>
      </div>
      <form class="card space-y-4 p-6" @submit.prevent="submit">
        <label class="block text-sm">
          <span class="font-medium text-slate-700">Email</span>
          <input v-model="email" type="email" class="field mt-1" required />
        </label>
        <label class="block text-sm">
          <span class="font-medium text-slate-700">Пароль</span>
          <input v-model="password" type="password" class="field mt-1" required />
        </label>
        <p v-if="error" class="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700">{{ error }}</p>
        <button class="btn btn-primary w-full py-2.5" :disabled="pending">{{ pending ? 'Входим…' : 'Войти' }}</button>
        <p class="text-center text-xs text-slate-500">Демо: manager@demo.kz или admin@demo.kz, пароль demo12345</p>
      </form>
    </div>
  </div>
</template>
