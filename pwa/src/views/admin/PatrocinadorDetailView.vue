<script setup lang="ts">
import { ref, onMounted } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { apiFetch, apiMutate } from '../../api'
import { formatFecha } from '../../utils'
import { useToast } from '../../composables/useToast'

const route = useRoute()
const router = useRouter()
const patrocinador = ref<any>(null)
const contratos = ref<any[]>([])
const tab = ref<'datos' | 'contratos' | 'pagos'>('datos')
const loading = ref(true)
const { toast, showToast } = useToast()

// Forms
const editForm = ref<any>({})
const showContratoForm = ref(false)
const contratoForm = ref({ fecha_desde: '', fecha_hasta: '', monto_total: 0, moneda: 'PYG', estado: 'borrador', descripcion: '' })
const showPagoForm = ref(false)
const pagoContratoId = ref<string | null>(null)
const pagoForm = ref({ fecha_pago: '', monto: 0, moneda: 'PYG', metodo: 'transferencia', referencia: '', estado: 'recibido', notas: '' })

onMounted(load)

async function load() {
  loading.value = true
  const id = route.params.id as string
  const res = await apiFetch<any>(`/patrocinadores/${id}`)
  patrocinador.value = res.data
  editForm.value = { ...res.data }

  const cRes = await apiFetch<any[]>(`/patrocinadores/${id}/contratos`)
  contratos.value = cRes.data || []

  loading.value = false
}

async function guardarDatos() {
  try {
    await apiMutate('PUT', `/patrocinadores/${route.params.id}`, editForm.value)
    showToast('Datos guardados')
    await load()
  } catch (e: any) {
    showToast(e.message || 'Error', 'error')
  }
}

async function subirLogo(event: Event) {
  const file = (event.target as HTMLInputElement).files?.[0]
  if (!file) return

  const formData = new FormData()
  formData.append('logo', file)

  try {
    await fetch(`/api/v1/patrocinadores/${route.params.id}/logo`, {
      method: 'POST',
      credentials: 'same-origin',
      headers: {
        'X-XSRF-TOKEN': decodeURIComponent(document.cookie.match(/XSRF-TOKEN=([^;]+)/)?.[1] || ''),
      },
      body: formData,
    })
    // Get CSRF first
    await fetch('/sanctum/csrf-cookie', { credentials: 'same-origin' })
    const csrfMatch = document.cookie.match(/XSRF-TOKEN=([^;]+)/)
    const token = csrfMatch ? decodeURIComponent(csrfMatch[1]) : ''

    const res = await fetch(`/api/v1/patrocinadores/${route.params.id}/logo`, {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'X-XSRF-TOKEN': token },
      body: formData,
    })

    if (res.ok) {
      showToast('Logo subido')
      await load()
    } else {
      const err = await res.json()
      showToast(err.message || 'Error al subir logo', 'error')
    }
  } catch (e: any) {
    showToast('Error al subir logo', 'error')
  }
}

async function crearContrato() {
  try {
    await apiMutate('POST', `/patrocinadores/${route.params.id}/contratos`, contratoForm.value)
    showContratoForm.value = false
    contratoForm.value = { fecha_desde: '', fecha_hasta: '', monto_total: 0, moneda: 'PYG', estado: 'borrador', descripcion: '' }
    showToast('Contrato creado')
    await load()
  } catch (e: any) {
    showToast(e.message || 'Error', 'error')
  }
}

async function cambiarEstadoContrato(id: string, estado: string) {
  await apiMutate('PUT', `/contratos-patrocinio/${id}`, { estado })
  showToast(`Contrato ${estado}`)
  await load()
}

async function crearPago() {
  if (!pagoContratoId.value) return
  try {
    await apiMutate('POST', `/contratos-patrocinio/${pagoContratoId.value}/pagos`, pagoForm.value)
    showPagoForm.value = false
    pagoForm.value = { fecha_pago: '', monto: 0, moneda: 'PYG', metodo: 'transferencia', referencia: '', estado: 'recibido', notas: '' }
    showToast('Pago registrado')
    await load()
  } catch (e: any) {
    showToast(e.message || 'Error', 'error')
  }
}

async function eliminar() {
  if (!confirm('Eliminar este patrocinador?')) return
  try {
    await apiMutate('DELETE', `/patrocinadores/${route.params.id}`)
    router.push('/admin/patrocinadores')
  } catch (e: any) {
    showToast(e.message || 'Error', 'error')
  }
}


function formatMonto(monto: number, moneda: string): string {
  if (moneda === 'PYG') return `Gs. ${Math.round(monto).toLocaleString('es-PY')}`
  if (moneda === 'USD') return `USD ${monto.toFixed(2)}`
  return `BRL ${monto.toFixed(2)}`
}
</script>

<template>
  <div class="min-h-screen bg-gray-50">
    <header class="bg-[var(--brand-dark)] text-white px-4 py-3">
      <div class="max-w-2xl mx-auto">
        <div class="flex items-center gap-3 mb-2">
          <button @click="router.push('/admin/patrocinadores')" class="text-white/60 hover:text-white">&larr;</button>
          <h1 class="font-bold truncate text-sm">{{ patrocinador?.nombre_comercial || 'Cargando...' }}</h1>
          <span v-if="patrocinador" :class="['text-[10px] px-2 py-0.5 rounded-full ml-auto shrink-0',
            patrocinador.activo ? 'bg-green-900 text-green-300' : 'bg-red-900 text-red-300']">
            {{ patrocinador.activo ? 'Activo' : 'Inactivo' }}
          </span>
        </div>
        <div class="flex gap-1">
          <button v-for="t in (['datos', 'contratos', 'pagos'] as const)" :key="t"
            @click="tab = t"
            :class="['px-3 py-1.5 rounded-t-lg text-xs font-medium transition-colors',
              tab === t ? 'bg-gray-50 text-gray-800' : 'text-white/60 hover:text-white']">
            {{ { datos: 'Datos', contratos: 'Contratos', pagos: 'Pagos' }[t] }}
          </button>
        </div>
      </div>
    </header>

    <main class="max-w-2xl mx-auto px-4 py-4 space-y-4">
      <!-- DATOS -->
      <div v-if="tab === 'datos' && patrocinador" class="space-y-4">
        <!-- Logo -->
        <div class="bg-white rounded-xl p-4 shadow-sm text-center space-y-3">
          <div class="w-32 h-32 mx-auto rounded-xl bg-gray-100 flex items-center justify-center overflow-hidden">
            <img v-if="patrocinador.logo_url" :src="patrocinador.logo_url" class="w-full h-full object-contain" />
            <span v-else class="text-gray-300">Sin logo</span>
          </div>
          <label class="inline-block bg-blue-600 hover:bg-blue-700 text-white text-sm px-4 py-2 rounded-lg cursor-pointer">
            Subir logo
            <input type="file" accept="image/*" class="hidden" @change="subirLogo" />
          </label>
        </div>

        <!-- Formulario -->
        <div class="bg-white rounded-xl p-4 shadow-sm space-y-3">
          <div>
            <label class="block text-xs text-gray-500 mb-1">Nombre comercial</label>
            <input v-model="editForm.nombre_comercial" class="w-full rounded-lg border border-gray-300 px-3 py-2 outline-none focus:ring-2 focus:ring-blue-500" />
          </div>
          <div class="grid grid-cols-2 gap-3">
            <div>
              <label class="block text-xs text-gray-500 mb-1">Nivel</label>
              <select v-model="editForm.nivel" class="w-full rounded-lg border border-gray-300 px-3 py-2 outline-none">
                <option value="principal">Principal</option>
                <option value="secundario">Secundario</option>
              </select>
            </div>
            <div>
              <label class="block text-xs text-gray-500 mb-1">Orden</label>
              <input v-model.number="editForm.orden" type="number" min="0" class="w-full rounded-lg border border-gray-300 px-3 py-2 outline-none" />
            </div>
          </div>
          <div>
            <label class="block text-xs text-gray-500 mb-1">Descripcion corta (max 200)</label>
            <textarea v-model="editForm.descripcion_corta" maxlength="200" rows="2" class="w-full rounded-lg border border-gray-300 px-3 py-2 outline-none focus:ring-2 focus:ring-blue-500"></textarea>
            <p class="text-[10px] text-gray-400 text-right">{{ (editForm.descripcion_corta || '').length }}/200</p>
          </div>
          <input v-model="editForm.website" placeholder="Website (https://...)" class="w-full rounded-lg border border-gray-300 px-3 py-2 outline-none" />
          <div class="grid grid-cols-2 gap-3">
            <input v-model="editForm.razon_social" placeholder="Razon social" class="rounded-lg border border-gray-300 px-3 py-2 outline-none" />
            <input v-model="editForm.ruc" placeholder="RUC" class="rounded-lg border border-gray-300 px-3 py-2 outline-none" />
          </div>
          <input v-model="editForm.contacto_nombre" placeholder="Contacto nombre" class="w-full rounded-lg border border-gray-300 px-3 py-2 outline-none" />
          <div class="grid grid-cols-2 gap-3">
            <input v-model="editForm.contacto_email" placeholder="Email" class="rounded-lg border border-gray-300 px-3 py-2 outline-none" />
            <input v-model="editForm.contacto_telefono" placeholder="Telefono" class="rounded-lg border border-gray-300 px-3 py-2 outline-none" />
          </div>
          <div class="flex items-center gap-2">
            <input v-model="editForm.activo" type="checkbox" class="w-4 h-4" />
            <span class="text-sm text-gray-700">Activo (visible publicamente si tiene contrato vigente)</span>
          </div>
          <button @click="guardarDatos" class="w-full bg-green-600 hover:bg-green-700 text-white font-medium py-2.5 rounded-lg">Guardar</button>
          <button @click="eliminar" class="w-full bg-red-100 hover:bg-red-200 text-red-700 font-medium py-2 rounded-lg text-sm">Eliminar patrocinador</button>
        </div>
      </div>

      <!-- CONTRATOS -->
      <div v-if="tab === 'contratos'" class="space-y-3">
        <button v-if="!showContratoForm" @click="showContratoForm = true"
          class="w-full bg-blue-600 hover:bg-blue-700 text-white font-medium py-2.5 rounded-lg text-sm">
          + Nuevo contrato
        </button>

        <div v-if="showContratoForm" class="bg-white rounded-xl p-4 shadow-sm space-y-3">
          <div class="grid grid-cols-2 gap-3">
            <div>
              <label class="block text-xs text-gray-500 mb-1">Desde</label>
              <input v-model="contratoForm.fecha_desde" type="date" class="w-full rounded-lg border border-gray-300 px-3 py-2 outline-none" />
            </div>
            <div>
              <label class="block text-xs text-gray-500 mb-1">Hasta</label>
              <input v-model="contratoForm.fecha_hasta" type="date" class="w-full rounded-lg border border-gray-300 px-3 py-2 outline-none" />
            </div>
          </div>
          <div class="grid grid-cols-2 gap-3">
            <div>
              <label class="block text-xs text-gray-500 mb-1">Monto total</label>
              <input v-model.number="contratoForm.monto_total" type="number" min="0" class="w-full rounded-lg border border-gray-300 px-3 py-2 outline-none" />
            </div>
            <div>
              <label class="block text-xs text-gray-500 mb-1">Moneda</label>
              <select v-model="contratoForm.moneda" class="w-full rounded-lg border border-gray-300 px-3 py-2 outline-none">
                <option value="PYG">Guaranies</option>
                <option value="USD">Dolares</option>
                <option value="BRL">Reales</option>
              </select>
            </div>
          </div>
          <select v-model="contratoForm.estado" class="w-full rounded-lg border border-gray-300 px-3 py-2 outline-none">
            <option value="borrador">Borrador</option>
            <option value="vigente">Vigente</option>
          </select>
          <textarea v-model="contratoForm.descripcion" placeholder="Descripcion / condiciones" rows="2" class="w-full rounded-lg border border-gray-300 px-3 py-2 outline-none"></textarea>
          <div class="flex gap-2">
            <button @click="crearContrato" class="bg-green-600 text-white px-4 py-2 rounded-lg font-medium">Crear</button>
            <button @click="showContratoForm = false" class="text-gray-500 px-4 py-2">Cancelar</button>
          </div>
        </div>

        <div v-for="c in contratos" :key="c.id" class="bg-white rounded-xl p-4 shadow-sm space-y-2">
          <div class="flex items-center justify-between">
            <div>
              <span :class="['text-xs px-2 py-0.5 rounded-full font-medium',
                c.estado === 'vigente' ? 'bg-green-100 text-green-700' : c.estado === 'borrador' ? 'bg-gray-100 text-gray-600' : c.estado === 'cancelado' ? 'bg-red-100 text-red-600' : 'bg-purple-100 text-purple-700']">
                {{ c.estado }}
              </span>
              <span class="text-sm text-gray-500 ml-2">{{ formatFecha(c.fecha_desde) }} — {{ formatFecha(c.fecha_hasta) }}</span>
            </div>
            <p class="font-bold text-gray-800">{{ formatMonto(c.monto_total, c.moneda) }}</p>
          </div>

          <!-- Progress bar -->
          <div class="bg-gray-200 rounded-full h-2">
            <div class="bg-green-500 h-2 rounded-full" :style="{ width: Math.min(100, (c.total_cobrado / c.monto_total) * 100) + '%' }"></div>
          </div>
          <p class="text-xs text-gray-500">Cobrado: {{ formatMonto(c.total_cobrado, c.moneda) }} | Pendiente: {{ formatMonto(c.saldo_pendiente, c.moneda) }}</p>

          <!-- Actions -->
          <div class="flex gap-2 pt-1">
            <button v-if="c.estado === 'borrador'" @click="cambiarEstadoContrato(c.id, 'vigente')"
              class="text-xs bg-green-100 text-green-700 px-2 py-1 rounded">Activar</button>
            <button v-if="c.estado === 'vigente'" @click="cambiarEstadoContrato(c.id, 'finalizado')"
              class="text-xs bg-gray-100 text-gray-600 px-2 py-1 rounded">Finalizar</button>
            <button @click="pagoContratoId = c.id; showPagoForm = true; pagoForm.moneda = c.moneda"
              class="text-xs bg-blue-100 text-blue-700 px-2 py-1 rounded">+ Pago</button>
          </div>

          <!-- Pagos del contrato -->
          <div v-if="c.pagos?.length" class="border-t border-gray-100 pt-2 mt-2 space-y-1">
            <div v-for="p in c.pagos" :key="p.id" class="flex items-center justify-between text-xs">
              <div class="flex items-center gap-2">
                <span :class="['w-1.5 h-1.5 rounded-full', p.estado === 'recibido' ? 'bg-green-500' : p.estado === 'anulado' ? 'bg-red-400' : 'bg-yellow-400']"></span>
                <span class="text-gray-500">{{ formatFecha(p.fecha_pago) }}</span>
                <span class="text-gray-400">{{ p.metodo }}</span>
              </div>
              <span class="font-medium text-gray-700">{{ formatMonto(p.monto, p.moneda) }}</span>
            </div>
          </div>
        </div>

        <!-- Modal pago -->
        <div v-if="showPagoForm" class="bg-white rounded-xl p-4 shadow-sm space-y-3 ring-2 ring-blue-400">
          <p class="text-xs text-blue-600 font-medium uppercase">Registrar pago</p>
          <div class="grid grid-cols-2 gap-3">
            <div>
              <label class="block text-xs text-gray-500 mb-1">Fecha</label>
              <input v-model="pagoForm.fecha_pago" type="date" class="w-full rounded-lg border border-gray-300 px-3 py-2 outline-none" />
            </div>
            <div>
              <label class="block text-xs text-gray-500 mb-1">Monto</label>
              <input v-model.number="pagoForm.monto" type="number" min="0" class="w-full rounded-lg border border-gray-300 px-3 py-2 outline-none" />
            </div>
          </div>
          <select v-model="pagoForm.metodo" class="w-full rounded-lg border border-gray-300 px-3 py-2 outline-none text-sm">
            <option value="transferencia">Transferencia</option>
            <option value="efectivo">Efectivo</option>
            <option value="cheque">Cheque</option>
            <option value="tarjeta">Tarjeta</option>
            <option value="otro">Otro</option>
          </select>
          <input v-model="pagoForm.referencia" placeholder="Referencia (nro transferencia, banco...)" class="w-full rounded-lg border border-gray-300 px-3 py-2 outline-none text-sm" />
          <div class="flex gap-2">
            <button @click="crearPago" class="bg-green-600 text-white px-4 py-2 rounded-lg font-medium text-sm">Registrar</button>
            <button @click="showPagoForm = false" class="text-gray-500 text-sm">Cancelar</button>
          </div>
        </div>
      </div>

      <!-- PAGOS (todos) -->
      <div v-if="tab === 'pagos'" class="space-y-2">
        <div v-for="c in contratos" :key="c.id">
          <div v-for="p in (c.pagos || [])" :key="p.id" class="bg-white rounded-lg p-3 shadow-sm flex items-center justify-between">
            <div>
              <p class="text-sm text-gray-800">{{ formatFecha(p.fecha_pago) }} — {{ p.metodo }}</p>
              <p class="text-xs text-gray-400">{{ p.referencia || 'Sin referencia' }}</p>
            </div>
            <div class="text-right">
              <p class="font-bold text-sm">{{ formatMonto(p.monto, p.moneda) }}</p>
              <span :class="['text-[10px] px-1.5 py-0.5 rounded-full',
                p.estado === 'recibido' ? 'bg-green-100 text-green-700' : p.estado === 'anulado' ? 'bg-red-100 text-red-600' : 'bg-yellow-100 text-yellow-700']">
                {{ p.estado }}
              </span>
            </div>
          </div>
        </div>
        <div v-if="contratos.every(c => !c.pagos?.length)" class="text-center py-8 text-gray-400">No hay pagos registrados</div>
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
