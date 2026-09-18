<script setup lang="ts">
import { onMounted, onUnmounted, computed, ref } from 'vue'
import { useCarreraStore } from '../../stores/carrera'
import { usePolling } from '../../composables/usePolling'
import { formatTiempo, formatDiferencia, estadoLabel, estadoColor, faseLabel } from '../../utils'
import { apiFetch } from '../../api'

const store = useCarreraStore()
const secondsAgo = ref(0)
let _timer: ReturnType<typeof setInterval> | null = null
const patrocinadores = ref<any[]>([])
const top3General = ref<any[]>([])
const secundarios = computed(() => patrocinadores.value.filter(p => p.nivel === 'secundario'))

onMounted(async () => {
  await store.fetchFechaActiva()
  try { const r = await apiFetch<any[]>('/public/patrocinadores'); patrocinadores.value = r.data || [] } catch {}
  if (store.fechaActiva) { try { const r = await apiFetch<any[]>('/public/fechas/' + store.fechaActiva.id + '/top3'); top3General.value = r.data || [] } catch {} }
  _timer = setInterval(() => {
    secondsAgo.value = Math.floor((Date.now() - store.lastUpdate) / 1000)
  }, 1000)
})

usePolling()

onUnmounted(() => { if (_timer) clearInterval(_timer) })

const connectionStatus = computed(() => {
  if (!store.online) return 'offline'
  if (secondsAgo.value > 10) return 'stale'
  return 'live'
})

function selectCategoria(id: string) {
  store.setCategoria(id)
}
</script>

<template>
  <div class="min-h-screen bg-gray-50 pb-16">
    <!-- Sticky header -->
    <header class="sticky top-0 z-50 bg-[var(--brand-dark)] text-white shadow-lg">
      <div class="max-w-2xl mx-auto px-4 py-3 flex items-center justify-between">
        <div class="min-w-0">
          <h1 class="font-bold truncate text-sm">{{ store.fechaActiva?.nombre || 'Pioneros 4x4' }}</h1>
          <p v-if="store.fechaActiva" class="text-[10px] text-white/50">
            {{ store.fechaActiva.tipo_pista === 'doble' ? 'Doble' : 'Simple' }}
            &middot; {{ store.fechaActiva.vueltas_clasificacion }}V
            <template v-if="store.fechaActiva.tiene_final"> + Final</template>
          </p>
        </div>
        <div class="flex items-center gap-2 shrink-0 ml-3">
          <span class="w-2 h-2 rounded-full"
            :class="{ 'bg-green-400 animate-pulse': connectionStatus === 'live', 'bg-yellow-400': connectionStatus === 'stale', 'bg-red-400': connectionStatus === 'offline' }">
          </span>
          <span class="text-xs text-white/60">
            {{ connectionStatus === 'live' ? 'EN VIVO' : connectionStatus === 'stale' ? `${secondsAgo}s` : 'Sin conexion' }}
          </span>
        </div>
      </div>

      <!-- Category tabs -->
      <div v-if="store.categorias.length > 1" class="max-w-2xl mx-auto px-4 pb-2 flex gap-1.5 overflow-x-auto">
        <button
          v-for="cat in store.categorias" :key="cat.id"
          @click="selectCategoria(cat.id)"
          :class="['px-3 py-1.5 rounded-full text-xs font-medium whitespace-nowrap transition-colors',
            cat.id === store.selectedCategoriaId ? 'bg-white text-[var(--brand-dark)]' : 'bg-white/15 text-white/70 hover:bg-white/25']"
        >
          {{ cat.nombre }}
          <span :class="['ml-1 text-[10px]', cat.id === store.selectedCategoriaId ? 'text-gray-500' : 'text-white/40']">
            {{ faseLabel(cat.fase) }}
          </span>
        </button>
      </div>
    </header>

    <main class="max-w-2xl mx-auto px-4 py-4 space-y-3">
      <div v-if="!store.ranking" class="text-center py-12 text-gray-400">Cargando ranking...</div>

      <template v-else>
        <!-- Phase banner -->
        <div v-if="store.ranking.stats.fase === 'final'" class="bg-orange-50 border border-orange-200 rounded-lg p-3 text-center">
          <p class="text-orange-800 font-medium text-sm">Fase Final en curso &middot; Top {{ store.fechaActiva?.finalistas_top }}</p>
        </div>
        <div v-else-if="store.ranking.stats.fase === 'finalizada'" class="bg-green-50 border border-green-200 rounded-lg p-3 text-center">
          <p class="text-green-800 font-medium text-sm">Categoria finalizada</p>
        </div>

        <!-- Top 3 General -->
        <div v-if="top3General.length" class="bg-amber-50 border border-amber-200 rounded-xl p-3">
          <h3 class="text-xs text-amber-700 uppercase tracking-wider font-bold mb-2 text-center">Top 3 General</h3>
          <div v-for="t in top3General" :key="t.tripulacion_id" class="flex items-center gap-2 py-1">
            <span class="w-6 font-bold text-center" :class="t.posicion === 1 ? 'text-yellow-600' : t.posicion === 2 ? 'text-gray-500' : 'text-amber-700'">{{ t.posicion }}.</span>
            <span class="font-mono text-gray-400 text-sm">#{{ t.numero }}</span>
            <span class="flex-1 text-gray-800 text-sm truncate">{{ t.piloto }}</span>
            <span class="text-xs text-gray-400">{{ t.categoria }}</span>
            <span class="font-mono text-sm font-bold">{{ formatTiempo(t.mejor_vuelta_ms) }}</span>
          </div>
        </div>

        <!-- Stats -->
        <div class="grid grid-cols-4 gap-2">
          <div class="bg-white rounded-lg p-2 text-center shadow-sm">
            <p class="text-lg font-bold text-gray-800">{{ store.ranking.stats.total }}</p>
            <p class="text-[10px] text-gray-400 uppercase">Total</p>
          </div>
          <div class="bg-white rounded-lg p-2 text-center shadow-sm">
            <p class="text-lg font-bold text-yellow-600">{{ store.ranking.stats.en_pista }}</p>
            <p class="text-[10px] text-gray-400 uppercase">En pista</p>
          </div>
          <div class="bg-white rounded-lg p-2 text-center shadow-sm">
            <p class="text-lg font-bold text-blue-600">{{ store.ranking.stats.completadas }}</p>
            <p class="text-[10px] text-gray-400 uppercase">Completas</p>
          </div>
          <div class="bg-white rounded-lg p-2 text-center shadow-sm">
            <p class="text-lg font-bold text-red-500">{{ store.ranking.stats.nulas }}</p>
            <p class="text-[10px] text-gray-400 uppercase">DNF</p>
          </div>
        </div>

        <!-- Mi tripulacion -->
        <div v-if="store.miTripulacion" class="bg-[var(--brand-color)] text-white rounded-xl p-4 shadow-lg">
          <div class="flex items-center justify-between">
            <div>
              <p class="text-xs text-white/60">Mi tripulacion</p>
              <p class="text-2xl font-bold">#{{ store.miTripulacion.numero }}</p>
              <p class="text-sm">{{ store.miTripulacion.nombre }}</p>
            </div>
            <div class="text-right">
              <p v-if="store.miTripulacion.posicion" class="text-3xl font-bold">{{ store.miTripulacion.posicion }}°</p>
              <p class="text-sm text-white/70">
                {{ store.miTripulacion.mejor_vuelta_ms ? formatTiempo(store.miTripulacion.mejor_vuelta_ms) : estadoLabel(store.miTripulacion.estado) }}
              </p>
            </div>
          </div>
        </div>

        <!-- Final ranking (if in final phase) -->
        <div v-if="store.ranking.final_ranking?.length" class="bg-orange-50 rounded-xl shadow-sm p-3">
          <h3 class="text-xs text-orange-600 uppercase tracking-wider font-medium mb-2">Ranking Final</h3>
          <div class="space-y-2">
            <div v-for="f in store.ranking.final_ranking" :key="f.id"
              class="bg-white rounded-lg p-2.5 flex items-center gap-3">
              <div class="w-8 h-8 rounded-full flex items-center justify-center font-bold text-sm"
                :class="f.posicion === 1 ? 'bg-[var(--brand-accent)] text-[var(--brand-dark)]' : f.posicion === 2 ? 'bg-gray-300 text-gray-700' : 'bg-amber-700 text-white'">
                {{ f.posicion }}
              </div>
              <div class="flex-1 min-w-0">
                <p class="font-medium text-gray-800 text-sm">#{{ f.numero }} {{ f.nombre }}</p>
              </div>
              <div class="text-right font-mono text-sm">
                <p class="text-gray-800">{{ formatTiempo(f.vuelta_final?.total_vuelta) }}</p>
                <p v-if="f.diferencia" class="text-xs text-red-400">{{ formatDiferencia(f.diferencia) }}</p>
              </div>
            </div>
          </div>
        </div>

        <!-- Classification ranking -->
        <div>
          <h3 class="text-xs text-gray-400 uppercase tracking-wider mb-2 px-1">
            Clasificacion (mejor de {{ store.fechaActiva?.vueltas_clasificacion }} vueltas)
          </h3>
          <div class="space-y-2">
            <div v-for="t in store.ranking.ranking" :key="t.id"
              class="bg-white rounded-xl shadow-sm p-3 transition-colors"
              :class="{ 'ring-2 ring-[var(--brand-color)]': t.id === store.miTripulacionId }">
              <!-- Header -->
              <div class="flex items-center gap-3 mb-2">
                <div class="w-8 h-8 rounded-lg flex items-center justify-center font-bold text-sm shrink-0"
                  :class="t.posicion === 1 ? 'bg-[var(--brand-accent)] text-[var(--brand-dark)]' : t.posicion === 2 ? 'bg-gray-300 text-gray-700' : t.posicion === 3 ? 'bg-amber-700 text-white' : 'bg-gray-200 text-gray-500'">
                  {{ t.posicion }}
                </div>
                <div class="min-w-0 flex-1">
                  <p class="font-bold text-gray-800 text-sm truncate">
                    <span class="text-gray-400 font-mono">#{{ t.numero }}</span> {{ t.nombre }}
                  </p>
                  <p class="text-xs text-gray-400">{{ t.piloto }}</p>
                </div>
                <div class="text-right shrink-0">
                  <p class="font-bold font-mono text-gray-800 text-sm">{{ formatTiempo(t.mejor_vuelta_ms) }}</p>
                  <p v-if="t.diferencia" class="text-xs text-red-400 font-mono">{{ formatDiferencia(t.diferencia) }}</p>
                </div>
              </div>

              <!-- Vueltas detail -->
              <div class="flex gap-1 overflow-x-auto border-t border-gray-100 pt-2">
                <div v-for="v in t.vueltas" :key="v.id"
                  :class="['bg-gray-50 rounded-lg px-2 py-1 text-xs min-w-[70px] shrink-0',
                    v.numero_vuelta === t.mejor_vuelta_numero && !v.nula ? 'ring-1 ring-[var(--brand-color)] bg-green-50' : '']">
                  <p class="text-gray-400 font-medium">V{{ v.numero_vuelta }}</p>
                  <p v-if="v.nula" class="text-red-500 font-medium">NULA</p>
                  <p v-else class="font-mono text-gray-700">{{ formatTiempo(v.total_vuelta) }}</p>
                  <!-- Tramos mini -->
                  <div v-if="!v.nula && v.tramos.length > 1" class="mt-0.5 space-y-0.5">
                    <div v-for="tr in v.tramos" :key="tr.id" class="flex justify-between text-[10px] text-gray-400">
                      <span>{{ tr.letra }}</span>
                      <span class="font-mono">{{ formatTiempo(tr.tiempo_con_penal) }}</span>
                    </div>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>

        <!-- En curso -->
        <div v-if="store.ranking.en_curso.length" class="bg-white rounded-xl shadow-sm p-3">
          <h3 class="text-xs text-gray-400 uppercase tracking-wider mb-2">En curso</h3>
          <div class="space-y-1.5">
            <div v-for="t in store.ranking.en_curso" :key="t.id" class="flex items-center justify-between text-sm">
              <div class="flex items-center gap-2">
                <span class="font-mono text-gray-500">#{{ t.numero }}</span>
                <span class="text-gray-800">{{ t.nombre }}</span>
              </div>
              <span :class="['text-xs px-2 py-0.5 rounded-full', estadoColor(t.estado)]">
                {{ estadoLabel(t.estado) }}
                <span v-if="t.vueltas.length" class="ml-0.5 text-[10px]">({{ t.vueltas.length }}V)</span>
              </span>
            </div>
          </div>
        </div>

        <!-- DNF -->
        <details v-if="store.ranking.dnf.length" class="bg-white rounded-xl shadow-sm">
          <summary class="p-3 text-xs text-gray-400 uppercase tracking-wider cursor-pointer">DNF ({{ store.ranking.dnf.length }})</summary>
          <div class="px-3 pb-3 space-y-1">
            <div v-for="t in store.ranking.dnf" :key="t.id" class="text-sm text-gray-500">#{{ t.numero }} {{ t.nombre }}</div>
          </div>
        </details>
      </template>
    </main>

    <!-- Sponsors secundarios -->
    <div v-if="secundarios.length" class="max-w-2xl mx-auto px-4 py-3 mb-16">
      <p class="text-[10px] text-gray-300 uppercase tracking-wider text-center mb-2">Auspician</p>
      <div class="flex items-center justify-center gap-4 flex-wrap">
        <a v-for="p in secundarios" :key="p.id" :href="p.website" target="_blank" rel="noopener">
          <img v-if="p.logo_url" :src="p.logo_url" :alt="p.nombre_comercial" class="h-8 object-contain opacity-50 hover:opacity-100 transition-opacity" loading="lazy" />
        </a>
      </div>
    </div>

    <!-- Bottom nav -->
    <nav class="fixed bottom-0 inset-x-0 bg-white border-t border-gray-200 px-4 py-2 flex justify-around max-w-2xl mx-auto">
      <router-link to="/" class="text-gray-400 hover:text-gray-600 text-xs text-center py-1">Inicio</router-link>
      <router-link to="/elegir-tripulacion" class="text-gray-400 hover:text-gray-600 text-xs text-center py-1">Mi equipo</router-link>
      <router-link to="/resultados" class="text-gray-400 hover:text-gray-600 text-xs text-center py-1">Resultados</router-link>
    </nav>
  </div>
</template>
