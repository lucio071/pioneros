<script setup lang="ts">
import { ref, onMounted } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { apiFetch, apiMutate } from '../../api'
import { estadoLabel, estadoColor, formatFecha } from '../../utils'
import type { CategoriaCatalogo } from '../../types'
import { useToast } from '../../composables/useToast'

const route = useRoute()
const router = useRouter()

const campeonato = ref<any>(null)
const categorias = ref<CategoriaCatalogo[]>([])
const fechas = ref<any[]>([])
const ranking = ref<any>(null)
const tab = ref<'datos' | 'fechas' | 'ranking'>('datos')
const loading = ref(true)
const { toast, showToast } = useToast()
const selectedRankingCatId = ref<string | null>(null)

onMounted(load)

async function load() {
  loading.value = true
  const res = await apiFetch<any>(`/public/campeonatos/${route.params.id}`)
  campeonato.value = res.data
  categorias.value = res.data?.categorias || []
  loading.value = false
}

async function loadTab() {
  if (tab.value === 'fechas') {
    const res = await apiFetch<any[]>(`/public/campeonatos/${route.params.id}/fechas`)
    fechas.value = res.data || []
  } else if (tab.value === 'ranking') {
    if (!selectedRankingCatId.value && categorias.value.length) {
      selectedRankingCatId.value = categorias.value[0].id
    }
    if (selectedRankingCatId.value) await loadRanking()
  }
}

async function loadRanking() {
  if (!selectedRankingCatId.value) return
  ranking.value = null
  const res = await apiFetch<any>(`/public/campeonatos/${route.params.id}/categorias/${selectedRankingCatId.value}/ranking`)
  ranking.value = res.data
}

function switchTab(t: typeof tab.value) {
  tab.value = t
  loadTab()
}

async function activar() {
  if (!confirm('Activar este campeonato?')) return
  await apiMutate('POST', `/campeonatos/${route.params.id}/activar`)
  showToast('Campeonato activado')
  await load()
}

async function finalizar() {
  if (!confirm('Finalizar campeonato?')) return
  await apiMutate('POST', `/campeonatos/${route.params.id}/finalizar`)
  showToast('Campeonato finalizado')
  await load()
}

async function eliminar() {
  if (!confirm('Eliminar este campeonato?')) return
  try {
    await apiMutate('DELETE', `/campeonatos/${route.params.id}`)
    router.push('/admin/campeonatos')
  } catch (e: any) {
    showToast(e.message || 'Error', 'error')
  }
}

</script>

<template>
  <div class="min-h-screen bg-gray-50">
    <header class="bg-[var(--brand-dark)] text-white px-4 py-3">
      <div class="max-w-2xl mx-auto">
        <div class="flex items-center gap-3 mb-2">
          <button @click="router.push('/admin/campeonatos')" class="text-white/60 hover:text-white">&larr;</button>
          <h1 class="font-bold truncate text-sm">{{ campeonato?.nombre || 'Cargando...' }} {{ campeonato?.anio }}</h1>
          <span v-if="campeonato" :class="['text-[10px] px-2 py-0.5 rounded-full ml-auto shrink-0', estadoColor(campeonato.estado)]">
            {{ estadoLabel(campeonato.estado) }}
          </span>
        </div>
        <div class="flex gap-1">
          <button v-for="t in (['datos', 'fechas', 'ranking'] as const)" :key="t"
            @click="switchTab(t)"
            :class="['px-3 py-1.5 rounded-t-lg text-xs font-medium transition-colors whitespace-nowrap',
              tab === t ? 'bg-gray-50 text-gray-800' : 'text-white/60 hover:text-white']">
            {{ { datos: 'Datos', fechas: 'Fechas', ranking: 'Ranking' }[t] }}
          </button>
        </div>
      </div>
    </header>

    <main class="max-w-2xl mx-auto px-4 py-4 space-y-4">
      <!-- DATOS -->
      <div v-if="tab === 'datos' && campeonato" class="space-y-4">
        <div class="bg-white rounded-xl p-4 shadow-sm space-y-2">
          <div class="text-sm"><span class="text-gray-400">Nombre:</span> <span class="font-medium">{{ campeonato.nombre }}</span></div>
          <div class="text-sm"><span class="text-gray-400">Ano:</span> <span class="font-medium">{{ campeonato.anio }}</span></div>
          <div class="text-sm"><span class="text-gray-400">Fechas:</span> <span class="font-medium">{{ campeonato.fechas_count || 0 }}</span></div>
          <div class="text-sm"><span class="text-gray-400">Pista:</span> <span class="font-medium">{{ campeonato.tipo_pista_forzado ? (campeonato.tipo_pista_forzado === 'simple' ? 'Siempre simple' : 'Siempre doble') : 'Cada fecha elige' }}</span></div>
        </div>
        <p class="text-xs text-gray-400">
          El ranking se calcula automaticamente sumando los puntos que cada piloto recibe en cada fecha.
          Los puntos se cargan manualmente por tripulacion en cada fecha.
        </p>
        <div class="space-y-2">
          <button v-if="campeonato.estado === 'borrador'" @click="activar"
            class="w-full bg-green-600 hover:bg-green-700 text-white font-bold py-3 rounded-lg">Activar campeonato</button>
          <button v-if="campeonato.estado === 'activo'" @click="finalizar"
            class="w-full bg-red-600 hover:bg-red-700 text-white font-bold py-3 rounded-lg">Finalizar campeonato</button>
          <button v-if="campeonato.estado === 'borrador'" @click="eliminar"
            class="w-full bg-red-100 hover:bg-red-200 text-red-700 font-medium py-2.5 rounded-lg">Eliminar</button>
        </div>
      </div>

      <!-- FECHAS -->
      <div v-if="tab === 'fechas'" class="space-y-2">
        <div v-if="!fechas.length" class="text-center py-8 text-gray-400">No hay fechas asociadas</div>
        <button v-for="f in fechas" :key="f.id" @click="router.push(`/admin/fecha/${f.id}`)"
          class="w-full bg-white rounded-lg p-3 shadow-sm text-left hover:shadow-md transition-shadow flex items-center justify-between">
          <div>
            <p class="font-medium text-gray-800 text-sm">{{ f.nombre }}</p>
            <p class="text-xs text-gray-500">{{ formatFecha(f.fecha) }} &middot; {{ f.tripulaciones_count || 0 }} trips</p>
          </div>
          <span :class="['text-[10px] px-2 py-0.5 rounded-full', estadoColor(f.estado)]">{{ estadoLabel(f.estado) }}</span>
        </button>
        <p class="text-xs text-gray-400 mt-2">Para asociar fechas, crealas desde el Dashboard seleccionando este campeonato.</p>
      </div>

      <!-- RANKING -->
      <div v-if="tab === 'ranking'" class="space-y-3">
        <div v-if="!categorias.length" class="text-center py-8 text-gray-400">
          No hay datos. Las categorias aparecen cuando hay fechas finalizadas.
        </div>
        <template v-else>
          <div class="flex gap-1.5 overflow-x-auto">
            <button v-for="c in categorias" :key="c.id" @click="selectedRankingCatId = c.id; loadRanking()"
              :class="['px-3 py-1 rounded-full text-xs font-medium whitespace-nowrap',
                c.id === selectedRankingCatId ? 'bg-[var(--brand-color)] text-white' : 'bg-gray-100 text-gray-600']">
              {{ c.nombre }}
            </button>
          </div>

          <div v-if="!ranking" class="text-center py-8 text-gray-400">Cargando...</div>

          <div v-else-if="!ranking.ranking?.length" class="text-center py-8 text-gray-400">
            No hay datos. Finaliza alguna fecha con puntos cargados.
          </div>

          <template v-else>
            <div class="bg-white rounded-xl shadow-sm overflow-x-auto">
              <table class="w-full text-sm min-w-[500px]">
                <thead>
                  <tr class="bg-gray-50 text-gray-500 text-[10px] uppercase">
                    <th class="py-2 px-2 text-left w-8">Pos</th>
                    <th class="py-2 px-2 text-left w-8">N</th>
                    <th class="py-2 px-2 text-left">Piloto</th>
                    <th class="py-2 px-2 text-left">Copiloto</th>
                    <th class="py-2 px-2 text-center font-bold">Total</th>
                    <th v-for="f in ranking.fechas" :key="f.id" class="py-2 px-1.5 text-center max-w-[60px] truncate" :title="f.nombre">
                      {{ f.nombre.substring(0, 8) }}
                    </th>
                  </tr>
                </thead>
                <tbody>
                  <tr v-for="r in ranking.ranking" :key="r.piloto" class="border-t border-gray-100">
                    <td class="py-2 px-2 font-bold" :class="r.posicion <= 3 ? 'text-[var(--brand-color)]' : ''">{{ r.posicion }}</td>
                    <td class="py-2 px-2 font-mono text-gray-500">{{ r.numero }}</td>
                    <td class="py-2 px-2 font-medium text-gray-800 truncate">{{ r.piloto }}</td>
                    <td class="py-2 px-2 text-gray-500 text-xs truncate">{{ r.copiloto || '-' }}</td>
                    <td class="py-2 px-2 text-center font-bold text-[var(--brand-color)]">{{ r.puntos_total }}</td>
                    <td v-for="fd in r.fechas_detalle" :key="fd.fecha_id" class="py-2 px-1.5 text-center text-xs">
                      <span v-if="fd.participo && fd.puntos > 0" class="font-medium">{{ fd.puntos }}</span>
                      <span v-else-if="fd.participo" class="text-gray-300">0</span>
                      <span v-else class="text-gray-200">-</span>
                    </td>
                  </tr>
                </tbody>
              </table>
            </div>
          </template>
        </template>
      </div>
    </main>

    <Transition enter-active-class="transition-all duration-300 ease-out" enter-from-class="translate-y-full opacity-0"
      enter-to-class="translate-y-0 opacity-100" leave-active-class="transition-all duration-200 ease-in"
      leave-from-class="translate-y-0 opacity-100" leave-to-class="translate-y-full opacity-0">
      <div v-if="toast.show"
        :class="['fixed bottom-4 left-4 right-4 max-w-2xl mx-auto rounded-lg px-4 py-3 text-white font-medium shadow-lg text-center',
          toast.type === 'success' ? 'bg-green-600' : 'bg-red-600']">
        {{ toast.msg }}
      </div>
    </Transition>
  </div>
</template>
