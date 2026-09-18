<script setup lang="ts">
import { ref, onMounted, onUnmounted } from 'vue'
import { useRouter } from 'vue-router'
import { apiFetch, apiMutate } from '../../api'
import { useToast } from '../../composables/useToast'

const router = useRouter()
const dispositivos = ref<any[]>([])
const loading = ref(true)
const tokenModal = ref<{ show: boolean; codigo: string; token: string }>({ show: false, codigo: '', token: '' })
const { toast, showToast } = useToast()
let pollTimer: ReturnType<typeof setInterval> | null = null

onMounted(async () => {
  await loadData()
  loading.value = false
  // Polling cada 5 segundos
  pollTimer = setInterval(loadData, 5000)
})

onUnmounted(() => {
  if (pollTimer) clearInterval(pollTimer)
})

async function loadData() {
  try {
    const res = await apiFetch<any[]>('/dispositivos-cronometro')
    dispositivos.value = res.data || []
  } catch {}
}

async function verToken(id: string) {
  const res = await apiFetch<any>(`/dispositivos-cronometro/${id}/token`)
  tokenModal.value = { show: true, codigo: res.data.codigo, token: res.data.api_token }
}

async function regenerarToken(id: string) {
  if (!confirm('Regenerar el token? El dispositivo dejara de conectar hasta que se actualice el firmware con el nuevo token.')) return
  const res = await apiMutate<any>('POST', `/dispositivos-cronometro/${id}/regenerar-token`)
  tokenModal.value = { show: true, codigo: res.codigo, token: res.api_token }
  showToast('Token regenerado')
}

async function toggleActivo(d: any) {
  await apiMutate('PUT', `/dispositivos-cronometro/${d.id}`, { activo: !d.activo })
  await loadData()
}

function copiarToken() {
  navigator.clipboard.writeText(tokenModal.value.token)
  showToast('Token copiado al portapapeles')
}

function formatTiempoAtras(segundos: number | null): string {
  if (segundos === null) return 'Nunca'
  if (segundos < 60) return `hace ${segundos}s`
  if (segundos < 3600) return `hace ${Math.floor(segundos / 60)}m`
  if (segundos < 86400) return `hace ${Math.floor(segundos / 3600)}h`
  return `hace ${Math.floor(segundos / 86400)}d`
}

</script>

<template>
  <div class="min-h-screen bg-gray-50">
    <header class="bg-[var(--brand-dark)] text-white px-4 py-4">
      <div class="max-w-2xl mx-auto flex items-center gap-3">
        <button @click="router.push('/admin')" class="text-white/60 hover:text-white">&larr;</button>
        <h1 class="font-bold">Dispositivos</h1>
        <span class="text-white/40 text-xs ml-auto">Auto-refresh 5s</span>
      </div>
    </header>

    <main class="max-w-2xl mx-auto px-4 py-4 space-y-3">
      <div v-if="loading" class="text-center py-8 text-gray-400">Cargando...</div>

      <div v-else class="space-y-2">
        <div v-for="d in dispositivos" :key="d.id"
          class="bg-white rounded-xl p-4 shadow-sm">
          <div class="flex items-center gap-3">
            <!-- Estado indicator -->
            <div :class="['w-3 h-3 rounded-full shrink-0',
              d.estado === 'online' ? 'bg-green-500 animate-pulse' :
              d.estado === 'nunca' ? 'bg-yellow-400' : 'bg-gray-300']">
            </div>

            <!-- Info -->
            <div class="flex-1 min-w-0">
              <div class="flex items-center gap-2">
                <span class="font-bold text-gray-800 text-sm">{{ d.nombre }}</span>
                <span class="text-[10px] bg-gray-100 text-gray-500 px-1.5 py-0.5 rounded font-mono">{{ d.codigo }}</span>
              </div>
              <div class="flex items-center gap-2 mt-0.5 text-xs text-gray-400">
                <span>{{ d.tipo }}</span>
                <span v-if="d.tramo">&middot; Tramo {{ d.tramo }}</span>
                <span>&middot; {{ formatTiempoAtras(d.segundos_desde_heartbeat) }}</span>
              </div>
            </div>

            <!-- Estado badge -->
            <span :class="['text-[10px] px-2 py-0.5 rounded-full font-medium shrink-0',
              d.estado === 'online' ? 'bg-green-100 text-green-700' :
              d.estado === 'nunca' ? 'bg-yellow-100 text-yellow-700' : 'bg-gray-100 text-gray-500']">
              {{ d.estado === 'online' ? 'Online' : d.estado === 'nunca' ? 'Nunca conectado' : 'Offline' }}
            </span>
          </div>

          <!-- Telemetria -->
          <div v-if="d.ultimo_rssi !== null" class="flex gap-4 mt-2 text-xs text-gray-400">
            <span>RSSI: {{ d.ultimo_rssi }} dBm</span>
            <span>{{ (d.ultimo_voltaje_mv / 1000).toFixed(1) }}V</span>
            <span v-if="d.ultimo_uptime_sec">Uptime: {{ Math.floor((d.ultimo_uptime_sec || 0) / 60) }}m</span>
          </div>

          <!-- Actions -->
          <div class="flex items-center gap-2 mt-3 border-t border-gray-100 pt-2">
            <button @click="verToken(d.id)"
              class="text-xs bg-blue-50 hover:bg-blue-100 text-blue-700 px-3 py-1.5 rounded-lg font-medium transition-colors">
              Ver token
            </button>
            <button @click="regenerarToken(d.id)"
              class="text-xs bg-orange-50 hover:bg-orange-100 text-orange-700 px-3 py-1.5 rounded-lg font-medium transition-colors">
              Regenerar
            </button>
            <button @click="toggleActivo(d)"
              :class="['text-xs px-3 py-1.5 rounded-lg font-medium transition-colors ml-auto',
                d.activo ? 'bg-green-50 text-green-700 hover:bg-green-100' : 'bg-red-50 text-red-600 hover:bg-red-100']">
              {{ d.activo ? 'Activo' : 'Inactivo' }}
            </button>
          </div>
        </div>
      </div>
    </main>

    <!-- Token modal -->
    <div v-if="tokenModal.show" class="fixed inset-0 bg-black/50 flex items-center justify-center z-50 p-4" @click.self="tokenModal.show = false">
      <div class="bg-white rounded-xl p-5 max-w-lg w-full shadow-xl space-y-3">
        <h3 class="font-bold text-gray-800">Token de {{ tokenModal.codigo }}</h3>
        <p class="text-xs text-gray-400">Copia este token al firmware del ESP32</p>
        <div class="bg-gray-50 rounded-lg p-3 font-mono text-xs text-gray-700 break-all select-all">
          {{ tokenModal.token }}
        </div>
        <div class="flex gap-2">
          <button @click="copiarToken"
            class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg text-sm font-medium">
            Copiar
          </button>
          <button @click="tokenModal.show = false"
            class="text-gray-500 px-4 py-2 text-sm">
            Cerrar
          </button>
        </div>
      </div>
    </div>

    <!-- Toast -->
    <div v-if="toast.show" class="fixed bottom-4 left-1/2 -translate-x-1/2 bg-green-600 text-white px-6 py-2 rounded-lg shadow-lg text-sm font-medium">
      {{ toast.msg }}
    </div>
  </div>
</template>
