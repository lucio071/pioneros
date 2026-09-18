import { defineStore } from 'pinia'
import { ref, computed } from 'vue'
import { apiFetch } from '../api'
import type { Fecha, FechaCategoriaResumen, RankingData } from '../types'

export const useCarreraStore = defineStore('carrera', () => {
  const fechaActiva = ref<Fecha | null>(null)
  const categorias = ref<FechaCategoriaResumen[]>([])
  const selectedCategoriaId = ref<string | null>(null)
  const ranking = ref<RankingData | null>(null)
  const lastEtag = ref('')
  const lastUpdate = ref(Date.now())
  const online = ref(true)
  const pollInterval = ref(3000)

  const miTripulacionId = ref<string | null>(
    localStorage.getItem('miTripulacionId'),
  )

  const selectedCategoria = computed(() =>
    categorias.value.find((c) => c.id === selectedCategoriaId.value) || null,
  )

  const miTripulacion = computed(() => {
    if (!miTripulacionId.value || !ranking.value) return null
    return (
      ranking.value.ranking.find((t) => t.id === miTripulacionId.value) ||
      ranking.value.en_curso.find((t) => t.id === miTripulacionId.value) ||
      ranking.value.dnf.find((t) => t.id === miTripulacionId.value) ||
      null
    )
  })

  function setMiTripulacion(id: string | null) {
    miTripulacionId.value = id
    if (id) localStorage.setItem('miTripulacionId', id)
    else localStorage.removeItem('miTripulacionId')
  }

  function setCategoria(id: string) {
    selectedCategoriaId.value = id
    lastEtag.value = '' // reset etag to force refetch
    ranking.value = null
  }

  async function fetchFechaActiva() {
    try {
      const res = await apiFetch<Fecha | null>('/public/fechas/activa')
      fechaActiva.value = res.data
      if (res.data?.categorias) {
        categorias.value = res.data.categorias
        // Auto-select first category if none selected
        if (!selectedCategoriaId.value && res.data.categorias.length > 0) {
          selectedCategoriaId.value = res.data.categorias[0].id
        }
      }
      online.value = true
    } catch {
      online.value = false
    }
  }

  async function fetchRanking() {
    if (!fechaActiva.value || !selectedCategoriaId.value) return

    try {
      const res = await apiFetch<RankingData>(
        `/public/fechas/${fechaActiva.value.id}/categorias/${selectedCategoriaId.value}/ranking`,
        { etag: lastEtag.value },
      )

      if (!res.notModified && res.data) {
        ranking.value = res.data
        // Update categorias phase from ranking response
        if (res.data.categoria) {
          const cat = categorias.value.find((c) => c.id === selectedCategoriaId.value)
          if (cat) cat.fase = res.data.categoria.fase as any
        }
        lastEtag.value = res.etag || ''
      }

      lastUpdate.value = Date.now()
      online.value = true
      pollInterval.value = 3000
    } catch {
      online.value = false
      pollInterval.value = Math.min(pollInterval.value * 2, 60000)
    }
  }

  return {
    fechaActiva,
    categorias,
    selectedCategoriaId,
    selectedCategoria,
    ranking,
    lastUpdate,
    online,
    pollInterval,
    miTripulacionId,
    miTripulacion,
    setMiTripulacion,
    setCategoria,
    fetchFechaActiva,
    fetchRanking,
  }
})
