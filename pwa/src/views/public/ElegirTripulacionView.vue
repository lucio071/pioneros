<script setup lang="ts">
import { ref, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import { useCarreraStore } from '../../stores/carrera'
import { apiFetch } from '../../api'

const router = useRouter()
const store = useCarreraStore()
const categorias = ref<any[]>([])
const selectedCat = ref<string | null>(null)
const tripulaciones = ref<any[]>([])
const loading = ref(true)

onMounted(async () => {
  await store.fetchFechaActiva()
  if (store.fechaActiva) {
    const res = await apiFetch<any[]>(`/public/fechas/${store.fechaActiva.id}/categorias`)
    categorias.value = res.data || []
    if (categorias.value.length === 1) {
      selectedCat.value = categorias.value[0].id
      await loadTrips()
    }
  }
  loading.value = false
})

async function selectCat(id: string) {
  selectedCat.value = id
  await loadTrips()
}

async function loadTrips() {
  if (!store.fechaActiva || !selectedCat.value) return
  const res = await apiFetch<any>(`/public/fechas/${store.fechaActiva.id}/categorias/${selectedCat.value}/ranking`)
  const data = res.data
  tripulaciones.value = [...(data.ranking || []), ...(data.en_curso || []), ...(data.dnf || [])]
}

function seleccionar(id: string) {
  store.setMiTripulacion(id)
  if (selectedCat.value) store.setCategoria(selectedCat.value)
  router.push('/vivo')
}
</script>

<template>
  <div class="min-h-screen bg-gray-50">
    <header class="bg-[var(--brand-dark)] text-white px-4 py-4">
      <div class="max-w-lg mx-auto flex items-center gap-3">
        <router-link to="/" class="text-white/60 hover:text-white">&larr;</router-link>
        <h1 class="font-bold">Elegir mi tripulacion</h1>
      </div>
    </header>

    <main class="max-w-lg mx-auto px-4 py-4">
      <div v-if="loading" class="text-center py-8 text-gray-400">Cargando...</div>

      <!-- Category selector -->
      <div v-else-if="!selectedCat && categorias.length > 1" class="space-y-3">
        <p class="text-sm text-gray-500 mb-2">Primero, selecciona tu categoria:</p>
        <button v-for="c in categorias" :key="c.id" @click="selectCat(c.id)"
          class="w-full bg-white rounded-lg p-4 text-left shadow-sm hover:shadow-md transition-shadow">
          <p class="font-medium text-gray-800">{{ c.nombre }}</p>
          <p class="text-sm text-gray-500">{{ c.tripulaciones_count }} tripulaciones</p>
        </button>
      </div>

      <!-- Trip selector -->
      <template v-else>
        <div v-if="categorias.length > 1" class="mb-3">
          <button @click="selectedCat = null; tripulaciones = []" class="text-sm text-blue-600">&larr; Cambiar categoria</button>
        </div>

        <p class="text-sm text-gray-500 mb-4">Selecciona tu tripulacion para seguirla en el ranking.</p>

        <div v-if="!tripulaciones.length" class="text-center py-8 text-gray-400">No hay tripulaciones</div>

        <div v-else class="space-y-2">
          <button v-for="t in tripulaciones" :key="t.id" @click="seleccionar(t.id)"
            class="w-full bg-white rounded-lg p-4 text-left shadow-sm hover:shadow-md transition-shadow flex items-center gap-4"
            :class="{ 'ring-2 ring-[var(--brand-color)]': t.id === store.miTripulacionId }">
            <div class="w-12 h-12 bg-[var(--brand-color)] text-white rounded-lg flex items-center justify-center font-bold text-lg shrink-0">
              {{ t.numero }}
            </div>
            <div class="min-w-0">
              <p class="font-medium text-gray-800">{{ t.nombre }}</p>
              <p class="text-sm text-gray-500 truncate">{{ t.piloto }}<span v-if="t.copiloto"> / {{ t.copiloto }}</span></p>
            </div>
          </button>
        </div>
      </template>
    </main>
  </div>
</template>
