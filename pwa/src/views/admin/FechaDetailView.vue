<script setup lang="ts">
import { ref, onMounted, computed } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { apiFetch, apiMutate } from '../../api'
import { useOfflineStore } from '../../stores/offline'
import { formatTiempo, formatDiferencia, estadoLabel, estadoColor, faseLabel, faseColor } from '../../utils'
import { useToast } from '../../composables/useToast'
import { useAuthStore } from '../../stores/auth'

const route = useRoute()
const router = useRouter()
const offline = useOfflineStore()

const fecha = ref<any>(null)
const fechaCategorias = ref<any[]>([])
const selectedCatId = ref<string | null>(null)
const tripulaciones = ref<any[]>([])
const rankingData = ref<any>(null)
const tab = ref<'ranking' | 'cronometraje' | 'inscripcion' | 'config'>('config')
const loading = ref(true)
const { toast, showToast } = useToast()
const auth = useAuthStore()

// Config - catalogo para agregar categorias
const catalogo = ref<any[]>([])
const addCatForm = ref({ categoria_catalogo_id: '', penal_estaca_seg: 5, penal_cinta_seg: 10, tipo_pista: null as string | null })
const showAddCat = ref(false)

// Inscripcion
const tripForm = ref({ numero: '', nombre: '', piloto: '', copiloto: '' })
const editingTripId = ref<string | null>(null)

// Cronometraje
const showPares = ref(false)
const pares = computed(() => {
  const sorted = [...tripulaciones.value].sort((a: any, b: any) => (a.orden_largada || 99) - (b.orden_largada || 99))
  const result: any[][] = []
  for (let i = 0; i < sorted.length; i += 2) {
    result.push(sorted.slice(i, i + 2))
  }
  return result
})
const ordenList = ref<any[]>([])
const ordenBloqueado = ref(false)
const pistaATrip = ref<any>(null)
const pistaBTrip = ref<any>(null)
const selectedTripB = ref<any>(null)

function asignarPista(t: any) {
  if (esDobleCategoria.value && !showPares.value) {
    // Modo doble: asignar a pista A o B
    if (pistaATrip.value?.id === t.id) { pistaATrip.value = null; return }
    if (pistaBTrip.value?.id === t.id) { pistaBTrip.value = null; return }
    if (!pistaATrip.value) { pistaATrip.value = t; return }
    if (!pistaBTrip.value) { pistaBTrip.value = t; return }
    // Ambas ocupadas: reemplazar B
    pistaBTrip.value = t
  } else {
    // Modo simple: seleccionar directo
    selectTrip(t)
  }
}

const corridaActual = ref<1 | 2>(1)
const corrida1Guardada = ref(false)
const largadaEnCurso = ref(false)
const sensorArmadoHasta = ref(0)  // timestamp ms cuando expira
const sensorCountdown = ref(0)    // segundos restantes

// Quien corre en cada pista segun la corrida
const tripEnPistaA = computed(() => {
  if (!selectedTrip.value || !selectedTripB.value) return selectedTrip.value
  return corridaActual.value === 1 ? selectedTrip.value : selectedTripB.value
})
const tripEnPistaB = computed(() => {
  if (!selectedTrip.value || !selectedTripB.value) return null
  return corridaActual.value === 1 ? selectedTripB.value : selectedTrip.value
})

function iniciarParManual() {
  if (!pistaATrip.value) return
  const tripA = pistaATrip.value
  const tripB = pistaBTrip.value

  selectedTripB.value = tripB
  corridaActual.value = 1
  corrida1Guardada.value = false
  startCronoPolling()

  selectedTrip.value = tripA
  selectedVuelta.value = calcularVueltaPendiente(tripA, tripB)
  resetTramoForms()

  // Mandar numeros a cronometros fisicos
  apiMutate('POST', '/cronometro/crono-a/comando', { tipo: 'set_tripulacion', payload: { numero: Number(tripA.numero), vuelta: selectedVuelta.value } }).catch(() => {})
  if (tripB) {
    apiMutate('POST', '/cronometro/crono-b/comando', { tipo: 'set_tripulacion', payload: { numero: Number(tripB.numero), vuelta: selectedVuelta.value } }).catch(() => {})
  }

  pistaATrip.value = null
  pistaBTrip.value = null

  // Persistir par activo
  guardarParActivo()
}

function guardarParActivo() {
  if (selectedTrip.value) {
    const data: any = {
      tripAId: selectedTrip.value.id,
      tripBId: selectedTripB.value?.id || null,
      vuelta: selectedVuelta.value,
      corrida: corridaActual.value,
      corrida1Guardada: corrida1Guardada.value,
      catId: selectedCatId.value,
    }
    localStorage.setItem('pioneros_par_activo', JSON.stringify(data))
  }
}

function limpiarParActivo() {
  localStorage.removeItem('pioneros_par_activo')
}

function restaurarParActivo() {
  const saved = localStorage.getItem('pioneros_par_activo')
  if (!saved) return
  try {
    const data = JSON.parse(saved)
    if (data.catId !== selectedCatId.value) { limpiarParActivo(); return }
    const tripA = tripulaciones.value.find((t: any) => t.id === data.tripAId)
    if (!tripA) { limpiarParActivo(); return }
    selectedTrip.value = tripA
    if (data.tripBId) {
      selectedTripB.value = tripulaciones.value.find((t: any) => t.id === data.tripBId) || null
    }
    // Calcular vuelta pendiente basada en datos reales, no localStorage
    const tripBRestored = data.tripBId ? tripulaciones.value.find((t: any) => t.id === data.tripBId) : null
    selectedVuelta.value = calcularVueltaPendiente(selectedTrip.value, tripBRestored)
    corridaActual.value = 1
    // Verificar si corrida 1 de la vuelta actual tiene datos
    const vActual = selectedTrip.value?.vueltas?.find((v: any) => v.numero_vuelta === selectedVuelta.value && v.fase === 'clasificacion')
    const tieneTramoA = vActual?.tramos?.some((tr: any) => tr.letra === 'A' && tr.tiempo_ms > 0)
    corrida1Guardada.value = !!tieneTramoA
    if (corrida1Guardada.value && data.corrida === 2) {
      corridaActual.value = 2
    }
    startCronoPolling()
    resetTramoForms()
  } catch { limpiarParActivo() }
}

function initOrdenList() {
  ordenList.value = [...tripulaciones.value].sort((a: any, b: any) => (a.orden_largada || 99) - (b.orden_largada || 99))
}

function moverArriba(i: number) {
  if (i === 0) return
  const list = ordenList.value
  const tmp = list[i]
  list[i] = list[i - 1]
  list[i - 1] = tmp
  ordenList.value = [...list]
}

function moverAbajo(i: number) {
  if (i >= ordenList.value.length - 1) return
  const list = ordenList.value
  const tmp = list[i]
  list[i] = list[i + 1]
  list[i + 1] = tmp
  ordenList.value = [...list]
}

function sortearOrden() {
  const list = [...ordenList.value]
  for (let i = list.length - 1; i > 0; i--) {
    const j = Math.floor(Math.random() * (i + 1))
    const tmp = list[i]
    list[i] = list[j]
    list[j] = tmp
  }
  ordenList.value = [...list]
}

async function guardarOrden() {
  for (let i = 0; i < ordenList.value.length; i++) {
    const t = ordenList.value[i]
    if (t.orden_largada !== i + 1) {
      await apiMutate("PUT", "/tripulaciones/" + t.id, { orden_largada: i + 1 })
    }
  }
  showToast("Orden guardado y bloqueado")
  ordenBloqueado.value = true
  await load()
  initOrdenList()
}

const esDobleCategoria = computed(() => {
  const fc = selectedCat.value
  if (fc?.tipo_pista) return fc.tipo_pista === 'doble'
  return fecha.value?.tipo_pista === 'doble'
})

async function cambiarACorrida2() {
  if (!selectedTrip.value || !selectedTripB.value) return
  corridaActual.value = 2
  resetTramoForms()
  await enviarComandoCrono('reset')
  // Invertir cronometros fisicos: tripB en crono-a, tripA en crono-b
  await apiMutate('POST', '/cronometro/crono-a/comando', { tipo: 'set_tripulacion', payload: { numero: Number(selectedTripB.value.numero), vuelta: selectedVuelta.value } }).catch(() => {})
  await apiMutate('POST', '/cronometro/crono-b/comando', { tipo: 'set_tripulacion', payload: { numero: Number(selectedTrip.value.numero), vuelta: selectedVuelta.value } }).catch(() => {})
  showToast('Corrida 2: se cambiaron de pista')
  guardarParActivo()
}

async function guardarCorrida() {
  if (!selectedTrip.value) return
  const tripA = selectedTrip.value   // trip original pista A
  const tripB = selectedTripB.value  // trip original pista B

  const msA = tramoToMs(tramoA.value)
  const msB = tramoToMs(tramoB.value)
  const bodyPistaA: any = {
    estacas: tramoA.value.estacas,
    cintas: tramoA.value.cintas,
    tiempos_muertos: tramoA.value.tiempos_muertos,
  }
  if (msA > 0) bodyPistaA.tiempo_ms = msA

  const bodyPistaB: any = tripB ? {
    estacas: tramoB.value.estacas,
    cintas: tramoB.value.cintas,
    tiempos_muertos: tramoB.value.tiempos_muertos,
  } : null
  if (bodyPistaB && msB > 0) bodyPistaB.tiempo_ms = msB

  try {
    if (corridaActual.value === 1) {
      // Corrida 1: tripA corre pistaA (su tramo A), tripB corre pistaB (su tramo B)
      await apiMutate('PUT', `/tripulaciones/${tripA.id}/vueltas/${selectedVuelta.value}/tramos/A`, bodyPistaA)
      if (tripB && bodyPistaB) {
        await apiMutate('PUT', `/tripulaciones/${tripB.id}/vueltas/${selectedVuelta.value}/tramos/B`, bodyPistaB)
      }
    } else {
      // Corrida 2: tripB corre pistaA (su tramo A), tripA corre pistaB (su tramo B)
      if (tripB && bodyPistaA) {
        await apiMutate('PUT', `/tripulaciones/${tripB.id}/vueltas/${selectedVuelta.value}/tramos/A`, bodyPistaA)
      }
      await apiMutate('PUT', `/tripulaciones/${tripA.id}/vueltas/${selectedVuelta.value}/tramos/B`, bodyPistaB || bodyPistaA)
    }

    if (navigator.vibrate) navigator.vibrate([50, 50, 50])
    largadaEnCurso.value = false
    showToast(`Corrida ${corridaActual.value} guardada`)
    resetTramoForms()
    await load()
    // Refrescar datos de las trips
    selectedTrip.value = tripulaciones.value.find((t: any) => t.id === tripA.id) || null
    if (tripB) selectedTripB.value = tripulaciones.value.find((t: any) => t.id === tripB.id) || null

    // Si fue corrida 1, auto-pasar a corrida 2
    if (corridaActual.value === 1 && tripB) {
      corrida1Guardada.value = true
      cambiarACorrida2()
    } else {
      guardarParActivo()
    }
  } catch (e: any) {
    if (!navigator.onLine) {
      showToast('Sin conexion - no se pudo guardar', 'error')
    } else {
      showToast(e.message || 'Error al guardar corrida', 'error')
    }
  }
}

async function salioAPistaDoble() {
  if (!selectedTrip.value) return
  await apiMutate('POST', `/tripulaciones/${selectedTrip.value.id}/salio-a-pista`)
  if (selectedTripB.value) {
    await apiMutate('POST', `/tripulaciones/${selectedTripB.value.id}/salio-a-pista`)
  }
  showToast('Ambos marcados en pista')
  await load()
  selectedTrip.value = tripulaciones.value.find((t: any) => t.id === selectedTrip.value?.id) || null
  if (selectedTripB.value) {
    selectedTripB.value = tripulaciones.value.find((t: any) => t.id === selectedTripB.value?.id) || null
  }
}

const selectedTrip = ref<any>(null)
const selectedVuelta = ref(1)
const tramoA = ref({ tiempo_min: '', tiempo_sec: '', tiempo_ms: '', estacas: 0, cintas: 0, tiempos_muertos: [] as number[] })
const tramoB = ref({ tiempo_min: '', tiempo_sec: '', tiempo_ms: '', estacas: 0, cintas: 0, tiempos_muertos: [] as number[] })
const tmInputA = ref({ min: '', seg: '' })
const tmInputB = ref({ min: '', seg: '' })

function agregarTM(letra: 'A' | 'B') {
  const input = letra === 'A' ? tmInputA.value : tmInputB.value
  const segs = (parseInt(input.min) || 0) * 60 + (parseInt(input.seg) || 0)
  if (segs <= 0) return
  const form = letra === 'A' ? tramoA.value : tramoB.value
  form.tiempos_muertos.push(segs)
  input.min = ''
  input.seg = ''
}

function quitarTM(letra: 'A' | 'B', index: number) {
  const form = letra === 'A' ? tramoA.value : tramoB.value
  form.tiempos_muertos.splice(index, 1)
}
const search = ref('')

// Panel fisico cronometros
const dispositivos = ref<any[]>([])
let cronoTimer: ReturnType<typeof setInterval> | null = null

async function fetchCronoEstado() {
  try {
    const res = await apiFetch<any[]>("/dispositivos-cronometro")
    dispositivos.value = (res.data || []).map((d: any) => {
      const ago = d.ultimo_visto_at ? Math.floor((Date.now() - new Date(d.ultimo_visto_at).getTime()) / 1000) : null
      return { ...d, online: ago !== null && ago < 60, segundos_ago: ago }
    })
  } catch {}

  // Recargar datos de tripulaciones para ver tiempos del sensor
  if (selectedTrip.value || selectedTripB.value) {
    try {
      await loadRanking()
      const oldTripA = selectedTrip.value
      const oldTripB = selectedTripB.value
      if (selectedTrip.value) {
        selectedTrip.value = tripulaciones.value.find((t: any) => t.id === selectedTrip.value?.id) || selectedTrip.value
      }
      if (selectedTripB.value) {
        selectedTripB.value = tripulaciones.value.find((t: any) => t.id === selectedTripB.value?.id) || selectedTripB.value
      }
      // Actualizar formularios vacíos con datos del sensor
      const formAVacio = !tramoA.value.tiempo_min && !tramoA.value.tiempo_sec
      const formBVacio = !tramoB.value.tiempo_min && !tramoB.value.tiempo_sec
      if (formAVacio || formBVacio) {
        if (selectedTripB.value) {
          const tripEnA = corridaActual.value === 1 ? selectedTrip.value : selectedTripB.value
          const tripEnB = corridaActual.value === 1 ? selectedTripB.value : selectedTrip.value
          if (formAVacio) {
            const trA = buscarTramo(tripEnA, 'A')
            if (trA) cargarTramoEnForm(tramoA, trA)
          }
          if (formBVacio) {
            const trB = buscarTramo(tripEnB, 'B')
            if (trB) cargarTramoEnForm(tramoB, trB)
          }
        } else if (formAVacio) {
          const trA = buscarTramo(selectedTrip.value, 'A')
          if (trA) cargarTramoEnForm(tramoA, trA)
        }
      }
    } catch {}
  }
}
function startCronoPolling() {
  fetchCronoEstado()
  if (!cronoTimer) cronoTimer = setInterval(fetchCronoEstado, 3000)
}

function stopCronoPolling() {
  if (cronoTimer) { clearInterval(cronoTimer); cronoTimer = null }
}

async function enviarComandoCrono(tipo: string) {
  const fc = selectedCat.value
  const esDoble = fc?.tipo_pista ? fc.tipo_pista === 'doble' : fecha.value?.tipo_pista === 'doble'

  try {
    await apiMutate('POST', '/cronometro/crono-a/comando', { tipo })
    if (esDoble) {
      await apiMutate('POST', '/cronometro/crono-b/comando', { tipo })
    }
    showToast(tipo === 'start' ? 'Largada armada' : tipo === 'stop' ? 'Llegada armada' : 'Reset enviado')
  } catch (e: any) {
    showToast(e.message || 'Cronometro offline', 'error')
  }
}

async function autoReincorporar(trip: any): Promise<boolean> {
  if (!trip || trip.estado !== 'abandonado') return true
  if (!confirm(`#${trip.numero} está en DNF. ¿Reincorporar para que pueda largar?`)) return false
  await apiMutate('POST', `/tripulaciones/${trip.id}/reincorporar`)
  showToast(`#${trip.numero} reincorporado`)
  return true
}

async function armarLargada() {
  try {
    // Auto-reincorporar si alguna trip está en DNF
    const tripsAChequear = selectedTripB.value
      ? [tripEnPistaA.value, tripEnPistaB.value]
      : [selectedTrip.value]
    for (const t of tripsAChequear) {
      if (!(await autoReincorporar(t))) return
    }

    if (selectedTripB.value) {
      // Pista doble: usa endpoint que arma ambas pistas + semaforo + sensores
      const tripA = tripEnPistaA.value
      const tripB = tripEnPistaB.value
      await apiMutate('POST', '/cronometro/armar-largada-doble', {
        tripulacion_a_id: tripA.id,
        tripulacion_b_id: tripB?.id || null,
        vuelta_numero: selectedVuelta.value,
        tramo_letra_a: 'A',
        tramo_letra_b: 'B',
      })
    } else {
      // Pista simple
      await apiMutate('POST', '/cronometro/armar-largada', {
        tripulacion_id: selectedTrip.value.id,
        vuelta_numero: selectedVuelta.value,
        crono_codigo: 'crono-a',
        sensor_largada_codigo: 'sensor-a',
        sensor_llegada_codigo: 'sensor-a',
        tramo_letra: 'A',
      })
    }
    largadaEnCurso.value = true
    sensorArmadoHasta.value = Date.now() + 35000
    if (navigator.vibrate) navigator.vibrate([100, 50, 100])
    showToast('Largada armada: sensores + semaforo')
  } catch (e: any) {
    showToast(e.message || 'Error al armar largada', 'error')
  }
}

async function armarLlegada() {
  try {
    if (selectedTripB.value) {
      await apiMutate('POST', '/cronometro/armar-llegada-doble')
    } else {
      await apiMutate('POST', '/cronometro/armar-llegada')
    }
    if (navigator.vibrate) navigator.vibrate(50)
    sensorArmadoHasta.value = Date.now() + 35000
    showToast('Llegada armada: sensores habilitados')
  } catch (e: any) {
    showToast(e.message || 'Error al armar llegada', 'error')
  }
}

async function enviarNumeroTripulacion(numero: string | number) {
  const fc = selectedCat.value
  const esDoble = fc?.tipo_pista ? fc.tipo_pista === 'doble' : fecha.value?.tipo_pista === 'doble'
  try {
    await apiMutate('POST', '/cronometro/crono-a/comando', { tipo: 'set_tripulacion', payload: { numero: Number(numero), vuelta: selectedVuelta.value } })
    if (esDoble) {
      await apiMutate('POST', '/cronometro/crono-b/comando', { tipo: 'set_tripulacion', payload: { numero: Number(numero), vuelta: selectedVuelta.value } })
    }
  } catch {}
}

onMounted(load)

function imprimirTiempo(trip: any, vuelta: any) {
  const cat = selectedCat.value
  const pe = cat?.penal_estaca_seg || 5
  const pc = cat?.penal_cinta_seg || 10
  const catNom = cat?.categoria_catalogo?.nombre || cat?.nombre || ''
  const vn = vuelta.numero_vuelta === 99 ? 'FINAL' : 'V' + vuelta.numero_vuelta
  const tramos = vuelta.tramos || []
  let lines = '   PIONEROS 4x4\n'
  lines += '------------------------\n'
  lines += '#' + trip.numero + ' ' + trip.nombre + '\n'
  lines += trip.piloto + (trip.copiloto ? ' / ' + trip.copiloto : '') + '\n'
  lines += '------------------------\n'
  lines += vn + ' - ' + catNom + '\n'
  for (const tr of tramos) {
    lines += 'Pista ' + tr.letra + ':  ' + formatTiempo(tr.tiempo_ms) + '\n'
    if (tr.estacas || tr.cintas) {
      const pms = (tr.estacas * pe + tr.cintas * pc) * 1000
      lines += '  E:' + tr.estacas + ' C:' + tr.cintas + ' +' + formatTiempo(pms) + '\n'
    }
  }
  lines += '------------------------\n'
  lines += 'TOTAL:  ' + formatTiempo(vuelta.total_vuelta) + '\n'
  lines += '------------------------\n'
  lines += new Date().toLocaleString() + '\n'
  const doc = '<html><head><style>@media print{@page{margin:0;size:58mm auto}}pre{font-size:12px;margin:0;padding:4px}</style></head><body><pre>' + lines + '</pre></body></html>'
  const w = window.open('', '_blank')
  if (w) {
    w.document.write(doc)
    w.document.close()
    w.focus()
    setTimeout(() => { w.print() }, 500)
  } else {
    // Fallback: imprimir la pagina actual
    const printDiv = document.createElement('div')
    printDiv.innerHTML = '<pre style="font-size:12px">' + lines + '</pre>'
    printDiv.id = 'print-div'
    document.body.appendChild(printDiv)
    const style = document.createElement('style')
    style.textContent = '@media print { body > *:not(#print-div) { display:none !important } #print-div { display:block !important } @page { margin:0; size:58mm auto } }'
    document.head.appendChild(style)
    window.print()
    document.body.removeChild(printDiv)
    document.head.removeChild(style)
  }
}

async function load() {
  loading.value = true
  const id = route.params.id as string
  const res = await apiFetch<any[]>('/fechas')
  fecha.value = (res.data || []).find((f: any) => f.id === id)

  if (fecha.value) {
    fechaCategorias.value = fecha.value.fecha_categorias || fecha.value.categorias || []
    if (!selectedCatId.value && fechaCategorias.value.length > 0) {
      selectedCatId.value = fechaCategorias.value[0].id
    }
    // Default tab: config if no categories, ranking if there are
    if (fechaCategorias.value.length === 0) {
      tab.value = 'config'
    } else if (tab.value === 'config' && fechaCategorias.value.length > 0 && fecha.value.estado !== 'borrador') {
      tab.value = 'ranking'
    }
    if (selectedCatId.value) {
      await loadRanking()
    }
  }
  loading.value = false
  await offline.loadPending()

  // Restaurar par activo si hay uno guardado
  if (tab.value === 'cronometraje' && !selectedTrip.value) {
    restaurarParActivo()
  }
}

async function loadRanking() {
  if (!fecha.value || !selectedCatId.value) { tripulaciones.value = []; rankingData.value = null; return }
  try {
    const res = await apiFetch<any>(`/public/fechas/${fecha.value.id}/categorias/${selectedCatId.value}/ranking`)
    rankingData.value = res.data
    tripulaciones.value = [...(res.data.ranking || []), ...(res.data.en_curso || []), ...(res.data.dnf || [])]
      .sort((a: any, b: any) => (a.orden_largada || 99) - (b.orden_largada || 99))
  } catch {
    tripulaciones.value = []
    rankingData.value = null
  }
}

const cronoA = computed(() => dispositivos.value.find((d: any) => d.codigo === 'crono-a'))
const cronoB = computed(() => dispositivos.value.find((d: any) => d.codigo === 'crono-b'))

const selectedCat = computed(() => fechaCategorias.value.find((c: any) => c.id === selectedCatId.value))
const filteredTrips = computed(() => {
  if (!search.value) return tripulaciones.value
  const q = search.value.toLowerCase()
  return tripulaciones.value.filter((t: any) =>
    t.numero.includes(q) || t.nombre.toLowerCase().includes(q) || t.piloto.toLowerCase().includes(q))
})

function selectCat(id: string) {
  selectedCatId.value = id
  selectedTrip.value = null
  loadRanking()
}

// --- Config: categorias ---
async function loadCatalogo() {
  const res = await apiFetch<any[]>('/categorias')
  catalogo.value = res.data || []
}

async function addCategoriaToFecha() {
  if (!addCatForm.value.categoria_catalogo_id) return
  try {
    await apiMutate('POST', `/fechas/${route.params.id}/categorias`, addCatForm.value)
    showAddCat.value = false
    addCatForm.value = { categoria_catalogo_id: '', penal_estaca_seg: 5, penal_cinta_seg: 10, tipo_pista: null as string | null }
    showToast('Categoria agregada')
    await load()
  } catch (e: any) {
    showToast(e.message || 'Error', 'error')
  }
}

async function removeCategoriaFromFecha(fcId: string) {
  if (!confirm('Quitar esta categoria de la fecha?')) return
  try {
    await apiMutate('DELETE', `/fecha-categorias/${fcId}`)
    showToast('Categoria quitada')
    if (selectedCatId.value === fcId) selectedCatId.value = null
    await load()
  } catch (e: any) {
    showToast(e.message || 'Error', 'error')
  }
}

// --- Inscripcion ---
async function inscribir() {
  if (!selectedCatId.value) return

  if (editingTripId.value) {
    await apiMutate('PUT', `/tripulaciones/${editingTripId.value}`, tripForm.value)
    editingTripId.value = null
    showToast('Tripulacion actualizada')
  } else {
    await apiMutate('POST', `/fecha-categorias/${selectedCatId.value}/tripulaciones`, tripForm.value)
    showToast('Tripulacion inscripta')
  }

  tripForm.value = { numero: '', nombre: '', piloto: '', copiloto: '' }
  await load()
}

function editarTrip(t: any) {
  editingTripId.value = t.id
  tripForm.value = { numero: t.numero, nombre: t.nombre, piloto: t.piloto, copiloto: t.copiloto || '' }
}

function cancelarEdicion() {
  editingTripId.value = null
  tripForm.value = { numero: '', nombre: '', piloto: '', copiloto: '' }
}

async function eliminarTrip(id: string) {
  if (!confirm('Eliminar?')) return
  await apiMutate('DELETE', `/tripulaciones/${id}`)
  if (editingTripId.value === id) cancelarEdicion()
  await load()
}

// --- Cronometraje ---
function selectTrip(t: any) {
  startCronoPolling()
  enviarNumeroTripulacion(t.numero)
  selectedTrip.value = t
  const done = (t.vueltas || []).filter((v: any) => v.fase === 'clasificacion').map((v: any) => v.numero_vuelta)
  for (let i = 1; i <= (fecha.value?.vueltas_clasificacion || 1); i++) {
    if (!done.includes(i)) { selectedVuelta.value = i; break }
  }
  if (done.length >= (fecha.value?.vueltas_clasificacion || 1)) {
    selectedVuelta.value = done.length > 0 ? done[done.length - 1] : 1
  }
  resetTramoForms()
}

function calcularVueltaPendiente(tripA: any, tripB: any): number {
  const total = fecha.value?.vueltas_clasificacion || 1
  for (let n = 1; n <= total; n++) {
    // Vuelta completa = ambas trips tienen tramo A y B con tiempo > 0
    const fase = 'clasificacion'
    const vA = (tripA?.vueltas || []).find((v: any) => v.numero_vuelta === n && v.fase === fase)
    const vB = (tripB?.vueltas || []).find((v: any) => v.numero_vuelta === n && v.fase === fase)
    const aCompleta = vA?.tramos?.some((tr: any) => tr.letra === 'A' && tr.tiempo_ms > 0)
      && vA?.tramos?.some((tr: any) => tr.letra === 'B' && tr.tiempo_ms > 0)
    const bCompleta = tripB
      ? (vB?.tramos?.some((tr: any) => tr.letra === 'A' && tr.tiempo_ms > 0)
        && vB?.tramos?.some((tr: any) => tr.letra === 'B' && tr.tiempo_ms > 0))
      : true
    const nula = vA?.nula || vB?.nula
    if (!aCompleta || !bCompleta) {
      if (!nula) return n  // primera vuelta no completa y no nula
    }
  }
  return total  // todas completas, quedar en la ultima
}

function vueltaCompleta(n: number): boolean {
  if (!selectedTrip.value) return false
  const trip = selectedTrip.value
  const fase = n === 99 ? 'final' : 'clasificacion'
  const vuelta = (trip.vueltas || []).find((v: any) => v.numero_vuelta === n && v.fase === fase)
  if (!vuelta?.tramos) return false
  if (selectedTripB.value) {
    // Pista doble: necesita tramo A y B (entre las 2 trips)
    const tripB = selectedTripB.value
    const vueltaB = (tripB.vueltas || []).find((v: any) => v.numero_vuelta === n && v.fase === fase)
    const tieneA = vuelta.tramos.some((tr: any) => tr.letra === 'A' && tr.tiempo_ms > 0)
    const tieneB = vueltaB?.tramos?.some((tr: any) => tr.letra === 'B' && tr.tiempo_ms > 0)
    const tripATieneB = vuelta.tramos.some((tr: any) => tr.letra === 'B' && tr.tiempo_ms > 0)
    const tripBTieneA = vueltaB?.tramos?.some((tr: any) => tr.letra === 'A' && tr.tiempo_ms > 0)
    return !!(tieneA && tieneB && tripATieneB && tripBTieneA)
  }
  return vuelta.tramos.some((tr: any) => tr.letra === 'A' && tr.tiempo_ms > 0)
}

function vueltaHabilitada(n: number): boolean {
  if (n === 1) return true
  return vueltaCompleta(n - 1)
}

function cargarTramoEnForm(form: any, tr: any) {
  const ms = tr.tiempo_ms || 0
  const totalSec = Math.floor(ms / 1000)
  form.value.tiempo_min = totalSec >= 60 ? String(Math.floor(totalSec / 60)) : ''
  form.value.tiempo_sec = String(totalSec % 60) || ''
  form.value.tiempo_ms = String(Math.floor((ms % 1000) / 10)) || ''
  form.value.estacas = tr.estacas || 0
  form.value.cintas = tr.cintas || 0
  form.value.tiempos_muertos = (tr.tiempos_muertos || []).map((tm: any) => tm.segundos || tm)
}

async function marcarDNF(trip: any) {
  if (!confirm(`DNF — #${trip.numero} NO CORRE MÁS\n\nSe retira de la fecha.\nSus vueltas quedan como están.\n¿Confirmar?`)) return
  try {
    await apiMutate('POST', `/tripulaciones/${trip.id}/abandonar`)
    if (navigator.vibrate) navigator.vibrate([200, 100, 200])
    showToast(`#${trip.numero} marcado como DNF`)
    await load()
  } catch (e: any) {
    showToast(e.message || 'Error', 'error')
  }
}

async function reincorporarTrip(trip: any) {
  if (!confirm(`Reincorporar #${trip.numero}?\n\nVuelve a estar habilitado para correr.`)) return
  try {
    await apiMutate('POST', `/tripulaciones/${trip.id}/reincorporar`)
    showToast(`#${trip.numero} reincorporado`)
    await load()
  } catch (e: any) {
    showToast(e.message || 'Error', 'error')
  }
}

async function abandonarVuelta(trip: any) {
  if (!trip) return
  if (!confirm(`#${trip.numero} ABANDONA ESTA VUELTA\n\nV${selectedVuelta.value} quedará NULA (corrida 1 + 2).\nLa otra tripulación sigue corriendo.\nPuede volver a largar en la vuelta siguiente.`)) return

  try {
    // Crear tramo vacío para que exista la vuelta, luego marcar nula
    const path = `/tripulaciones/${trip.id}/vueltas/${selectedVuelta.value}/tramos/A`
    await apiMutate('PUT', path, { tiempo_ms: 0, estacas: 0, cintas: 0 })

    // Buscar la vuelta y marcar nula
    const tripData = await apiFetch<any>(`/public/fechas/${fecha.value.id}/tripulaciones/${trip.id}`)
    const vuelta = (tripData.data?.vueltas || []).find((v: any) => v.numero_vuelta === selectedVuelta.value)
    if (vuelta) {
      await apiMutate('POST', `/vueltas/${vuelta.id}/nula`)
    }

    // Reset crono de la pista donde corría el que abandonó
    const esTripA = trip.id === tripEnPistaA.value?.id
    const cronoCodigo = esTripA ? 'crono-a' : 'crono-b'
    await apiMutate('POST', `/cronometro/${cronoCodigo}/comando`, { tipo: 'reset' }).catch(() => {})

    if (navigator.vibrate) navigator.vibrate([100, 50, 100])
    showToast(`#${trip.numero} abandonó — vuelta V${selectedVuelta.value} nula`)
    await load()
    selectedTrip.value = tripulaciones.value.find((t: any) => t.id === selectedTrip.value?.id) || selectedTrip.value
    if (selectedTripB.value) {
      selectedTripB.value = tripulaciones.value.find((t: any) => t.id === selectedTripB.value?.id) || selectedTripB.value
    }
  } catch (e: any) {
    showToast(e.message || 'Error', 'error')
  }
}

function buscarTramo(trip: any, letra: string) {
  if (!trip) return null
  const fase = selectedVuelta.value === 99 ? 'final' : 'clasificacion'
  const vuelta = (trip.vueltas || []).find((v: any) => v.numero_vuelta === selectedVuelta.value && v.fase === fase)
  if (!vuelta?.tramos) return null
  return vuelta.tramos.find((tr: any) => tr.letra === letra)
}

function resetTramoForms() {
  tramoA.value = { tiempo_min: '', tiempo_sec: '', tiempo_ms: '', estacas: 0, cintas: 0, tiempos_muertos: [] }
  tramoB.value = { tiempo_min: '', tiempo_sec: '', tiempo_ms: '', estacas: 0, cintas: 0, tiempos_muertos: [] }
  tmInputA.value = { min: '', seg: '' }
  tmInputB.value = { min: '', seg: '' }

  if (selectedTripB.value) {
    // Pista doble: en corrida 1 tripA corre pista A (su tramo A), tripB corre pista B (su tramo B)
    // en corrida 2 tripB corre pista A (su tramo A), tripA corre pista B (su tramo B)
    // Formulario Pista A muestra el tramo de quien corre en A
    // Formulario Pista B muestra el tramo de quien corre en B
    const tripEnA = corridaActual.value === 1 ? selectedTrip.value : selectedTripB.value
    const tripEnB = corridaActual.value === 1 ? selectedTripB.value : selectedTrip.value
    const trA = buscarTramo(tripEnA, 'A')
    const trB = buscarTramo(tripEnB, 'B')
    if (trA) cargarTramoEnForm(tramoA, trA)
    if (trB) cargarTramoEnForm(tramoB, trB)
  } else if (selectedTrip.value) {
    // Pista simple
    const trA = buscarTramo(selectedTrip.value, 'A')
    if (trA) cargarTramoEnForm(tramoA, trA)
  }
}

function tramoToMs(t: any): number {
  const centesimas = parseInt(t.tiempo_ms) || 0
  return ((parseInt(t.tiempo_min) || 0) * 60 + (parseInt(t.tiempo_sec) || 0)) * 1000 + centesimas * 10
}

async function guardarTramo(letra: 'A' | 'B') {
  if (!selectedTrip.value) return
  const form = letra === 'A' ? tramoA.value : tramoB.value
  const body = {
    tiempo_ms: tramoToMs(form),
    estacas: form.estacas,
    cintas: form.cintas,
    tiempos_muertos: form.tiempos_muertos,
  }

  const path = `/tripulaciones/${selectedTrip.value.id}/vueltas/${selectedVuelta.value}/tramos/${letra}`

  try {
    await apiMutate('PUT', path, body)
    if (navigator.vibrate) navigator.vibrate(50)
    showToast(`Tramo ${letra} guardado`)
    await load()
    selectedTrip.value = tripulaciones.value.find((t: any) => t.id === selectedTrip.value?.id) || null
  } catch (e: any) {
    if (!navigator.onLine) {
      await offline.enqueue('PUT', path, body)
      showToast('Sin conexion - guardado en cola')
    } else {
      showToast(e.message || 'Error', 'error')
    }
  }
}



async function anularVuelta() {
  if (!selectedTrip.value) return
  if (!confirm('Anular esta vuelta?')) return

  // Primero crear la vuelta con un tramo vacío para que exista, luego marcar nula
  const path = `/tripulaciones/${selectedTrip.value.id}/vueltas/${selectedVuelta.value}/tramos/A`
  try {
    await apiMutate('PUT', path, { tiempo_ms: 0, estacas: 0, cintas: 0 })
    // Buscar la vuelta recién creada y marcar nula
    const tripData = await apiFetch<any>(`/public/fechas/${fecha.value.id}/tripulaciones/${selectedTrip.value.id}`)
    const vuelta = (tripData.data?.vueltas || []).find((v: any) => v.numero_vuelta === selectedVuelta.value)
    if (vuelta) {
      await apiMutate('POST', `/vueltas/${vuelta.id}/nula`)
    }
    showToast('Vuelta anulada')
    await load()
    selectedTrip.value = tripulaciones.value.find((t: any) => t.id === selectedTrip.value?.id) || null
  } catch (e: any) {
    showToast(e.message || 'Error', 'error')
  }
}

async function confirmarVuelta(vueltaId: string) {
  if (!confirm('Confirmar esta vuelta? Una vez confirmada NO se puede modificar.')) return
  try {
    await apiMutate('POST', `/vueltas/${vueltaId}/confirmar`)
    if (navigator.vibrate) navigator.vibrate([50, 50, 50])
    showToast('Vuelta confirmada')
    await load()
    if (selectedTrip.value) {
      selectedTrip.value = tripulaciones.value.find((t: any) => t.id === selectedTrip.value?.id) || null
    }
  } catch (e: any) {
    showToast(e.message || 'Error al confirmar', 'error')
  }
}

async function salioAPista() {
  if (!selectedTrip.value) return
  await apiMutate('POST', `/tripulaciones/${selectedTrip.value.id}/salio-a-pista`)
  showToast('Marcado en pista')
  await load()
  selectedTrip.value = tripulaciones.value.find((t: any) => t.id === selectedTrip.value?.id) || null
}

// --- Config ---
const fechaEditable = computed(() => fecha.value && ['borrador', 'configurada'].includes(fecha.value.estado))

async function guardarPuntos(tripId: string, puntos: number) {
  try {
    await apiMutate('PUT', `/tripulaciones/${tripId}/puntos`, { puntos })
    showToast(`${puntos} puntos asignados`)
  } catch (e: any) {
    showToast(e.message || 'Error', 'error')
  }
}

async function activarFecha() {
  const cats = fechaCategorias.value.length
  const trips = tripulaciones.value.length
  const msg = `ACTIVAR FECHA\n\n` +
    `Una vez activada NO se puede:\n` +
    `- Modificar datos de la fecha\n` +
    `- Agregar o quitar categorias\n` +
    `- Cambiar penalizaciones\n\n` +
    `Categorias: ${cats}\n` +
    `Tripulaciones: ${trips}\n\n` +
    `Revisa que todo este correcto.\n` +
    `Continuar?`
  if (!confirm(msg)) return
  await apiMutate('POST', `/fechas/${route.params.id}/activar`)
  showToast('Fecha activada')
  await load()
}

async function eliminarFecha() {
  if (['activa', 'finalizada', 'en_curso'].includes(fecha.value?.estado)) {
    const nombre = prompt(`ELIMINAR FECHA "${fecha.value.nombre}"\n\nEsta fecha tiene datos de carrera.\nSe borran TODAS las tripulaciones, vueltas, tiempos.\n\nEscribi el nombre de la fecha para confirmar:`)
    if (nombre !== fecha.value.nombre) {
      showToast('Nombre incorrecto, no se elimino', 'error')
      return
    }
  } else {
    if (!confirm('Eliminar esta fecha? Se borran todas las tripulaciones y datos.')) return
  }
  try {
    await apiMutate('DELETE', `/fechas/${route.params.id}`)
    router.push('/admin')
  } catch (e: any) {
    showToast(e.message || 'Error al eliminar', 'error')
  }
}
async function finalizarFecha() {
  const cats = fechaCategorias.value.length
  const trips = tripulaciones.value.length
  const dnfTrips = tripulaciones.value.filter((t: any) => t.estado === 'abandonado')
  const dnfList = dnfTrips.map((t: any) => `#${t.numero} ${t.nombre}`).join('\n')
  const msg = `FINALIZAR FECHA

Una vez finalizada NO se puede:
- Modificar tiempos ni penalizaciones
- Inscribir o eliminar tripulaciones
- Cambiar configuracion

Solo el administrador podra modificar puntos.

Categorias: ${cats}
Tripulaciones: ${trips}
${dnfTrips.length ? `\nDNF (1 punto):\n${dnfList}\n` : ''}
Estas seguro?`
  if (!confirm(msg)) return
  await apiMutate('POST', `/fechas/${route.params.id}/finalizar`)
  showToast('Fecha finalizada')
  await load()
}
async function configurarFecha() {
  try {
    await apiMutate('POST', `/fechas/${route.params.id}/configurar`)
    showToast('Fecha configurada')
    await load()
  } catch (e: any) {
    showToast(e.message || 'Error', 'error')
  }
}


function autotab(event: Event, nextRef: string) {
  const el = event.target as HTMLInputElement
  const maxLen = nextRef.includes('ms') ? 3 : 2
  if (el.value.length >= maxLen) {
    const next = document.querySelector(`[data-ref="${nextRef}"]`) as HTMLInputElement
    next?.focus(); next?.select()
  }
}

// Countdown del sensor armado
setInterval(() => {
  if (sensorArmadoHasta.value > Date.now()) {
    sensorCountdown.value = Math.ceil((sensorArmadoHasta.value - Date.now()) / 1000)
  } else {
    sensorCountdown.value = 0
  }
}, 500)

if (typeof window !== 'undefined') {
  window.addEventListener('online', () => offline.drain())
}
</script>

<template>
  <div class="min-h-screen bg-gray-50">
    <header class="bg-[var(--brand-dark)] text-white px-4 py-3">
      <div class="max-w-2xl mx-auto">
        <div class="flex items-center gap-3 mb-2">
          <button @click="router.push('/admin')" class="text-white/60 hover:text-white">&larr;</button>
          <h1 class="font-bold truncate text-sm">{{ fecha?.nombre || 'Cargando...' }}</h1>
          <span v-if="fecha" :class="['text-[10px] px-2 py-0.5 rounded-full ml-auto shrink-0', estadoColor(fecha.estado)]">
            {{ estadoLabel(fecha.estado) }}
          </span>
        </div>
        <div class="flex gap-1">
          <button v-for="t in (['config', 'inscripcion', 'cronometraje', 'ranking'] as const)" :key="t"
            @click="tab = t; if (t === 'cronometraje') { startCronoPolling(); restaurarParActivo() } else { selectedTrip = null; selectedTripB = null; stopCronoPolling() }"
            :class="['px-3 py-1.5 rounded-t-lg text-xs font-medium transition-colors',
              tab === t ? 'bg-gray-50 text-gray-800' : 'text-white/60 hover:text-white']">
            {{ { config: 'Config', inscripcion: 'Inscripcion', cronometraje: 'Crono', ranking: 'Ranking' }[t] }}
          </button>
        </div>
      </div>
    </header>

    <!-- Offline banner -->
    <div v-if="offline.count > 0" class="bg-yellow-100 border-b border-yellow-200 px-4 py-2 text-center text-sm text-yellow-800">
      Sin conexion &middot; {{ offline.count }} cambio{{ offline.count > 1 ? 's' : '' }} pendiente{{ offline.count > 1 ? 's' : '' }}
    </div>

    <!-- Category sub-tabs -->
    <div v-if="tab !== 'config' && fechaCategorias.length > 0" class="bg-white border-b border-gray-200 px-4 py-2">
      <div class="max-w-2xl mx-auto flex gap-1.5 overflow-x-auto">
        <button v-for="c in fechaCategorias" :key="c.id" @click="selectCat(c.id)"
          :class="['px-3 py-1 rounded-full text-xs font-medium whitespace-nowrap',
            c.id === selectedCatId ? 'bg-[var(--brand-color)] text-white' : 'bg-gray-100 text-gray-600 hover:bg-gray-200']">
          {{ c.categoria_catalogo?.nombre || c.nombre }}
          <span class="ml-1 opacity-60">{{ faseLabel(c.fase) }}</span>
        </button>
      </div>
    </div>

    <main class="max-w-2xl mx-auto px-4 py-4">

      <!-- ==================== RANKING ==================== -->
      <div v-if="tab === 'ranking'" class="space-y-3">
        <div v-if="!rankingData" class="text-center py-8 text-gray-400">Cargando ranking...</div>
        <template v-else>
          <!-- Phase + stats -->
          <div class="flex items-center gap-2 flex-wrap">
            <span :class="['text-xs px-2.5 py-1 rounded-full font-medium', faseColor(rankingData.stats.fase)]">
              {{ faseLabel(rankingData.stats.fase) }}
            </span>
            <span class="text-xs text-gray-500">
              {{ rankingData.stats.total }} inscritas &middot;
              {{ rankingData.stats.en_pista }} en pista &middot;
              {{ rankingData.stats.completadas }} completas &middot;
              {{ rankingData.stats.nulas }} DNF
            </span>
          </div>

          <!-- Final ranking if applicable -->
          <div v-if="rankingData.final_ranking?.length" class="bg-orange-50 border border-orange-200 rounded-xl p-3">
            <h3 class="text-xs text-orange-700 uppercase tracking-wider font-medium mb-2">Ranking Final</h3>
            <div class="space-y-1.5">
              <div v-for="f in rankingData.final_ranking" :key="f.id"
                class="bg-white rounded-lg p-2.5 flex items-center gap-3">
                <div class="w-8 h-8 rounded-full flex items-center justify-center font-bold text-sm"
                  :class="f.posicion === 1 ? 'bg-[var(--brand-accent)] text-[var(--brand-dark)]' : f.posicion === 2 ? 'bg-gray-300 text-gray-700' : 'bg-amber-700 text-white'">
                  {{ f.posicion }}
                </div>
                <div class="flex-1 min-w-0">
                  <p class="font-medium text-gray-800 text-sm">#{{ f.numero }} {{ f.nombre }}</p>
                  <p class="text-xs text-gray-400">{{ f.piloto }}</p>
                </div>
                <span class="font-mono text-sm text-gray-800">{{ formatTiempo(f.vuelta_final?.total_vuelta) }}</span>
              </div>
            </div>
          </div>

          <!-- Classification ranking with full vuelta details -->
          <h3 class="text-xs text-gray-400 uppercase tracking-wider">
            Clasificacion (mejor de {{ fecha?.vueltas_clasificacion }} vueltas)
          </h3>
          <div class="space-y-2">
            <div v-for="t in rankingData.ranking" :key="t.id" class="bg-white rounded-xl shadow-sm p-3">
              <div class="flex items-center gap-3 mb-2">
                <div class="w-8 h-8 rounded-lg flex items-center justify-center font-bold text-sm shrink-0"
                  :class="t.posicion === 1 ? 'bg-[var(--brand-accent)] text-[var(--brand-dark)]' : t.posicion === 2 ? 'bg-gray-300 text-gray-700' : t.posicion === 3 ? 'bg-amber-700 text-white' : 'bg-gray-200 text-gray-500'">
                  {{ t.posicion }}
                </div>
                <div class="min-w-0 flex-1">
                  <p class="font-bold text-gray-800 text-sm truncate">
                    <span class="text-gray-400 font-mono">#{{ t.numero }}</span> {{ t.nombre }}
                  </p>
                  <p class="text-xs text-gray-400">{{ t.piloto }}<span v-if="t.copiloto"> / {{ t.copiloto }}</span></p>
                </div>
                <div class="text-right shrink-0">
                  <p class="font-bold font-mono text-gray-800 text-sm">{{ formatTiempo(t.mejor_vuelta_ms) }}</p>
                  <p v-if="t.diferencia" class="text-xs text-red-400 font-mono">{{ formatDiferencia(t.diferencia) }}</p>
                </div>
              </div>

              <!-- Vueltas detail -->
              <div class="flex gap-1 overflow-x-auto border-t border-gray-100 pt-2">
                <div v-for="v in t.vueltas" :key="v.id"
                  :class="['bg-gray-50 rounded-lg px-2 py-1.5 text-xs min-w-[80px] shrink-0',
                    v.numero_vuelta === t.mejor_vuelta_numero && !v.nula ? 'ring-1 ring-[var(--brand-color)] bg-green-50' : '']">
                  <p class="text-gray-400 font-medium mb-0.5">V{{ v.numero_vuelta }}</p>
                  <p v-if="v.nula" class="text-red-500 font-medium">NULA</p>
                  <template v-else>
                    <p class="font-mono text-gray-700 font-medium">{{ formatTiempo(v.total_vuelta) }}</p>
                    <div v-for="tr in v.tramos" :key="tr.id" class="text-[10px] text-gray-400 mt-0.5 flex justify-between">
                      <span>{{ tr.letra }}: {{ formatTiempo(tr.tiempo_ms) }}</span>
                      <span v-if="tr.estacas || tr.cintas" class="text-orange-500">{{ tr.estacas }}E {{ tr.cintas }}C</span>
                    </div>
                  </template>
                </div>
                <!-- Final vuelta if exists -->
                <div v-if="t.vuelta_final"
                  class="bg-orange-50 rounded-lg px-2 py-1.5 text-xs min-w-[80px] shrink-0 ring-1 ring-orange-300">
                  <p class="text-orange-600 font-medium mb-0.5">FINAL</p>
                  <p class="font-mono text-gray-700 font-medium">{{ formatTiempo(t.vuelta_final.total_vuelta) }}</p>
                  <div v-for="tr in t.vuelta_final.tramos" :key="tr.id" class="text-[10px] text-gray-400 mt-0.5 flex justify-between">
                    <span>{{ tr.letra }}: {{ formatTiempo(tr.tiempo_ms) }}</span>
                    <span v-if="tr.estacas || tr.cintas" class="text-orange-500">{{ tr.estacas }}E {{ tr.cintas }}C</span>
                  </div>
                </div>
              </div>

              <!-- Puntos (si fecha pertenece a campeonato) -->
              <div v-if="fecha?.campeonato_id" class="flex items-center gap-2 border-t border-gray-100 pt-2 mt-2">
                <span class="text-xs text-gray-400">Puntos:</span>
                <input
                  type="number" min="0"
                  :value="t.puntos || 0"
                  @change="guardarPuntos(t.id, parseInt(($event.target as HTMLInputElement).value) || 0)"
                  class="w-16 border border-gray-300 rounded px-2 py-1 text-center text-sm font-bold outline-none focus:ring-2 focus:ring-blue-500"
                />
              </div>
            </div>
          </div>

          <!-- En curso -->
          <div v-if="rankingData.en_curso?.length" class="bg-white rounded-xl shadow-sm p-3">
            <h3 class="text-xs text-gray-400 uppercase tracking-wider mb-2">En curso (sin ranking aun)</h3>
            <div class="space-y-2">
              <div v-for="t in rankingData.en_curso" :key="t.id" class="flex items-center justify-between text-sm">
                <div class="flex items-center gap-2">
                  <span class="font-mono text-gray-500 font-bold">#{{ t.numero }}</span>
                  <div>
                    <span class="text-gray-800">{{ t.nombre }}</span>
                    <span class="text-gray-400 text-xs ml-1">{{ t.piloto }}</span>
                  </div>
                </div>
                <div class="flex items-center gap-2">
                  <div class="flex gap-0.5">
                    <span v-for="v in t.vueltas" :key="v.id"
                      :class="['w-5 h-5 rounded text-[10px] flex items-center justify-center font-medium',
                        v.nula ? 'bg-red-100 text-red-500' : 'bg-green-100 text-green-600']">
                      {{ v.numero_vuelta }}
                    </span>
                  </div>
                  <span :class="['text-[10px] px-1.5 py-0.5 rounded-full', estadoColor(t.estado)]">
                    {{ estadoLabel(t.estado) }}
                  </span>
                </div>
              </div>
            </div>
          </div>

          <!-- DNF -->
          <div v-if="rankingData.dnf?.length" class="bg-white rounded-xl shadow-sm p-3">
            <h3 class="text-xs text-red-400 uppercase tracking-wider mb-2">DNF ({{ rankingData.dnf.length }})</h3>
            <div v-for="t in rankingData.dnf" :key="t.id" class="text-sm text-gray-500">
              #{{ t.numero }} {{ t.nombre }} — {{ t.piloto }}
            </div>
          </div>
        </template>
      </div>

      <!-- ==================== CRONOMETRAJE ==================== -->
      <div v-if="tab === 'cronometraje'" class="space-y-3">
        <template v-if="!selectedTrip">
          <!-- Toggle pares (solo pista doble) -->
          <div v-if="fecha?.tipo_pista === 'doble'" class="flex items-center justify-between">
            <input v-model="search" placeholder="Buscar..." inputmode="search"
              class="flex-1 rounded-lg border border-gray-300 px-3 py-2.5 outline-none focus:ring-2 focus:ring-blue-500 mr-2" />
            <button @click="showPares = !showPares; if (showPares) initOrdenList()"
              :class="['px-3 py-2.5 rounded-lg text-xs font-medium whitespace-nowrap transition-colors',
                showPares ? 'bg-[var(--brand-color)] text-white' : 'bg-gray-200 text-gray-600']">
              Pares
            </button>
          </div>
          <input v-else v-model="search" placeholder="Buscar por numero o nombre..." inputmode="search"
            class="w-full rounded-lg border border-gray-300 px-3 py-2.5 outline-none focus:ring-2 focus:ring-blue-500" />

          <!-- Vista de pares con reorden -->
          <div v-if="showPares && fecha?.tipo_pista === 'doble'" class="space-y-2">
            <p v-if="!ordenBloqueado" class="text-xs text-gray-400">Usa las flechas para reordenar. Los pares se arman automatico (1vs2, 3vs4...).</p>
            <p v-else class="text-xs text-green-600 font-medium">Orden bloqueado. Toca "Desbloquear" para modificar.</p>
            <div class="bg-white rounded-xl shadow-sm divide-y divide-gray-100">
              <div v-for="(t, i) in ordenList" :key="t.id"
                class="flex items-center gap-2 px-3 py-2">
                <span class="text-xs text-gray-300 w-5 shrink-0">{{ i + 1 }}</span>
                <span :class="['text-[10px] px-1.5 py-0.5 rounded font-medium shrink-0',
                  i % 2 === 0 ? 'bg-blue-100 text-blue-700' : 'bg-amber-100 text-amber-700']">
                  {{ i % 2 === 0 ? 'A' : 'B' }}
                </span>
                <span class="font-bold text-gray-800 text-sm">#{{ t.numero }}</span>
                <span class="text-xs text-gray-500 truncate flex-1">{{ t.piloto }}</span>
                <div class="flex gap-1 shrink-0">
                  <button v-if="!ordenBloqueado" @click="moverArriba(i)" :disabled="i === 0"
                    class="w-7 h-7 rounded bg-gray-100 hover:bg-gray-200 text-gray-500 disabled:opacity-30 text-sm">&#x25B2;</button>
                  <button v-if="!ordenBloqueado" @click="moverAbajo(i)" :disabled="i === ordenList.length - 1"
                    class="w-7 h-7 rounded bg-gray-100 hover:bg-gray-200 text-gray-500 disabled:opacity-30 text-sm">&#x25BC;</button>
                </div>
              </div>
            </div>
            <div v-if="!ordenBloqueado" class="flex gap-2">
              <button @click="sortearOrden" class="flex-1 bg-amber-500 hover:bg-amber-600 text-white font-medium py-2 rounded-lg text-sm">
                Sortear
              </button>
              <button @click="guardarOrden" class="flex-1 bg-[var(--brand-color)] text-white font-medium py-2 rounded-lg text-sm">
                Guardar orden
              </button>
            </div>
            <button v-else @click="ordenBloqueado = false"
              class="w-full bg-red-100 hover:bg-red-200 text-red-700 font-medium py-2 rounded-lg text-sm">
              Desbloquear orden
            </button>
            <div class="space-y-1">
              <div v-for="(par, i) in pares" :key="i" class="bg-gray-50 rounded-lg p-2 flex items-center gap-2">
                <span class="text-xs text-gray-400 w-5 text-center">{{ i + 1 }}</span>
                <span class="bg-blue-100 text-blue-700 px-2 py-0.5 rounded text-xs font-bold">#{{ par[0].numero }}</span>
                <span class="text-gray-300 text-xs">vs</span>
                <span v-if="par[1]" class="bg-amber-100 text-amber-700 px-2 py-0.5 rounded text-xs font-bold">#{{ par[1].numero }}</span>
                <span v-else class="text-gray-300 text-xs">Solo</span>
              </div>
            </div>
          </div>

          <!-- Seleccion de par manual (pista doble) -->
          <div v-if="esDobleCategoria" class="bg-white rounded-xl p-3 shadow-sm space-y-2 mb-2">
            <p class="text-[10px] text-gray-400 uppercase tracking-wider">Armar par</p>
            <div class="grid grid-cols-2 gap-2">
              <div :class="['rounded-lg p-2 text-center border-2', pistaATrip ? 'bg-blue-50 border-blue-400' : 'bg-gray-50 border-dashed border-gray-300']">
                <p class="text-[10px] text-blue-500">Pista A</p>
                <p v-if="pistaATrip" class="font-bold text-lg">#{{ pistaATrip.numero }}</p>
                <p v-if="pistaATrip" class="text-xs text-gray-500">{{ pistaATrip.piloto }}</p>
                <p v-else class="text-gray-300 text-sm">Tocar tripulacion</p>
                <button v-if="pistaATrip" @click="pistaATrip = null" class="text-[10px] text-red-400 mt-1">Quitar</button>
              </div>
              <div :class="['rounded-lg p-2 text-center border-2', pistaBTrip ? 'bg-amber-50 border-amber-400' : 'bg-gray-50 border-dashed border-gray-300']">
                <p class="text-[10px] text-amber-500">Pista B</p>
                <p v-if="pistaBTrip" class="font-bold text-lg">#{{ pistaBTrip.numero }}</p>
                <p v-if="pistaBTrip" class="text-xs text-gray-500">{{ pistaBTrip.piloto }}</p>
                <p v-else class="text-gray-300 text-sm">Tocar tripulacion</p>
                <button v-if="pistaBTrip" @click="pistaBTrip = null" class="text-[10px] text-red-400 mt-1">Quitar</button>
              </div>
            </div>
            <button v-if="pistaATrip" @click="iniciarParManual"
              class="w-full bg-green-600 hover:bg-green-700 text-white font-bold py-2.5 rounded-lg text-sm">
              Cronometrar {{ pistaATrip ? '#' + pistaATrip.numero : '' }} {{ pistaBTrip ? 'vs #' + pistaBTrip.numero : '(solo)' }}
            </button>
          </div>

          <!-- Lista de tripulaciones para cronometrar -->
          <div class="space-y-1.5">
            <button v-for="t in filteredTrips" :key="t.id" @click="asignarPista(t)"
              class="w-full bg-white rounded-lg p-3 shadow-sm flex items-center gap-3 text-left hover:shadow-md transition-shadow">
              <div :class="['w-12 h-12 rounded-lg flex items-center justify-center font-bold text-xl shrink-0',
                pistaATrip?.id === t.id ? 'bg-blue-500 text-white' :
                pistaBTrip?.id === t.id ? 'bg-amber-500 text-white' :
                'bg-[var(--brand-color)] text-white']">
                {{ t.numero }}
              </div>
              <div class="min-w-0 flex-1">
                <p class="font-medium text-gray-800 text-sm">{{ t.nombre }}</p>
                <p class="text-xs text-gray-500">{{ t.piloto }}</p>
              </div>
              <div class="flex items-center gap-1 shrink-0">
                <span v-if="pistaATrip?.id === t.id" class="text-[10px] bg-blue-100 text-blue-700 px-1.5 py-0.5 rounded-full">A</span>
                <span v-if="pistaBTrip?.id === t.id" class="text-[10px] bg-amber-100 text-amber-700 px-1.5 py-0.5 rounded-full">B</span>
                <span :class="['text-[10px] px-1.5 py-0.5 rounded-full', estadoColor(t.estado)]">
                  {{ estadoLabel(t.estado) }}
                </span>
                <button v-if="t.estado !== 'abandonado'" @click.stop="marcarDNF(t)"
                  class="text-[9px] bg-red-50 text-red-500 px-1.5 py-0.5 rounded hover:bg-red-100">DNF</button>
                <button v-else @click.stop="reincorporarTrip(t)"
                  class="text-[9px] bg-green-50 text-green-600 px-1.5 py-0.5 rounded hover:bg-green-100">Reinc.</button>
              </div>
            </button>
          </div>
        </template>

        <!-- Trip selected: entry form -->
        <template v-else>
          <button @click="selectedTrip = null; selectedTripB = null; corridaActual = 1; stopCronoPolling(); enviarNumeroTripulacion(0); limpiarParActivo()" class="text-sm text-blue-600">&larr; Volver</button>

          <div class="bg-white rounded-xl p-4 shadow-sm space-y-4">

            <!-- ===== HEADER PISTA DOBLE: muestra quien corre en cada pista ESTA corrida ===== -->
            <template v-if="selectedTripB">
              <!-- Indicador de corrida -->
              <div class="flex items-center justify-center gap-2">
                <button
                  :disabled="corrida1Guardada"
                  :class="['px-4 py-1.5 rounded-lg text-sm font-bold transition-colors',
                    corridaActual === 1 ? 'bg-blue-600 text-white' : corrida1Guardada ? 'bg-gray-100 text-gray-300 cursor-not-allowed' : 'bg-gray-100 text-gray-500']">
                  Corrida 1 {{ corrida1Guardada ? '&#x2713;' : '' }}
                </button>
                <button @click="corrida1Guardada ? cambiarACorrida2() : undefined"
                  :disabled="!corrida1Guardada"
                  :class="['px-4 py-1.5 rounded-lg text-sm font-bold transition-colors',
                    corridaActual === 2 ? 'bg-blue-600 text-white' : !corrida1Guardada ? 'bg-gray-100 text-gray-300 cursor-not-allowed' : 'bg-gray-100 text-gray-500']">
                  Corrida 2
                </button>
              </div>
              <div class="grid grid-cols-2 gap-2">
                <div class="bg-blue-50 rounded-lg p-2 text-center border-2 border-blue-300">
                  <p class="text-[10px] text-blue-500 font-bold uppercase">Pista A</p>
                  <p class="font-bold text-2xl text-gray-800">#{{ tripEnPistaA?.numero }}</p>
                  <p class="text-xs text-gray-600 truncate">{{ tripEnPistaA?.nombre }}</p>
                </div>
                <div class="bg-amber-50 rounded-lg p-2 text-center border-2 border-amber-300">
                  <p class="text-[10px] text-amber-500 font-bold uppercase">Pista B</p>
                  <p class="font-bold text-2xl text-gray-800">#{{ tripEnPistaB?.numero }}</p>
                  <p class="text-xs text-gray-600 truncate">{{ tripEnPistaB?.nombre }}</p>
                </div>
              </div>
              <p class="text-center text-xs text-gray-400">
                Corrida {{ corridaActual }}: #{{ tripEnPistaA?.numero }} en A, #{{ tripEnPistaB?.numero }} en B
              </p>
            </template>

            <!-- ===== HEADER PISTA SIMPLE ===== -->
            <div v-else class="flex items-center gap-3">
              <div class="w-14 h-14 bg-[var(--brand-color)] text-white rounded-lg flex items-center justify-center font-bold text-2xl">
                {{ selectedTrip.numero }}
              </div>
              <div>
                <p class="font-bold text-gray-800">{{ selectedTrip.nombre }}</p>
                <p class="text-sm text-gray-500">{{ selectedTrip.piloto }}</p>
              </div>
            </div>

            <!-- Tiempos cargados de ambas tripulaciones (doble) -->
            <template v-if="selectedTripB">
              <div class="grid grid-cols-2 gap-2">
                <div v-for="trip in [selectedTrip, selectedTripB]" :key="trip.id" class="bg-gray-50 rounded-lg p-2 space-y-1">
                  <p class="text-[10px] text-gray-400 font-medium">#{{ trip.numero }} - Tiempos</p>
                  <div v-for="v in trip.vueltas" :key="v.id" class="text-xs">
                    <div class="flex items-center justify-between">
                      <span class="font-mono text-gray-700">V{{ v.numero_vuelta }}: {{ v.nula ? 'NULA' : formatTiempo(v.total_vuelta) }}</span>
                      <button v-if="!v.nula && v.tramos?.length >= 2" @click="imprimirTiempo(trip, v)"
                        class="text-[9px] bg-gray-200 text-gray-600 px-1.5 py-0.5 rounded">Print</button>
                    </div>
                    <div v-for="tr in v.tramos" :key="tr.id" class="text-[10px] text-gray-400 ml-2">
                      {{ tr.letra }}: {{ formatTiempo(tr.tiempo_ms) }}
                      <span v-if="tr.estacas || tr.cintas" class="text-orange-500">{{ tr.estacas }}E {{ tr.cintas }}C</span>
                    </div>
                  </div>
                  <p v-if="!trip.vueltas?.length" class="text-[10px] text-gray-300">Sin tiempos</p>
                </div>
              </div>
            </template>

            <!-- Tiempos cargados (simple) -->
            <div v-else-if="selectedTrip.vueltas?.length" class="bg-gray-50 rounded-lg p-2.5 space-y-1.5">
              <p class="text-[10px] text-gray-400 uppercase tracking-wider">Tiempos cargados</p>
              <div v-for="v in selectedTrip.vueltas" :key="v.id"
                :class="['rounded-lg p-2 flex items-center justify-between',
                  v.confirmada ? 'bg-blue-50 border border-blue-200' : 'bg-white border border-gray-200']">
                <span class="font-mono text-sm text-gray-700">
                  V{{ v.numero_vuelta === 99 ? 'F' : v.numero_vuelta }}:
                  {{ v.nula ? 'NULA' : formatTiempo(v.total_vuelta) }}
                </span>
                <div class="flex gap-1">
                  <button v-if="!v.nula" @click="imprimirTiempo(selectedTrip, v)"
                    class="text-[10px] bg-gray-100 text-gray-600 px-2 py-1 rounded">Print</button>
                  <span v-if="v.confirmada" class="text-[10px] bg-blue-100 text-blue-700 px-1.5 py-0.5 rounded-full">OK</span>
                  <button v-else @click="confirmarVuelta(v.id)"
                    class="text-xs bg-blue-600 text-white px-2 py-1 rounded-lg font-medium">Confirmar</button>
                </div>
              </div>
            </div>

            <!-- Dispositivos + Botones -->
            <div class="bg-gray-50 rounded-xl p-2 border border-gray-200 space-y-2">
              <div class="flex items-center gap-3 flex-wrap">
                <div v-for="d in dispositivos" :key="d.codigo" class="flex items-center gap-1">
                  <span :class="['w-2 h-2 rounded-full', d.online ? 'bg-green-500' : 'bg-red-400']"></span>
                  <span class="text-[10px] text-gray-600">{{ d.codigo }}</span>
                  <span v-if="d.online && d.ultimo_rssi" class="text-[9px] text-gray-400">{{ d.ultimo_rssi }}</span>
                </div>
              </div>
              <div class="grid grid-cols-3 gap-2">
                <button @click="armarLargada" :disabled="largadaEnCurso"
                  :class="['text-white text-xs font-bold py-2.5 rounded-lg transition-colors',
                    largadaEnCurso ? 'bg-gray-300 cursor-not-allowed' : 'bg-green-600 hover:bg-green-700']">
                  ARMAR LARGADA
                </button>
                <button @click="armarLlegada"
                  class="bg-amber-500 hover:bg-amber-600 text-white text-xs font-bold py-2.5 rounded-lg transition-colors">
                  ARMAR LLEGADA
                </button>
                <button @click="enviarComandoCrono('reset'); largadaEnCurso = false"
                  class="bg-gray-200 hover:bg-gray-300 text-gray-600 text-xs font-bold py-2.5 rounded-lg transition-colors">
                  RESET
                </button>
              </div>
            </div>

            <!-- Salio a pista (doble: marca los dos) -->
            <button v-if="selectedTripB && (selectedTrip.estado === 'inscripta' || selectedTripB.estado === 'inscripta')"
              @click="salioAPistaDoble"
              class="w-full bg-yellow-500 hover:bg-yellow-600 text-white font-bold py-3 rounded-lg text-lg">
              Salieron a pista (#{{ selectedTrip.numero }} + #{{ selectedTripB.numero }})
            </button>
            <button v-else-if="!selectedTripB && selectedTrip.estado === 'inscripta'" @click="salioAPista"
              class="w-full bg-yellow-500 hover:bg-yellow-600 text-white font-bold py-3 rounded-lg text-lg">
              Salio a pista
            </button>

            <!-- Vuelta selector -->
            <div class="flex gap-1.5 overflow-x-auto">
              <button v-for="n in (fecha?.vueltas_clasificacion || 0)" :key="n"
                @click="vueltaHabilitada(n) ? (selectedVuelta = n, corridaActual = 1, corrida1Guardada = false, largadaEnCurso = false, resetTramoForms()) : undefined"
                :disabled="!vueltaHabilitada(n)"
                :class="['px-3 py-1.5 rounded-lg text-sm font-medium',
                  selectedVuelta === n ? 'bg-blue-600 text-white' : !vueltaHabilitada(n) ? 'bg-gray-100 text-gray-300 cursor-not-allowed' : 'bg-gray-100 text-gray-600']">
                V{{ n }} {{ vueltaCompleta(n) ? '&#x2713;' : '' }}
              </button>
              <button v-if="selectedCat?.fase === 'final'" @click="selectedVuelta = 99; corridaActual = 1; resetTramoForms()"
                :class="['px-3 py-1.5 rounded-lg text-sm font-medium',
                  selectedVuelta === 99 ? 'bg-orange-600 text-white' : 'bg-orange-100 text-orange-700']">
                Final
              </button>
            </div>

            <!-- Sensor armado countdown -->
            <div v-if="sensorCountdown > 0"
              :class="['text-center py-1.5 rounded-lg text-sm font-bold animate-pulse',
                sensorCountdown > 10 ? 'bg-yellow-100 text-yellow-700' : 'bg-red-100 text-red-600']">
              Sensor armado: {{ sensorCountdown }}s
            </div>

            <!-- ===== FORMULARIOS DE TIEMPO ===== -->
            <div :class="['gap-3', selectedTripB ? 'grid grid-cols-1 sm:grid-cols-2' : '']">

              <!-- Pista A -->
              <div :class="['rounded-lg p-3 space-y-2', selectedTripB ? 'bg-blue-50 border border-blue-200' : 'bg-gray-50']">
                <p :class="['text-xs font-bold uppercase', selectedTripB ? 'text-blue-600' : 'text-gray-500']">
                  {{ selectedTripB ? 'Pista A — #' + tripEnPistaA?.numero : 'Tiempo' }}
                </p>
                <div class="flex items-center gap-1">
                  <input v-model="tramoA.tiempo_min" data-ref="a-min" inputmode="numeric" maxlength="2" placeholder="00"
                    @input="autotab($event, 'a-sec')"
                    class="w-14 text-center text-xl font-mono border border-gray-300 rounded-lg py-2.5 outline-none focus:ring-2 focus:ring-blue-500" />
                  <span class="text-xl text-gray-400 font-bold">:</span>
                  <input v-model="tramoA.tiempo_sec" data-ref="a-sec" inputmode="numeric" maxlength="2" placeholder="00"
                    @input="autotab($event, 'a-cc')"
                    class="w-14 text-center text-xl font-mono border border-gray-300 rounded-lg py-2.5 outline-none focus:ring-2 focus:ring-blue-500" />
                  <span class="text-xl text-gray-400 font-bold">:</span>
                  <input v-model="tramoA.tiempo_ms" data-ref="a-cc" inputmode="numeric" maxlength="2" placeholder="00"
                    class="w-14 text-center text-xl font-mono border border-gray-300 rounded-lg py-2.5 outline-none focus:ring-2 focus:ring-blue-500"
                    @input="selectedTripB ? autotab($event, 'b-min') : undefined" />
                </div>
                <div class="grid grid-cols-2 gap-3">
                  <div>
                    <label class="text-xs text-gray-400">Estacas</label>
                    <div class="flex items-center gap-2">
                      <button @click="tramoA.estacas = Math.max(0, tramoA.estacas - 1)" class="w-9 h-9 rounded-lg bg-gray-100 hover:bg-gray-200 text-lg font-bold text-gray-600">-</button>
                      <span class="text-xl font-mono w-6 text-center">{{ tramoA.estacas }}</span>
                      <button @click="tramoA.estacas++" class="w-9 h-9 rounded-lg bg-gray-100 hover:bg-gray-200 text-lg font-bold text-gray-600">+</button>
                    </div>
                  </div>
                  <div>
                    <label class="text-xs text-gray-400">Cintas</label>
                    <div class="flex items-center gap-2">
                      <button @click="tramoA.cintas = Math.max(0, tramoA.cintas - 1)" class="w-9 h-9 rounded-lg bg-gray-100 hover:bg-gray-200 text-lg font-bold text-gray-600">-</button>
                      <span class="text-xl font-mono w-6 text-center">{{ tramoA.cintas }}</span>
                      <button @click="tramoA.cintas++" class="w-9 h-9 rounded-lg bg-gray-100 hover:bg-gray-200 text-lg font-bold text-gray-600">+</button>
                    </div>
                  </div>
                </div>
                <!-- Tiempos muertos -->
                <div class="space-y-1">
                  <label class="text-xs text-orange-500 font-medium">Tiempos muertos</label>
                  <div v-for="(tm, i) in tramoA.tiempos_muertos" :key="i" class="flex items-center gap-2 bg-orange-50 rounded px-2 py-1">
                    <span class="text-sm font-mono text-orange-700">-{{ Math.floor(tm/60) }}:{{ String(tm%60).padStart(2,'0') }}</span>
                    <button @click="quitarTM('A', i)" class="text-red-400 hover:text-red-600 text-xs ml-auto">&#x2715;</button>
                  </div>
                  <div class="flex items-center gap-1">
                    <input v-model="tmInputA.min" inputmode="numeric" maxlength="2" placeholder="min" class="w-12 text-center text-sm font-mono border border-gray-300 rounded py-1 outline-none focus:ring-2 focus:ring-orange-400" />
                    <span class="text-gray-400">:</span>
                    <input v-model="tmInputA.seg" inputmode="numeric" maxlength="2" placeholder="seg" class="w-12 text-center text-sm font-mono border border-gray-300 rounded py-1 outline-none focus:ring-2 focus:ring-orange-400" />
                    <button @click="agregarTM('A')" class="text-xs bg-orange-100 hover:bg-orange-200 text-orange-700 px-2 py-1 rounded font-medium">+ TM</button>
                  </div>
                </div>
                <!-- Guardar individual (solo pista simple) -->
                <button v-if="!selectedTripB" @click="guardarTramo('A')"
                  class="w-full bg-green-600 hover:bg-green-700 text-white font-bold py-2.5 rounded-lg transition-colors">
                  Guardar
                </button>
              </div>

              <!-- Pista B (solo doble) -->
              <div v-if="selectedTripB" class="bg-amber-50 rounded-lg p-3 space-y-2 border border-amber-200">
                <p class="text-xs text-amber-600 font-bold uppercase">Pista B — #{{ tripEnPistaB?.numero }}</p>
                <div class="flex items-center gap-1">
                  <input v-model="tramoB.tiempo_min" data-ref="b-min" inputmode="numeric" maxlength="2" placeholder="00"
                    @input="autotab($event, 'b-sec')"
                    class="w-14 text-center text-xl font-mono border border-gray-300 rounded-lg py-2.5 outline-none focus:ring-2 focus:ring-amber-500" />
                  <span class="text-xl text-gray-400 font-bold">:</span>
                  <input v-model="tramoB.tiempo_sec" data-ref="b-sec" inputmode="numeric" maxlength="2" placeholder="00"
                    @input="autotab($event, 'b-cc')"
                    class="w-14 text-center text-xl font-mono border border-gray-300 rounded-lg py-2.5 outline-none focus:ring-2 focus:ring-amber-500" />
                  <span class="text-xl text-gray-400 font-bold">:</span>
                  <input v-model="tramoB.tiempo_ms" data-ref="b-cc" inputmode="numeric" maxlength="2" placeholder="00"
                    class="w-14 text-center text-xl font-mono border border-gray-300 rounded-lg py-2.5 outline-none focus:ring-2 focus:ring-amber-500" />
                </div>
                <div class="grid grid-cols-2 gap-3">
                  <div>
                    <label class="text-xs text-gray-400">Estacas</label>
                    <div class="flex items-center gap-2">
                      <button @click="tramoB.estacas = Math.max(0, tramoB.estacas - 1)" class="w-9 h-9 rounded-lg bg-gray-100 hover:bg-gray-200 text-lg font-bold text-gray-600">-</button>
                      <span class="text-xl font-mono w-6 text-center">{{ tramoB.estacas }}</span>
                      <button @click="tramoB.estacas++" class="w-9 h-9 rounded-lg bg-gray-100 hover:bg-gray-200 text-lg font-bold text-gray-600">+</button>
                    </div>
                  </div>
                  <div>
                    <label class="text-xs text-gray-400">Cintas</label>
                    <div class="flex items-center gap-2">
                      <button @click="tramoB.cintas = Math.max(0, tramoB.cintas - 1)" class="w-9 h-9 rounded-lg bg-gray-100 hover:bg-gray-200 text-lg font-bold text-gray-600">-</button>
                      <span class="text-xl font-mono w-6 text-center">{{ tramoB.cintas }}</span>
                      <button @click="tramoB.cintas++" class="w-9 h-9 rounded-lg bg-gray-100 hover:bg-gray-200 text-lg font-bold text-gray-600">+</button>
                    </div>
                  </div>
                </div>
                <!-- Tiempos muertos B -->
                <div class="space-y-1">
                  <label class="text-xs text-orange-500 font-medium">Tiempos muertos</label>
                  <div v-for="(tm, i) in tramoB.tiempos_muertos" :key="i" class="flex items-center gap-2 bg-orange-50 rounded px-2 py-1">
                    <span class="text-sm font-mono text-orange-700">-{{ Math.floor(tm/60) }}:{{ String(tm%60).padStart(2,'0') }}</span>
                    <button @click="quitarTM('B', i)" class="text-red-400 hover:text-red-600 text-xs ml-auto">&#x2715;</button>
                  </div>
                  <div class="flex items-center gap-1">
                    <input v-model="tmInputB.min" inputmode="numeric" maxlength="2" placeholder="min" class="w-12 text-center text-sm font-mono border border-gray-300 rounded py-1 outline-none focus:ring-2 focus:ring-orange-400" />
                    <span class="text-gray-400">:</span>
                    <input v-model="tmInputB.seg" inputmode="numeric" maxlength="2" placeholder="seg" class="w-12 text-center text-sm font-mono border border-gray-300 rounded py-1 outline-none focus:ring-2 focus:ring-orange-400" />
                    <button @click="agregarTM('B')" class="text-xs bg-orange-100 hover:bg-orange-200 text-orange-700 px-2 py-1 rounded font-medium">+ TM</button>
                  </div>
                </div>
              </div>
            </div>

            <!-- Guardar corrida (pista doble) -->
            <button v-if="selectedTripB && !vueltaCompleta(selectedVuelta)" @click="guardarCorrida"
              class="w-full bg-green-600 hover:bg-green-700 text-white font-bold py-3 rounded-lg text-lg transition-colors">
              Guardar Corrida {{ corridaActual }} — V{{ selectedVuelta === 99 ? 'F' : selectedVuelta }}
            </button>
            <div v-else-if="selectedTripB && vueltaCompleta(selectedVuelta)"
              class="w-full bg-gray-100 text-gray-500 font-bold py-3 rounded-lg text-lg text-center">
              V{{ selectedVuelta === 99 ? 'F' : selectedVuelta }} completa &#x2713;
            </div>

            <!-- Abandonar vuelta (pista doble) -->
            <div v-if="selectedTripB" class="grid grid-cols-2 gap-2">
              <button @click="abandonarVuelta(tripEnPistaA)"
                class="bg-red-100 hover:bg-red-200 text-red-700 font-medium py-2 rounded-lg text-xs transition-colors">
                #{{ tripEnPistaA?.numero }} abandona vuelta
              </button>
              <button @click="abandonarVuelta(tripEnPistaB)"
                class="bg-red-100 hover:bg-red-200 text-red-700 font-medium py-2 rounded-lg text-xs transition-colors">
                #{{ tripEnPistaB?.numero }} abandona vuelta
              </button>
            </div>

            <!-- Anular vuelta (pista simple) -->
            <button v-else @click="anularVuelta"
              class="w-full bg-red-100 hover:bg-red-200 text-red-700 font-medium py-2.5 rounded-lg transition-colors">
              Anular vuelta
            </button>
          </div>
        </template>
      </div>

      <!-- ==================== INSCRIPCION ==================== -->
      <div v-if="tab === 'inscripcion'" class="space-y-4">
          <form @submit.prevent="inscribir" :class="['bg-white rounded-xl p-4 shadow-sm space-y-3', editingTripId ? 'ring-2 ring-blue-400' : '']">
            <p v-if="editingTripId" class="text-xs text-blue-600 font-medium uppercase">Editando tripulacion</p>
            <div class="grid grid-cols-4 gap-2">
              <input v-model="tripForm.numero" placeholder="N°" required class="rounded-lg border border-gray-300 px-3 py-2 outline-none focus:ring-2 focus:ring-blue-500 text-center font-bold" />
              <div class="col-span-3">
                <input v-model="tripForm.nombre" placeholder="Nombre tripulacion" required class="w-full rounded-lg border border-gray-300 px-3 py-2 outline-none focus:ring-2 focus:ring-blue-500" />
              </div>
            </div>
            <div class="grid grid-cols-2 gap-2">
              <input v-model="tripForm.piloto" placeholder="Piloto" required class="rounded-lg border border-gray-300 px-3 py-2 outline-none focus:ring-2 focus:ring-blue-500" />
              <input v-model="tripForm.copiloto" placeholder="Copiloto" class="rounded-lg border border-gray-300 px-3 py-2 outline-none focus:ring-2 focus:ring-blue-500" />
            </div>
            <div class="flex gap-2">
              <button type="submit" :class="['flex-1 text-white font-medium py-2.5 rounded-lg',
                editingTripId ? 'bg-blue-600 hover:bg-blue-700' : 'bg-green-600 hover:bg-green-700']">
                {{ editingTripId ? 'Guardar cambios' : '+ Inscribir' }}
              </button>
              <button v-if="editingTripId" type="button" @click="cancelarEdicion"
                class="px-4 py-2.5 rounded-lg text-gray-500 hover:text-gray-700 bg-gray-100">
                Cancelar
              </button>
            </div>
          </form>

        <!-- Lista de inscriptos -->
        <div class="space-y-1">
          <div v-for="t in tripulaciones" :key="t.id"
            :class="['bg-white rounded-lg p-3 shadow-sm flex items-center justify-between',
              editingTripId === t.id ? 'ring-2 ring-blue-400' : '']">
            <div class="flex items-center gap-3">
              <span class="font-bold text-lg text-gray-700 w-8 text-center">{{ t.numero }}</span>
              <div>
                <p class="font-medium text-gray-800 text-sm">{{ t.nombre }}</p>
                <p class="text-xs text-gray-500">{{ t.piloto }}<span v-if="t.copiloto"> / {{ t.copiloto }}</span></p>
              </div>
            </div>
            <div class="flex gap-2">
              <button @click="editarTrip(t)" class="text-blue-500 hover:text-blue-700 text-xs">Editar</button>
              <button @click="eliminarTrip(t.id)" class="text-red-400 hover:text-red-600 text-xs">Eliminar</button>
            </div>
          </div>
        </div>
      </div>

      <!-- ==================== CONFIG ==================== -->
      <div v-if="tab === 'config' && fecha" class="space-y-4">

        <!-- Banner si esta activa -->
        <div v-if="!fechaEditable" class="bg-yellow-50 border border-yellow-200 rounded-lg p-3 text-center text-sm text-yellow-800">
          Fecha {{ estadoLabel(fecha.estado).toLowerCase() }} &mdash; la configuracion ya no se puede modificar.
        </div>

        <!-- Datos generales -->
        <div class="bg-white rounded-xl p-4 shadow-sm space-y-3">
          <h4 class="text-xs text-gray-400 uppercase tracking-wider">Datos de la fecha</h4>
          <div class="grid grid-cols-2 gap-3 text-sm">
            <div>
              <span class="text-gray-400">Pista:</span> <span class="font-medium">{{ fecha.tipo_pista === 'doble' ? 'Doble (A+B)' : 'Simple' }}</span>
              <span v-if="fecha.tipo_pista === 'simple' && fecha.trazado_simple" class="text-xs text-gray-400 ml-1">
                ({{ fecha.trazado_simple === 'mismo_lugar' ? 'largada=llegada' : 'largada!=llegada' }})
              </span>
            </div>
            <div><span class="text-gray-400">Vueltas:</span> <span class="font-medium">{{ fecha.vueltas_clasificacion }}</span></div>
            <div><span class="text-gray-400">Final:</span> <span class="font-medium">{{ fecha.tiene_final ? `Si, Top ${fecha.finalistas_top}` : 'No' }}</span></div>
            <div><span class="text-gray-400">Estado:</span> <span :class="['font-medium px-1.5 py-0.5 rounded text-xs', estadoColor(fecha.estado)]">{{ estadoLabel(fecha.estado) }}</span></div>
          </div>
        </div>

        <!-- Categorias de la fecha -->
        <div class="bg-white rounded-xl p-4 shadow-sm space-y-3">
          <h4 class="text-xs text-gray-400 uppercase tracking-wider">Categorias de esta fecha</h4>

          <div v-if="!fechaCategorias.length" class="text-sm text-gray-400 py-2">
            No hay categorias. Agrega al menos una para poder activar.
          </div>

          <div v-for="c in fechaCategorias" :key="c.id" class="bg-gray-50 rounded-lg p-3 flex items-center justify-between">
            <div>
              <p class="font-medium text-gray-800 text-sm">{{ c.categoria_catalogo?.nombre || c.nombre }}</p>
              <p class="text-xs text-gray-500">Estaca: {{ c.penal_estaca_seg }}s &middot; Cinta: {{ c.penal_cinta_seg }}s
                <span v-if="c.tipo_pista" class="ml-1">&middot; Pista {{ c.tipo_pista }}</span>
                <span v-else class="ml-1 text-gray-300">&middot; Pista de la fecha</span>
              </p>
            </div>
            <div class="flex items-center gap-2">
              <span :class="['text-[10px] px-2 py-0.5 rounded-full', faseColor(c.fase)]">{{ faseLabel(c.fase) }}</span>
              <button v-if="fechaEditable" @click="removeCategoriaFromFecha(c.id)"
                class="text-red-400 hover:text-red-600 text-xs">Quitar</button>
            </div>
          </div>

          <!-- Agregar categoria (solo si editable) -->
          <template v-if="fechaEditable">
            <div v-if="showAddCat" class="border border-blue-200 rounded-lg p-3 space-y-2 bg-blue-50">
              <select v-model="addCatForm.categoria_catalogo_id" class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm outline-none">
                <option value="">Seleccionar categoria...</option>
                <option v-for="c in catalogo.filter(c => !fechaCategorias.some((fc: any) => (fc.categoria_catalogo?.id || fc.categoria_catalogo_id) === c.id))"
                  :key="c.id" :value="c.id">{{ c.nombre }}</option>
              </select>
              <div class="grid grid-cols-2 gap-2">
                <div>
                  <label class="text-xs text-gray-500">Estaca (seg)</label>
                  <input v-model.number="addCatForm.penal_estaca_seg" type="number" min="0" class="w-full rounded border border-gray-300 px-2 py-1.5 text-sm outline-none" />
                </div>
                <div>
                  <label class="text-xs text-gray-500">Cinta (seg)</label>
                  <input v-model.number="addCatForm.penal_cinta_seg" type="number" min="0" class="w-full rounded border border-gray-300 px-2 py-1.5 text-sm outline-none" />
                </div>
              </div>
              <div>
                <label class="text-xs text-gray-500">Tipo de pista</label>
                <p class="text-[10px] text-gray-400 mb-1">Por defecto la fecha es: {{ fecha?.tipo_pista === 'doble' ? 'DOBLE' : 'SIMPLE' }}</p>
                <select v-model="addCatForm.tipo_pista" class="w-full rounded border border-gray-300 px-2 py-1.5 text-sm outline-none">
                  <option :value="null">Usar el de la fecha</option>
                  <option value="simple">Pista simple (solo A)</option>
                  <option value="doble">Pista doble (A+B)</option>
                </select>
              </div>
              <div class="flex gap-2">
                <button @click="addCategoriaToFecha" :disabled="!addCatForm.categoria_catalogo_id"
                  class="bg-blue-600 text-white px-4 py-1.5 rounded-lg text-sm font-medium disabled:opacity-50">Agregar</button>
                <button @click="showAddCat = false" class="text-gray-500 text-sm">Cancelar</button>
              </div>
            </div>
            <button v-else @click="showAddCat = true; loadCatalogo()"
              class="text-sm text-blue-600 hover:text-blue-800 font-medium">+ Agregar categoria</button>
          </template>
        </div>


        <!-- Acciones -->
        <div class="space-y-2">
          <!-- Antes de activar: configurar y activar -->
          <template v-if="fechaEditable">
            <button v-if="fecha.estado === 'borrador' && fechaCategorias.length > 0" @click="configurarFecha"
              class="w-full bg-blue-600 hover:bg-blue-700 text-white font-bold py-3 rounded-lg">Configurar fecha</button>
            <button v-if="fechaCategorias.length > 0" @click="activarFecha"
              class="w-full bg-green-600 hover:bg-green-700 text-white font-bold py-3 rounded-lg">Activar fecha</button>
          </template>

          <!-- Despues de activar: solo finalizar -->
          <button v-if="fecha.estado === 'activa'" @click="finalizarFecha"
            class="w-full bg-red-600 hover:bg-red-700 text-white font-bold py-3 rounded-lg">Finalizar fecha</button>

          <p v-if="fechaEditable && fechaCategorias.length === 0" class="text-sm text-gray-400 text-center py-2">
            Agrega al menos una categoria para poder activar la fecha.
          </p>

          <!-- Eliminar: solo admin, cualquier estado -->
          <button v-if="auth.isAdmin" @click="eliminarFecha"
            class="w-full bg-red-100 hover:bg-red-200 text-red-700 font-medium py-2.5 rounded-lg mt-4">Eliminar fecha</button>
        </div>
      </div>
    </main>

    <!-- Toast -->
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
