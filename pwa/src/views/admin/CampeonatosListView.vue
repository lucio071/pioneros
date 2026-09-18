<script setup lang="ts">
import { ref, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import { apiFetch, apiMutate } from '../../api'
import { estadoLabel, estadoColor } from '../../utils'
import type { Campeonato } from '../../types'

const router = useRouter()
const campeonatos = ref<Campeonato[]>([])
const loading = ref(true)
const showForm = ref(false)
const form = ref({ nombre: '', anio: new Date().getFullYear(), tipo_pista_forzado: null as string | null })

onMounted(async () => {
  await loadData()
  loading.value = false
})

async function loadData() {
  const res = await apiFetch<Campeonato[]>('/campeonatos')
  campeonatos.value = res.data || []
}

async function crear() {
  const res = await apiMutate<Campeonato>('POST', '/campeonatos', form.value)
  showForm.value = false
  form.value = { nombre: '', anio: new Date().getFullYear(), tipo_pista_forzado: null }
  await loadData()
  router.push(`/admin/campeonatos/${res.id}`)
}
</script>

<template>
  <div class="min-h-screen bg-gray-50">
    <header class="bg-[var(--brand-dark)] text-white px-4 py-4">
      <div class="max-w-2xl mx-auto flex items-center gap-3">
        <button @click="router.push('/admin')" class="text-white/60 hover:text-white">&larr;</button>
        <h1 class="font-bold">Campeonatos</h1>
      </div>
    </header>

    <main class="max-w-2xl mx-auto px-4 py-4 space-y-4">
      <button v-if="!showForm" @click="showForm = true"
        class="w-full bg-[var(--brand-color)] hover:bg-[var(--brand-light)] text-white font-medium py-3 rounded-lg">
        + Nuevo campeonato
      </button>

      <div v-if="showForm" class="bg-white rounded-xl p-4 shadow-sm space-y-3">
        <h3 class="font-bold text-gray-800">Nuevo campeonato</h3>
        <input v-model="form.nombre" placeholder="Nombre (ej: Campeonato Pioneros)" required
          class="w-full rounded-lg border border-gray-300 px-3 py-2 outline-none focus:ring-2 focus:ring-blue-500" />
        <input v-model.number="form.anio" type="number" min="2020" max="2100" placeholder="Ano"
          class="w-32 rounded-lg border border-gray-300 px-3 py-2 outline-none focus:ring-2 focus:ring-blue-500" />
        <select v-model="form.tipo_pista_forzado" class="w-full rounded-lg border border-gray-300 px-3 py-2 outline-none text-sm">
          <option :value="null">Cada fecha elige tipo de pista</option>
          <option value="simple">Siempre pista simple (ej: Autocross)</option>
          <option value="doble">Siempre pista doble</option>
        </select>
        <div class="flex gap-2">
          <button @click="crear" class="bg-green-600 text-white px-4 py-2 rounded-lg font-medium">Crear</button>
          <button @click="showForm = false" class="text-gray-500 px-4 py-2">Cancelar</button>
        </div>
      </div>

      <div v-if="loading" class="text-center py-8 text-gray-400">Cargando...</div>

      <div v-else class="space-y-2">
        <div v-if="!campeonatos.length" class="text-center py-8 text-gray-400">No hay campeonatos</div>
        <button v-for="c in campeonatos" :key="c.id" @click="router.push(`/admin/campeonatos/${c.id}`)"
          class="w-full bg-white rounded-xl p-4 text-left shadow-sm hover:shadow-md transition-shadow">
          <div class="flex items-center justify-between">
            <div>
              <p class="font-medium text-gray-800">{{ c.nombre }}</p>
              <p class="text-sm text-gray-500">{{ c.anio }} &middot; {{ c.fechas_count || 0 }} fechas &middot; {{ c.pilotos_count || 0 }} pilotos</p>
            </div>
            <span :class="['text-xs px-2.5 py-1 rounded-full font-medium', estadoColor(c.estado)]">
              {{ estadoLabel(c.estado) }}
            </span>
          </div>
        </button>
      </div>
    </main>
  </div>
</template>
