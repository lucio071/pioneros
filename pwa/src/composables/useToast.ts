import { ref } from 'vue'

const toast = ref({ show: false, msg: '', type: 'success' as 'success' | 'error' })
let toastTimer: ReturnType<typeof setTimeout> | null = null

export function useToast() {
  function showToast(msg: string, type: 'success' | 'error' = 'success') {
    if (toastTimer) clearTimeout(toastTimer)
    toast.value = { show: true, msg, type }
    toastTimer = setTimeout(() => { toast.value.show = false }, 4000)
  }
  return { toast, showToast }
}
