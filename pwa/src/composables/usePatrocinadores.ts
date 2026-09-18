import { ref, computed, onMounted } from 'vue'
import { apiFetch } from '../api'

const patrocinadores = ref<any[]>([])
const loaded = ref(false)

export function usePatrocinadores() {
  async function fetchPatrocinadores() {
    if (loaded.value) return
    try {
      const res = await apiFetch<any[]>('/public/patrocinadores')
      patrocinadores.value = res.data || []
      loaded.value = true
    } catch {
      patrocinadores.value = []
    }
  }

  const principales = computed(() =>
    patrocinadores.value.filter(p => p.nivel === 'principal')
  )

  const secundarios = computed(() =>
    patrocinadores.value.filter(p => p.nivel === 'secundario')
  )

  onMounted(fetchPatrocinadores)

  return { patrocinadores, principales, secundarios, fetchPatrocinadores }
}
