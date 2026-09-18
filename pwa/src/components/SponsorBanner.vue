<script setup lang="ts">
import { ref, onMounted, onUnmounted, computed } from 'vue'
import { apiFetch } from '../api'

const patrocinadores = ref<any[]>([])
const currentIndex = ref(0)
let timer: ReturnType<typeof setInterval> | null = null

const current = computed(() => patrocinadores.value[currentIndex.value] || null)

onMounted(async () => {
  try {
    const res = await apiFetch<any[]>('/public/patrocinadores')
    patrocinadores.value = res.data || []
  } catch {}

  if (patrocinadores.value.length > 1) {
    timer = setInterval(() => {
      currentIndex.value = (currentIndex.value + 1) % patrocinadores.value.length
    }, 4000)
  }
})

onUnmounted(() => {
  if (timer) clearInterval(timer)
})
</script>

<template>
  <div v-if="patrocinadores.length" class="bg-white border-b border-gray-200 py-2 px-4 overflow-hidden">
    <div class="max-w-2xl mx-auto flex items-center justify-center gap-3">
      <transition name="fade" mode="out-in">
        <a v-if="current" :key="current.id" :href="current.website" target="_blank" rel="noopener"
          class="flex items-center gap-3 transition-opacity">
          <img v-if="current.logo_url" :src="current.logo_url" :alt="current.nombre_comercial"
            class="h-10 object-contain" loading="lazy" />
          <span class="text-xs text-gray-500 hidden sm:inline">{{ current.nombre_comercial }}</span>
        </a>
      </transition>

      <!-- Dots -->
      <div v-if="patrocinadores.length > 1" class="flex gap-1 ml-3">
        <span v-for="(_, i) in patrocinadores" :key="i"
          :class="['w-1.5 h-1.5 rounded-full transition-colors', i === currentIndex ? 'bg-gray-400' : 'bg-gray-200']">
        </span>
      </div>
    </div>
  </div>
</template>

<style scoped>
.fade-enter-active, .fade-leave-active {
  transition: opacity 0.3s ease;
}
.fade-enter-from, .fade-leave-to {
  opacity: 0;
}
</style>
