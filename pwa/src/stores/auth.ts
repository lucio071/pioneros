import { defineStore } from 'pinia'
import { ref, computed } from 'vue'
import { apiFetch, apiMutate } from '../api'

interface User {
  id: number
  name: string
  email: string
  rol: 'cronometrista' | 'admin'
}

export const useAuthStore = defineStore('auth', () => {
  const user = ref<User | null>(null)
  const loading = ref(false)

  const isAdmin = computed(() => user.value?.rol === 'admin')

  async function login(email: string, password: string) {
    await apiMutate('POST', '/auth/login', { email, password })
    await checkAuth()
  }

  async function logout() {
    await apiMutate('POST', '/auth/logout')
    user.value = null
  }

  async function checkAuth() {
    try {
      const res = await apiFetch<User>('/me')
      user.value = res.data
    } catch {
      user.value = null
    }
  }

  return { user, loading, isAdmin, login, logout, checkAuth }
})
