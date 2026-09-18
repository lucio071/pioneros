<script setup lang="ts">
import { ref, onMounted } from 'vue'
import { useRoute } from 'vue-router'
import { apiFetch } from '../../api'
import { formatFecha } from '../../utils'

const route = useRoute()
const campeonato = ref<any>(null)
const categorias = ref<any[]>([])
const selectedCatId = ref<string | null>(null)
const ranking = ref<any>(null)
const loading = ref(true)

onMounted(async () => {
  const id = route.params.id as string
  let data: any

  if (id) {
    const res = await apiFetch<any>(`/public/campeonatos/${id}`)
    data = res.data
  } else {
    const res = await apiFetch<any>('/public/campeonatos/activo')
    data = res.data
  }

  campeonato.value = data
  categorias.value = data?.categorias || []

  if (categorias.value.length) {
    selectedCatId.value = categorias.value[0].id
    await loadRanking()
  }
  loading.value = false
})

async function loadRanking() {
  if (!campeonato.value || !selectedCatId.value) return
  ranking.value = null
  const res = await apiFetch<any>(`/public/campeonatos/${campeonato.value.id}/categorias/${selectedCatId.value}/ranking`)
  ranking.value = res.data
}

function selectCat(id: string) {
  selectedCatId.value = id
  loadRanking()
}
</script>

<template>
  <div class="min-h-screen bg-gray-50">
    <header class="bg-[var(--brand-dark)] text-white px-4 py-4">
      <div class="max-w-2xl mx-auto">
        <div class="flex items-center gap-3">
          <router-link to="/" class="text-white/60 hover:text-white">&larr;</router-link>
          <div>
            <h1 class="font-bold">{{ campeonato?.nombre || 'Campeonato' }}</h1>
            <p class="text-xs text-white/50">{{ campeonato?.anio }} &middot; {{ campeonato?.fechas_count || 0 }} fechas &middot; {{ campeonato?.pilotos_count || 0 }} pilotos</p>
          </div>
        </div>
      </div>
    </header>

    <main class="max-w-2xl mx-auto px-4 py-4 space-y-4">
      <div v-if="loading" class="text-center py-12 text-gray-400">Cargando...</div>

      <div v-else-if="!campeonato" class="text-center py-12 text-gray-400">No hay campeonato activo</div>

      <template v-else>
        <!-- Category tabs -->
        <div v-if="categorias.length > 1" class="flex gap-1.5 overflow-x-auto">
          <button v-for="c in categorias" :key="c.id" @click="selectCat(c.id)"
            :class="['px-3 py-1.5 rounded-full text-xs font-medium whitespace-nowrap',
              c.id === selectedCatId ? 'bg-[var(--brand-color)] text-white' : 'bg-gray-200 text-gray-600']">
            {{ c.nombre }}
          </button>
        </div>

        <div v-if="!ranking" class="text-center py-8 text-gray-400">Cargando ranking...</div>

        <template v-else>
          <!-- Podium top 3 -->
          <div v-if="ranking.ranking.length >= 3" class="flex justify-center gap-3 py-2">
            <div v-for="pos in [2, 1, 3]" :key="pos" class="text-center"
              :class="pos === 1 ? 'order-2' : pos === 2 ? 'order-1' : 'order-3'">
              <div :class="['w-12 h-12 rounded-full mx-auto flex items-center justify-center font-bold text-lg',
                pos === 1 ? 'bg-[var(--brand-accent)] text-[var(--brand-dark)]' : pos === 2 ? 'bg-gray-300 text-gray-700' : 'bg-amber-700 text-white']"
                :style="pos === 1 ? 'transform: scale(1.15)' : ''">
                {{ pos }}
              </div>
              <p class="text-xs font-bold text-gray-800 mt-1 truncate max-w-[80px]">
                {{ ranking.ranking.find((r: any) => r.posicion === pos)?.piloto_nombre }}
              </p>
              <p class="text-xs text-[var(--brand-color)] font-bold">
                {{ ranking.ranking.find((r: any) => r.posicion === pos)?.puntos_total }} pts
              </p>
            </div>
          </div>

          <!-- Full ranking table -->
          <div class="bg-white rounded-xl shadow-sm overflow-x-auto">
            <table class="w-full text-sm min-w-[400px]">
              <thead>
                <tr class="bg-gray-50 text-gray-500 text-[10px] uppercase">
                  <th class="py-2 px-2 text-left w-8">Pos</th>
                  <th class="py-2 px-2 text-left w-8">N°</th>
                  <th class="py-2 px-2 text-left">Piloto</th>
                  <th class="py-2 px-2 text-center font-bold">Pts</th>
                  <th v-for="f in ranking.fechas" :key="f.id" class="py-2 px-1 text-center" :title="f.nombre">
                    <span class="text-[9px]">{{ f.nombre.substring(0, 6) }}</span>
                  </th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="r in ranking.ranking" :key="r.inscripcion_id" class="border-t border-gray-100">
                  <td class="py-2 px-2 font-bold"
                    :class="r.posicion <= 3 ? 'text-[var(--brand-color)]' : 'text-gray-600'">{{ r.posicion }}</td>
                  <td class="py-2 px-2 font-mono text-gray-400 text-xs">{{ r.numero }}</td>
                  <td class="py-2 px-2">
                    <p class="font-medium text-gray-800 truncate text-xs">{{ r.piloto }}</p>
                  </td>
                  <td class="py-2 px-2 text-center font-bold text-[var(--brand-color)]">{{ r.puntos_total }}</td>
                  <td v-for="fd in r.fechas_detalle" :key="fd.fecha_id" class="py-2 px-1 text-center text-xs">
                    <span v-if="fd.puntos > 0" class="font-medium text-gray-800">{{ fd.puntos }}</span>
                    <span v-else-if="fd.posicion !== null" class="text-gray-300">0</span>
                    <span v-else class="text-gray-200">-</span>
                  </td>
                </tr>
              </tbody>
            </table>
          </div>

          <!-- Fechas list -->
          <div>
            <h3 class="text-xs text-gray-400 uppercase tracking-wider mb-2">Fechas del campeonato</h3>
            <div class="space-y-1">
              <router-link v-for="f in ranking.fechas" :key="f.id" :to="`/resultados`"
                class="block bg-white rounded-lg p-2.5 shadow-sm text-sm hover:shadow-md transition-shadow">
                <span class="text-gray-800">{{ f.nombre }}</span>
                <span class="text-gray-400 ml-2">{{ formatFecha(f.fecha) }}</span>
              </router-link>
            </div>
          </div>
        </template>
      </template>
    </main>

    <nav class="fixed bottom-0 inset-x-0 bg-white border-t border-gray-200 px-4 py-2 flex justify-around max-w-2xl mx-auto">
      <router-link to="/" class="text-gray-400 hover:text-gray-600 text-xs text-center py-1">Inicio</router-link>
      <router-link to="/vivo" class="text-gray-400 hover:text-gray-600 text-xs text-center py-1">En vivo</router-link>
      <router-link to="/campeonato" class="text-[var(--brand-color)] text-xs text-center py-1 font-medium">Campeonato</router-link>
    </nav>
  </div>
</template>
