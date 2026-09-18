<script setup lang="ts">
import { ref } from 'vue'
import { useRouter } from 'vue-router'
import { useAuthStore } from '../../stores/auth'

const router = useRouter()
const auth = useAuthStore()
const email = ref('')
const password = ref('')
const error = ref('')
const loading = ref(false)

async function submit() {
  error.value = ''
  loading.value = true
  try {
    await auth.login(email.value, password.value)
    router.push('/admin')
  } catch (e: any) {
    error.value = e.message || 'Error al iniciar sesion'
  } finally {
    loading.value = false
  }
}
</script>

<template>
  <div class="min-h-screen bg-[var(--brand-dark)] flex items-center justify-center px-4">
    <div class="w-full max-w-sm">
      <h1 class="text-2xl font-bold text-white text-center mb-8">Cronometrista</h1>

      <form @submit.prevent="submit" class="bg-white rounded-xl p-6 shadow-lg space-y-4">
        <div v-if="error" class="bg-red-50 border border-red-200 text-red-700 text-sm rounded-lg p-3">
          {{ error }}
        </div>

        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">Email</label>
          <input
            v-model="email"
            type="email"
            required
            autocomplete="email"
            class="w-full rounded-lg border border-gray-300 px-3 py-2.5 focus:ring-2 focus:ring-blue-500 focus:border-transparent outline-none"
          />
        </div>

        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">Contrasena</label>
          <input
            v-model="password"
            type="password"
            required
            autocomplete="current-password"
            class="w-full rounded-lg border border-gray-300 px-3 py-2.5 focus:ring-2 focus:ring-blue-500 focus:border-transparent outline-none"
          />
        </div>

        <button
          type="submit"
          :disabled="loading"
          class="w-full bg-[var(--brand-color)] hover:bg-[var(--brand-light)] text-white font-bold py-3 rounded-lg transition-colors disabled:opacity-50"
        >
          {{ loading ? 'Ingresando...' : 'Ingresar' }}
        </button>
      </form>

      <div class="text-center mt-4">
        <router-link to="/" class="text-white/40 hover:text-white/60 text-sm">Volver al inicio</router-link>
      </div>
    </div>
  </div>
</template>
