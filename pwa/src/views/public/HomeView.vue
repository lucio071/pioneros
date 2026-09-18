<script setup lang="ts">
import { ref, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import { apiFetch } from '../../api'

const router = useRouter()
const fechaActiva = ref<any>(null)
const fechasFin = ref<any[]>([])
const campeonatoActivo = ref<any>(null)
const loading = ref(true)

// PWA install
const installPrompt = ref<any>(null)
const canInstall = ref(false)
const isInstalled = ref(false)

if (window.matchMedia('(display-mode: standalone)').matches) {
  isInstalled.value = true
}

window.addEventListener('beforeinstallprompt', (e) => {
  e.preventDefault()
  installPrompt.value = e
  canInstall.value = true
})

async function instalarApp() {
  if (!installPrompt.value) return
  installPrompt.value.prompt()
  const result = await installPrompt.value.userChoice
  if (result.outcome === 'accepted') {
    canInstall.value = false
    isInstalled.value = true
  }
}


onMounted(async () => {
  try { const r = await apiFetch<any>('/public/fechas/activa'); fechaActiva.value = r.data } catch {}
  try { const r = await apiFetch<any[]>('/public/fechas'); fechasFin.value = r.data || [] } catch {}
  try { const r = await apiFetch<any>('/public/campeonatos/activo'); campeonatoActivo.value = r.data } catch {}
  loading.value = false
})
</script>

<template>
  <div class="min-h-screen bg-[var(--brand-dark)] text-white">
    <header class="p-6 text-center">
      <img src="/logo.jpg" alt="Club Pioneros de 4x4" class="w-28 h-28 mx-auto rounded-full shadow-lg mb-3" />
      <h1 class="text-3xl font-bold tracking-tight">Pioneros 4x4</h1>
      <p class="text-white/60 mt-1">Club de Ciudad del Este</p>
    </header>

    <main class="max-w-lg mx-auto px-4 pb-8">
      <!-- Instalar app -->
      <button v-if="canInstall && !isInstalled" @click="instalarApp"
        class="w-full bg-white/10 hover:bg-white/20 border border-white/20 rounded-xl p-4 mb-4 flex items-center gap-3 transition-colors">
        <span class="text-2xl">📲</span>
        <div class="text-left">
          <p class="font-bold text-sm">Instalar Pioneros 4x4</p>
          <p class="text-white/50 text-xs">Agrega la app a tu celular para acceso rapido</p>
        </div>
      </button>

      <div v-if="loading" class="text-center py-12 text-white/50">Cargando...</div>

      <template v-else>
        <div v-if="fechaActiva" class="space-y-4">
          <div class="bg-white/[0.07] rounded-xl p-6 border border-white/10">
            <div class="flex items-center gap-2 mb-3">
              <span class="w-2.5 h-2.5 bg-green-400 rounded-full animate-pulse"></span>
              <span class="text-green-400 text-sm font-medium uppercase tracking-wider">En vivo</span>
            </div>
            <h2 class="text-xl font-bold">{{ fechaActiva.nombre }}</h2>
            <p class="text-white/50 text-sm mt-1">{{ fechaActiva.tripulaciones_count || 0 }} tripulaciones</p>
            <div class="flex flex-wrap gap-1.5 mt-3">
              <span class="text-xs bg-white/10 rounded-full px-2 py-0.5">{{ fechaActiva.tipo_pista === 'doble' ? 'Pista doble' : 'Pista simple' }}</span>
              <span class="text-xs bg-white/10 rounded-full px-2 py-0.5">{{ fechaActiva.vueltas_clasificacion }} vueltas</span>
              <span v-if="fechaActiva.tiene_final" class="text-xs bg-white/10 rounded-full px-2 py-0.5">Final Top {{ fechaActiva.finalistas_top }}</span>
              <span class="text-xs bg-white/10 rounded-full px-2 py-0.5">{{ fechaActiva.categorias?.length || 0 }} categorias</span>
            </div>
            <button @click="router.push('/vivo')"
              class="mt-4 w-full bg-[var(--brand-accent)] hover:brightness-110 text-[var(--brand-dark)] font-bold py-3.5 rounded-lg text-lg transition-all">
              Ver carrera en vivo
            </button>
          </div>
          <button @click="router.push('/elegir-tripulacion')"
            class="w-full bg-white/10 hover:bg-white/15 text-white/80 py-3 rounded-lg transition-colors">
            Seguir a mi tripulacion
          </button>
        </div>

        <div v-else class="text-center py-12">
          <p class="text-white/50 text-lg">No hay carrera en curso</p>
        </div>

        <!-- Campeonato -->
        <div v-if="campeonatoActivo" class="mt-4">
          <button @click="router.push('/campeonato')"
            class="w-full bg-white/[0.07] border border-white/10 rounded-xl p-4 text-left hover:bg-white/10 transition-colors">
            <p class="text-[var(--brand-accent)] text-xs font-medium uppercase tracking-wider mb-1">Campeonato</p>
            <p class="text-white font-bold">{{ campeonatoActivo.nombre }} {{ campeonatoActivo.anio }}</p>
            <p class="text-white/50 text-sm">{{ campeonatoActivo.fechas_count || 0 }} fechas</p>
          </button>
        </div>

        <!-- Historico -->
        <div v-if="fechasFin.length" class="mt-8">
          <h3 class="text-white/40 text-xs uppercase tracking-wider mb-3 px-1">Resultados anteriores</h3>
          <div class="space-y-2">
            <button v-for="f in fechasFin" :key="f.id" @click="router.push('/resultados')"
              class="w-full bg-white/5 hover:bg-white/10 rounded-lg p-4 text-left transition-colors">
              <p class="font-medium">{{ f.nombre }}</p>
              <p class="text-white/40 text-sm">{{ f.categorias?.length || 0 }} categorias</p>
            </button>
          </div>
        </div>


        <div class="mt-12 text-center">
          <router-link to="/login" class="text-white/20 hover:text-white/40 text-sm transition-colors">
            Cronometrista
          </router-link>
        </div>
      </template>
    </main>

  </div>
</template>
