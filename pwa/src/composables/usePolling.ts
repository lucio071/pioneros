import { ref, onMounted, onUnmounted, watch } from 'vue'
import { useCarreraStore } from '../stores/carrera'

export function usePolling() {
  const store = useCarreraStore()
  let timer: ReturnType<typeof setTimeout> | null = null
  const visible = ref(!document.hidden)

  function onVisibility() {
    visible.value = !document.hidden
  }

  async function poll() {
    if (!visible.value) return

    await store.fetchRanking()

    timer = setTimeout(poll, store.pollInterval)
  }

  function start() {
    if (timer) clearTimeout(timer)
    timer = setTimeout(poll, 0)
  }

  function stop() {
    if (timer) {
      clearTimeout(timer)
      timer = null
    }
  }

  watch(visible, (v) => {
    if (v) {
      store.pollInterval = 3000 // reset backoff
      start()
    } else {
      stop()
    }
  })

  onMounted(() => {
    document.addEventListener('visibilitychange', onVisibility)
    start()
  })

  onUnmounted(() => {
    document.removeEventListener('visibilitychange', onVisibility)
    stop()
  })
}
