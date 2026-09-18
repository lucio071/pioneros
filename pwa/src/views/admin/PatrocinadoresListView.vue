<script setup lang="ts">
import { ref, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import { apiFetch, apiMutate } from '../../api'

const router = useRouter()
const patrocinadores = ref<any[]>([])
const loading = ref(true)
const showForm = ref(false)
const form = ref({ nombre_comercial: '', nivel: 'secundario' })

onMounted(async () => {
  await loadData()
  loading.value = false
})

async function loadData() {
  const res = await apiFetch<any[]>('/patrocinadores')
  patrocinadores.value = res.data || []
}

async function crear() {
  const res = await apiMutate<any>('POST', '/patrocinadores', form.value)
  showForm.value = false
  form.value = { nombre_comercial: '', nivel: 'secundario' }
  await loadData()
  router.push(`/admin/patrocinadores/${res.id}`)
}
</script>

<template>
  <div class="min-h-screen bg-gray-50">
    <header class="bg-[var(--brand-dark)] text-white px-4 py-4">
      <div class="max-w-2xl mx-auto flex items-center gap-3">
        <button @click="router.push('/admin')" class="text-white/60 hover:text-white">&larr;</button>
        <h1 class="font-bold">Patrocinadores</h1>
      </div>
    </header>

    <main class="max-w-2xl mx-auto px-4 py-4 space-y-4">
      <!-- Stats -->
      <div class="grid grid-cols-3 gap-2">
        <div class="bg-white rounded-lg p-3 text-center shadow-sm">
          <p class="text-lg font-bold text-gray-800">{{ patrocinadores.filter(p => p.activo).length }}</p>
          <p class="text-[10px] text-gray-400 uppercase">Activos</p>
        </div>
        <div class="bg-white rounded-lg p-3 text-center shadow-sm">
          <p class="text-lg font-bold text-[var(--brand-accent)]">{{ patrocinadores.filter(p => p.nivel === 'principal').length }}</p>
          <p class="text-[10px] text-gray-400 uppercase">Principales</p>
        </div>
        <div class="bg-white rounded-lg p-3 text-center shadow-sm">
          <p class="text-lg font-bold text-blue-600">{{ patrocinadores.filter(p => p.tiene_contrato_vigente).length }}</p>
          <p class="text-[10px] text-gray-400 uppercase">Con contrato</p>
        </div>
      </div>

      <button v-if="!showForm" @click="showForm = true"
        class="w-full bg-[var(--brand-color)] hover:bg-[var(--brand-light)] text-white font-medium py-3 rounded-lg">
        + Nuevo patrocinador
      </button>

      <div v-if="showForm" class="bg-white rounded-xl p-4 shadow-sm space-y-3">
        <input v-model="form.nombre_comercial" placeholder="Nombre comercial" required
          class="w-full rounded-lg border border-gray-300 px-3 py-2 outline-none focus:ring-2 focus:ring-blue-500" />
        <select v-model="form.nivel" class="w-full rounded-lg border border-gray-300 px-3 py-2 outline-none">
          <option value="principal">Principal</option>
          <option value="secundario">Secundario</option>
        </select>
        <div class="flex gap-2">
          <button @click="crear" class="bg-green-600 text-white px-4 py-2 rounded-lg font-medium">Crear</button>
          <button @click="showForm = false" class="text-gray-500 px-4 py-2">Cancelar</button>
        </div>
      </div>

      <div v-if="loading" class="text-center py-8 text-gray-400">Cargando...</div>

      <div v-else class="space-y-2">
        <button v-for="p in patrocinadores" :key="p.id" @click="router.push(`/admin/patrocinadores/${p.id}`)"
          class="w-full bg-white rounded-xl p-4 text-left shadow-sm hover:shadow-md transition-shadow flex items-center gap-3">
          <div class="w-12 h-12 rounded-lg bg-gray-100 flex items-center justify-center overflow-hidden shrink-0">
            <img v-if="p.logo_url" :src="p.logo_url" class="w-full h-full object-contain" />
            <span v-else class="text-gray-300 text-xs">Sin logo</span>
          </div>
          <div class="flex-1 min-w-0">
            <p class="font-medium text-gray-800 truncate">{{ p.nombre_comercial }}</p>
            <div class="flex gap-1.5 mt-1">
              <span :class="['text-[10px] px-1.5 py-0.5 rounded-full',
                p.nivel === 'principal' ? 'bg-yellow-100 text-yellow-700' : 'bg-gray-100 text-gray-500']">
                {{ p.nivel }}
              </span>
              <span :class="['text-[10px] px-1.5 py-0.5 rounded-full',
                p.activo ? 'bg-green-100 text-green-700' : 'bg-red-100 text-red-600']">
                {{ p.activo ? 'Activo' : 'Inactivo' }}
              </span>
              <span v-if="p.tiene_contrato_vigente" class="text-[10px] px-1.5 py-0.5 rounded-full bg-blue-100 text-blue-700">
                Contrato vigente
              </span>
            </div>
          </div>
        </button>
      </div>
    </main>
  </div>
</template>
