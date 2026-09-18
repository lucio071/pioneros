<script setup lang="ts">
import { ref, onMounted } from 'vue'
import { apiFetch } from '../../api'
import { formatTiempo, formatDiferencia, formatFecha } from '../../utils'
import type { Fecha, RankingData, FechaCategoriaResumen } from '../../types'

const fechas = ref<Fecha[]>([])
const selectedFecha = ref<Fecha | null>(null)
const categorias = ref<FechaCategoriaResumen[]>([])
const selectedCatId = ref<string | null>(null)
const ranking = ref<RankingData | null>(null)
const top3General = ref<any[]>([])
const loading = ref(true)

onMounted(async () => {
  const res = await apiFetch<Fecha[]>('/public/fechas')
  fechas.value = res.data || []
  loading.value = false
})

async function loadTop3(fechaId: string) {
  try {
    const res = await apiFetch<any[]>('/public/fechas/' + fechaId + '/top3')
    top3General.value = res.data || []
  } catch { top3General.value = [] }
}

async function selectFecha(f: Fecha) {
  selectedFecha.value = f
  categorias.value = f.categorias || []
  await loadTop3(f.id)
  if (categorias.value.length > 0) {
    await loadRanking(categorias.value[0].id)
  }
}

async function loadRanking(catId: string) {
  selectedCatId.value = catId
  ranking.value = null
  if (!selectedFecha.value) return
  const res = await apiFetch<RankingData>(`/public/fechas/${selectedFecha.value.id}/categorias/${catId}/ranking`)
  ranking.value = res.data
}
</script>

<template>
  <div class="min-h-screen bg-gray-50">
    <header class="bg-[var(--brand-dark)] text-white px-4 py-4">
      <div class="max-w-lg mx-auto flex items-center gap-3">
        <router-link to="/" class="text-white/60 hover:text-white">&larr;</router-link>
        <h1 class="font-bold">Resultados</h1>
      </div>
    </header>

    <main class="max-w-lg mx-auto px-4 py-4 space-y-4">
      <div v-if="loading" class="text-center py-8 text-gray-400">Cargando...</div>

      <div v-else-if="!selectedFecha" class="space-y-2">
        <div v-if="!fechas.length" class="text-center py-8 text-gray-400">No hay resultados</div>
        <button v-for="f in fechas" :key="f.id" @click="selectFecha(f)"
          class="w-full bg-white rounded-lg p-4 text-left shadow-sm hover:shadow-md transition-shadow">
          <p class="font-medium text-gray-800">{{ f.nombre }}</p>
          <p class="text-sm text-gray-500">
            {{ formatFecha(f.fecha) }}
            &middot; {{ f.categorias.length }} categorias
          </p>
        </button>
      </div>

      <template v-else>
        <button @click="selectedFecha = null; ranking = null" class="text-sm text-blue-600">&larr; Volver</button>
        <h2 class="text-lg font-bold text-gray-800">{{ selectedFecha.nombre }}</h2>

        <!-- Top 3 General -->
        <div v-if="top3General.length" class="bg-amber-50 border border-amber-200 rounded-xl p-3 mb-2">
          <h3 class="text-xs text-amber-700 uppercase tracking-wider font-bold mb-2 text-center">Top 3 General</h3>
          <div v-for="t in top3General" :key="t.tripulacion_id" class="flex items-center gap-2 py-1">
            <span class="w-6 font-bold text-center" :class="t.posicion === 1 ? 'text-yellow-600' : t.posicion === 2 ? 'text-gray-500' : 'text-amber-700'">{{ t.posicion }}.</span>
            <span class="font-mono text-gray-400 text-sm">#{{ t.numero }}</span>
            <span class="flex-1 text-gray-800 text-sm">{{ t.piloto }}</span>
            <span class="text-xs text-gray-400">{{ t.categoria }}</span>
            <span class="font-mono text-sm font-bold">{{ formatTiempo(t.mejor_vuelta_ms) }}</span>
          </div>
        </div>

        <!-- Category tabs -->
        <div v-if="categorias.length > 1" class="flex gap-1.5 overflow-x-auto">
          <button v-for="c in categorias" :key="c.id" @click="loadRanking(c.id)"
            :class="['px-3 py-1.5 rounded-full text-xs font-medium whitespace-nowrap',
              c.id === selectedCatId ? 'bg-[var(--brand-color)] text-white' : 'bg-gray-200 text-gray-600']">
            {{ c.nombre }}
          </button>
        </div>

        <div v-if="!ranking" class="text-center py-8 text-gray-400">Cargando ranking...</div>

        <!-- Final results -->
        <template v-else>
          <div v-if="ranking.final_ranking?.length" class="bg-orange-50 rounded-xl p-3 mb-2">
            <h3 class="text-xs text-orange-600 uppercase tracking-wider font-medium mb-2">Podio Final</h3>
            <div v-for="f in ranking.final_ranking" :key="f.id" class="flex items-center gap-2 py-1">
              <span class="w-6 font-bold text-center"
                :class="f.posicion === 1 ? 'text-yellow-600' : f.posicion === 2 ? 'text-gray-500' : 'text-amber-700'">
                {{ f.posicion }}°
              </span>
              <span class="font-mono text-gray-400 text-sm">#{{ f.numero }}</span>
              <span class="flex-1 text-gray-800 text-sm">{{ f.nombre }}</span>
              <span class="font-mono text-sm">{{ formatTiempo(f.vuelta_final?.total_vuelta) }}</span>
            </div>
          </div>

          <!-- Classification -->
          <div class="bg-white rounded-xl shadow-sm overflow-hidden">
            <table class="w-full text-sm">
              <thead>
                <tr class="bg-gray-50 text-gray-500 text-xs uppercase">
                  <th class="py-2 px-2 text-left w-8">Pos</th>
                  <th class="py-2 px-2 text-left w-10">N°</th>
                  <th class="py-2 px-2 text-left">Tripulacion</th>
                  <th class="py-2 px-2 text-right">Mejor</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="t in ranking.ranking" :key="t.id" class="border-t border-gray-100">
                  <td class="py-2 px-2 font-bold">{{ t.posicion }}</td>
                  <td class="py-2 px-2 font-mono text-gray-500">{{ t.numero }}</td>
                  <td class="py-2 px-2">
                    <p class="font-medium text-gray-800 truncate">{{ t.nombre }}</p>
                    <p class="text-xs text-gray-400">{{ t.piloto }}</p>
                  </td>
                  <td class="py-2 px-2 text-right font-mono">
                    <p>{{ formatTiempo(t.mejor_vuelta_ms) }}</p>
                    <p v-if="t.diferencia" class="text-xs text-gray-400">{{ formatDiferencia(t.diferencia) }}</p>
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
        </template>
      </template>
    </main>
  </div>
</template>
