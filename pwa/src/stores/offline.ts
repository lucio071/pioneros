import { defineStore } from 'pinia'
import { ref, computed } from 'vue'
import Dexie from 'dexie'

interface QueuedAction {
  id?: number
  method: string
  path: string
  body: string | null
  createdAt: number
}

const db = new Dexie('pioneros')
db.version(1).stores({
  queue: '++id, createdAt',
})
const queueTable = (db as any).queue as Dexie.Table<QueuedAction, number>

export const useOfflineStore = defineStore('offline', () => {
  const pending = ref<QueuedAction[]>([])
  const draining = ref(false)

  async function loadPending() {
    pending.value = await queueTable.toArray()
  }

  async function enqueue(method: string, path: string, body?: any) {
    const action: QueuedAction = {
      method,
      path,
      body: body ? JSON.stringify(body) : null,
      createdAt: Date.now(),
    }
    await queueTable.add(action)
    await loadPending()
  }

  async function drain() {
    if (draining.value) return
    draining.value = true

    try {
      const items = await queueTable.orderBy('createdAt').toArray()
      for (const item of items) {
        try {
          // Get CSRF cookie first
          await fetch('/sanctum/csrf-cookie', { credentials: 'same-origin' })
          const csrfMatch = document.cookie.match(/XSRF-TOKEN=([^;]+)/)
          const token = csrfMatch ? decodeURIComponent(csrfMatch[1]) : ''

          const res = await fetch(`/api/v1${item.path}`, {
            method: item.method,
            credentials: 'same-origin',
            headers: {
              'Content-Type': 'application/json',
              Accept: 'application/json',
              'X-XSRF-TOKEN': token,
            },
            body: item.body,
          })

          if (res.ok || res.status === 422) {
            // Remove from queue (422 = validation error, no point retrying)
            await queueTable.delete(item.id!)
          } else {
            break // stop draining on server error
          }
        } catch {
          break // network error, stop draining
        }
      }
    } finally {
      draining.value = false
      await loadPending()
    }
  }

  const count = computed(() => pending.value.length)

  return { pending, count, draining, loadPending, enqueue, drain }
})
