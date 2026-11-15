import { defineStore } from 'pinia'
import { computed, ref } from 'vue'
import { api, tokenStore } from '../api/client'
import type { User } from '../api/types'

export const useAuthStore = defineStore('auth', () => {
  const user = ref<User | null>(null)
  const ready = ref(false)
  const mine = ref(localStorage.getItem('b2b.manager.mine') === '1')
  const isStaff = computed(() => user.value?.role === 'manager' || user.value?.role === 'admin')

  async function load() {
    if (tokenStore.get()) {
      user.value = await api<{ data: User }>('/auth/me')
        .then((r) => r.data)
        .catch(() => null)
    }
    ready.value = true
  }

  async function login(email: string, password: string) {
    const result = await api<{ token: string; user: User }>('/auth/login', {
      method: 'POST',
      json: { email, password, device: 'manager-web' },
    })
    if (result.user.role !== 'manager' && result.user.role !== 'admin') {
      await api('/auth/logout', { method: 'POST', headers: { Authorization: `Bearer ${result.token}` } }).catch(() => undefined)
      throw new Error('Это учётная запись клиента: войдите в кабинет клиента')
    }
    tokenStore.set(result.token)
    user.value = result.user
  }

  async function logout() {
    await api('/auth/logout', { method: 'POST' }).catch(() => undefined)
    tokenStore.clear()
    user.value = null
  }

  function setMine(value: boolean) {
    mine.value = value
    localStorage.setItem('b2b.manager.mine', value ? '1' : '0')
  }

  window.addEventListener('b2b:unauthorized', () => (user.value = null))

  return { user, ready, isStaff, mine, setMine, load, login, logout }
})
