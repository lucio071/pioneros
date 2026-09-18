<script setup lang="ts">
import { ref, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import { useAuthStore } from '../../stores/auth'
import { apiFetch, apiMutate } from '../../api'
import { estadoLabel, estadoColor, formatFecha } from '../../utils'
import type { Fecha, CategoriaCatalogo, Campeonato } from '../../types'

const router = useRouter()
const auth = useAuthStore()
const fechas = ref<Fecha[]>([])
const catalogo = ref<CategoriaCatalogo[]>([])
const campeonatos = ref<Campeonato[]>([])
const loading = ref(true)

// Wizard state
const showWizard = ref(false)
const wizardStep = ref(1)
const fechaForm = ref({
  nombre: '', fecha: '', tipo_pista: 'doble' as const,
  vueltas_clasificacion: 2, tiene_final: false, finalistas_top: 3,
  campeonato_id: null as string | null,
})
const newFechaId = ref<string | null>(null)
const catSelections = ref<Record<string, { selected: boolean; penal_estaca_seg: number; penal_cinta_seg: number }>>({})

// Category catalog
const showCatForm = ref(false)
const catForm = ref({ nombre: '' })

onMounted(async () => {
  await Promise.all([loadFechas(), loadCatalogo(), loadCampeonatos()])
  loading.value = false
})

async function loadFechas() {
  const res = await apiFetch<Fecha[]>('/fechas')
  fechas.value = res.data || []
}

async function loadCampeonatos() {
  try {
    const res = await apiFetch<Campeonato[]>('/campeonatos')
    campeonatos.value = res.data || []
  } catch { campeonatos.value = [] }
}

async function loadCatalogo() {
  const res = await apiFetch<CategoriaCatalogo[]>('/categorias')
  catalogo.value = res.data || []
}

async function crearCategoria() {
  await apiMutate('POST', '/categorias', catForm.value)
  catForm.value = { nombre: '' }
  showCatForm.value = false
  await loadCatalogo()
}

async function eliminarCategoria(id: string) {
  if (!confirm('Eliminar esta categoria?')) return
  try {
    await apiMutate('DELETE', `/categorias/${id}`)
    await loadCatalogo()
  } catch (e: any) {
    alert(e.message || 'No se puede eliminar')
  }
}

// Wizard step 1
async function wizardNext() {
  // Si no hay categorías en el catálogo, crear la fecha directamente e ir a config
  if (catalogo.value.length === 0) {
    const res = await apiMutate<any>('POST', '/fechas', fechaForm.value)
    showWizard.value = false
    fechaForm.value = { nombre: '', fecha: '', tipo_pista: 'doble', vueltas_clasificacion: 2, tiene_final: false, finalistas_top: 3, campeonato_id: null }
    await loadFechas()
    router.push(`/admin/fecha/${res.id}`)
    return
  }
  const res = await apiMutate<any>('POST', '/fechas', fechaForm.value)
  newFechaId.value = res.id
  catSelections.value = {}
  catalogo.value.forEach(c => {
    catSelections.value[c.id] = { selected: false, penal_estaca_seg: 5, penal_cinta_seg: 10 }
  })
  wizardStep.value = 2
}

// Wizard step 2
async function wizardFinish() {
  if (!newFechaId.value) return
  for (const [catId, sel] of Object.entries(catSelections.value)) {
    if (sel.selected) {
      await apiMutate('POST', `/fechas/${newFechaId.value}/categorias`, {
        categoria_catalogo_id: catId,
        penal_estaca_seg: sel.penal_estaca_seg,
        penal_cinta_seg: sel.penal_cinta_seg,
      })
    }
  }
  showWizard.value = false
  wizardStep.value = 1
  fechaForm.value = { nombre: '', fecha: '', tipo_pista: 'doble', vueltas_clasificacion: 2, tiene_final: false, finalistas_top: 3, campeonato_id: null }
  await loadFechas()
  router.push(`/admin/fecha/${newFechaId.value}`)
}

async function logout() {
  await auth.logout()
  router.push('/')
}
</script>

<template>
  <div class="min-h-screen bg-gray-50">
    <header class="bg-[var(--brand-dark)] text-white px-4 py-4">
      <div class="max-w-2xl mx-auto flex items-center justify-between">
        <h1 class="font-bold">Panel del cronometrista</h1>
        <button @click="logout" class="text-sm text-white/60 hover:text-white">Salir</button>
      </div>
    </header>

    <main class="max-w-2xl mx-auto px-4 py-4 space-y-4">
      <!-- Campeonatos link -->
      <button @click="router.push('/admin/campeonatos')"
        class="w-full bg-[var(--brand-accent)] hover:brightness-110 text-[var(--brand-dark)] font-medium py-2.5 rounded-lg transition-all text-sm">
        Campeonatos ({{ campeonatos.length }})
      </button>
      <button @click="router.push('/admin/patrocinadores')"
        class="w-full bg-white/10 hover:bg-white/20 text-[var(--brand-dark)] font-medium py-2.5 rounded-lg transition-all text-sm border border-gray-300">
        Patrocinadores
      </button>
      <button @click="router.push('/admin/dispositivos')"
        class="w-full bg-white/10 hover:bg-white/20 text-[var(--brand-dark)] font-medium py-2.5 rounded-lg transition-all text-sm border border-gray-300">
        Dispositivos
      </button>
      <!-- Category catalog - always visible -->
      <div class="bg-white rounded-xl shadow-sm p-4 space-y-3">
        <h3 class="font-medium text-gray-700">Categorias</h3>
        <div v-if="!catalogo.length" class="text-sm text-gray-400 py-1">
          No hay categorias creadas. Crea al menos una antes de crear fechas.
        </div>
        <div class="flex flex-wrap gap-2">
          <span v-for="c in catalogo" :key="c.id" class="bg-gray-100 rounded-lg px-3 py-1.5 text-sm flex items-center gap-2">
            <span class="font-medium text-gray-800">{{ c.nombre }}</span>
            <button @click="eliminarCategoria(c.id)" class="text-red-400 hover:text-red-600 text-xs">&times;</button>
          </span>
        </div>
        <div v-if="showCatForm" class="flex gap-2">
          <input v-model="catForm.nombre" placeholder="Ej: Standard, Modificado..."
            class="flex-1 rounded-lg border border-gray-300 px-3 py-2 text-sm outline-none focus:ring-2 focus:ring-blue-500"
            @keyup.enter="crearCategoria" />
          <button @click="crearCategoria" class="bg-green-600 text-white px-4 py-2 rounded-lg text-sm font-medium">Crear</button>
          <button @click="showCatForm = false" class="text-gray-400 px-2">X</button>
        </div>
        <button v-else @click="showCatForm = true" class="text-sm text-blue-600 hover:text-blue-800 font-medium">+ Nueva categoria</button>
      </div>

      <!-- New fecha -->
      <button v-if="!showWizard" @click="showWizard = true; wizardStep = 1"
        class="w-full bg-[var(--brand-color)] hover:bg-[var(--brand-light)] text-white font-medium py-3 rounded-lg transition-colors">
        + Nueva fecha
      </button>

      <!-- Wizard -->
      <div v-if="showWizard" class="bg-white rounded-xl p-4 shadow-sm space-y-4">
        <div class="flex items-center justify-between">
          <h3 class="font-bold text-gray-800">Nueva fecha &middot; Paso {{ wizardStep }}/2</h3>
          <button @click="showWizard = false" class="text-gray-400 hover:text-gray-600">X</button>
        </div>

        <!-- Step 1 -->
        <template v-if="wizardStep === 1">
          <div class="space-y-3">
            <input v-model="fechaForm.nombre" placeholder="Nombre (ej: Desafio Hernandarias)" required
              class="w-full rounded-lg border border-gray-300 px-3 py-2 outline-none focus:ring-2 focus:ring-blue-500" />
            <div class="grid grid-cols-2 gap-3">
              <div>
                <label class="block text-xs text-gray-500 mb-1">Fecha</label>
                <input v-model="fechaForm.fecha" type="date" required class="w-full rounded-lg border border-gray-300 px-3 py-2 outline-none focus:ring-2 focus:ring-blue-500" />
              </div>
              <div>
                <label class="block text-xs text-gray-500 mb-1">Pista</label>
                <select v-model="fechaForm.tipo_pista" class="w-full rounded-lg border border-gray-300 px-3 py-2 outline-none">
                  <option value="doble">Doble (A+B)</option>
                  <option value="simple">Simple</option>
                </select>
              </div>
            </div>
            <div class="grid grid-cols-2 gap-3">
              <div>
                <label class="block text-xs text-gray-500 mb-1">Vueltas clasificacion</label>
                <select v-model.number="fechaForm.vueltas_clasificacion" class="w-full rounded-lg border border-gray-300 px-3 py-2 outline-none">
                  <option v-for="n in 5" :key="n" :value="n">{{ n }}</option>
                </select>
              </div>
              <div>
                <label class="block text-xs text-gray-500 mb-1">Final</label>
                <div class="flex items-center gap-2 mt-1">
                  <input v-model="fechaForm.tiene_final" type="checkbox" class="w-4 h-4" />
                  <span class="text-sm text-gray-700">Habilitar</span>
                </div>
              </div>
            </div>
            <div v-if="fechaForm.tiene_final">
              <label class="block text-xs text-gray-500 mb-1">Finalistas (Top X)</label>
              <input v-model.number="fechaForm.finalistas_top" type="number" min="2" max="20" class="w-24 rounded-lg border border-gray-300 px-3 py-2 outline-none" />
            </div>
          </div>
          <div v-if="campeonatos.length">
            <label class="block text-xs text-gray-500 mb-1">Campeonato (opcional)</label>
            <select v-model="fechaForm.campeonato_id" class="w-full rounded-lg border border-gray-300 px-3 py-2 outline-none">
              <option :value="null">Sin campeonato</option>
              <option v-for="c in campeonatos.filter(c => c.estado !== 'finalizado')" :key="c.id" :value="c.id">
                {{ c.nombre }} {{ c.anio }}
              </option>
            </select>
            <p v-if="campeonatos.find(c => c.id === fechaForm.campeonato_id)?.tipo_pista_forzado" class="text-xs text-amber-600 mt-1">
              Este campeonato fuerza pista {{ campeonatos.find(c => c.id === fechaForm.campeonato_id)?.tipo_pista_forzado }}.
            </p>
          </div>
          <button @click="wizardNext" :disabled="!fechaForm.nombre || !fechaForm.fecha"
            class="w-full bg-blue-600 hover:bg-blue-700 text-white font-medium py-2.5 rounded-lg disabled:opacity-50">
            Siguiente: Categorias
          </button>
        </template>

        <!-- Step 2 -->
        <template v-if="wizardStep === 2">
          <p class="text-sm text-gray-500">Selecciona categorias y define penalizaciones:</p>
          <div v-if="!catalogo.length" class="text-sm text-gray-400">No hay categorias en el catalogo. Crea una arriba.</div>
          <div v-for="c in catalogo" :key="c.id" class="bg-gray-50 rounded-lg p-3 space-y-2">
            <label class="flex items-center gap-2">
              <input type="checkbox" v-model="catSelections[c.id].selected" class="w-4 h-4" />
              <span class="font-medium text-gray-800">{{ c.nombre }}</span>
            </label>
            <div v-if="catSelections[c.id]?.selected" class="grid grid-cols-2 gap-2 pl-6">
              <div>
                <label class="text-xs text-gray-500">Estaca (seg)</label>
                <input v-model.number="catSelections[c.id].penal_estaca_seg" type="number" min="0" class="w-full rounded border border-gray-300 px-2 py-1.5 text-sm outline-none" />
              </div>
              <div>
                <label class="text-xs text-gray-500">Cinta (seg)</label>
                <input v-model.number="catSelections[c.id].penal_cinta_seg" type="number" min="0" class="w-full rounded border border-gray-300 px-2 py-1.5 text-sm outline-none" />
              </div>
            </div>
          </div>
          <button @click="wizardFinish"
            class="w-full bg-green-600 hover:bg-green-700 text-white font-medium py-2.5 rounded-lg">
            Crear fecha
          </button>
        </template>
      </div>

      <!-- Fechas list -->
      <div v-if="loading" class="text-center py-8 text-gray-400">Cargando...</div>
      <div v-else class="space-y-2">
        <button v-for="f in fechas" :key="f.id" @click="router.push(`/admin/fecha/${f.id}`)"
          class="w-full bg-white rounded-xl p-4 text-left shadow-sm hover:shadow-md transition-shadow">
          <div class="flex items-center justify-between">
            <div>
              <p class="font-medium text-gray-800">{{ f.nombre }}</p>
              <p class="text-sm text-gray-500">
                {{ formatFecha(f.fecha) }}
                &middot; {{ f.tripulaciones_count }} trips
                &middot; {{ f.categorias?.length || 0 }} cats
              </p>
            </div>
            <span :class="['text-xs px-2.5 py-1 rounded-full font-medium', estadoColor(f.estado)]">
              {{ estadoLabel(f.estado) }}
            </span>
          </div>
        </button>
      </div>
    </main>
  </div>
</template>
