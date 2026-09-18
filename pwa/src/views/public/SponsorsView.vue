<script setup lang="ts">
import { usePatrocinadores } from '../../composables/usePatrocinadores'

const { principales, secundarios } = usePatrocinadores()
</script>

<template>
  <div class="min-h-screen bg-gray-50">
    <header class="bg-[var(--brand-dark)] text-white px-4 py-4">
      <div class="max-w-2xl mx-auto flex items-center gap-3">
        <router-link to="/" class="text-white/60 hover:text-white">&larr;</router-link>
        <h1 class="font-bold">Patrocinadores</h1>
      </div>
    </header>

    <main class="max-w-2xl mx-auto px-4 py-6 space-y-6">
      <!-- Principales -->
      <div v-if="principales.length">
        <h2 class="text-xs text-gray-400 uppercase tracking-wider mb-3">Patrocinadores Principales</h2>
        <div class="space-y-3">
          <a v-for="p in principales" :key="p.id"
            :href="p.website" target="_blank" rel="noopener"
            class="block bg-white rounded-xl p-6 shadow-sm hover:shadow-md transition-shadow text-center">
            <img v-if="p.logo_url" :src="p.logo_url" :alt="p.nombre_comercial"
              class="h-20 mx-auto object-contain mb-3" loading="lazy" />
            <h3 class="font-bold text-gray-800 text-lg">{{ p.nombre_comercial }}</h3>
            <p v-if="p.descripcion_corta" class="text-sm text-gray-500 mt-1">{{ p.descripcion_corta }}</p>
          </a>
        </div>
      </div>

      <!-- Secundarios -->
      <div v-if="secundarios.length">
        <h2 class="text-xs text-gray-400 uppercase tracking-wider mb-3">Auspiciantes</h2>
        <div class="grid grid-cols-2 sm:grid-cols-3 gap-3">
          <a v-for="p in secundarios" :key="p.id"
            :href="p.website" target="_blank" rel="noopener"
            class="bg-white rounded-lg p-4 shadow-sm hover:shadow-md transition-shadow text-center">
            <img v-if="p.logo_url" :src="p.logo_url" :alt="p.nombre_comercial"
              class="h-12 mx-auto object-contain mb-2" loading="lazy" />
            <p class="text-xs text-gray-600 font-medium truncate">{{ p.nombre_comercial }}</p>
          </a>
        </div>
      </div>

      <div v-if="!principales.length && !secundarios.length" class="text-center py-12 text-gray-400">
        No hay patrocinadores activos
      </div>
    </main>

    <nav class="fixed bottom-0 inset-x-0 bg-white border-t border-gray-200 px-4 py-2 flex justify-around max-w-2xl mx-auto">
      <router-link to="/" class="text-gray-400 hover:text-gray-600 text-xs text-center py-1">Inicio</router-link>
      <router-link to="/vivo" class="text-gray-400 hover:text-gray-600 text-xs text-center py-1">En vivo</router-link>
      <router-link to="/sponsors" class="text-[var(--brand-color)] text-xs text-center py-1 font-medium">Sponsors</router-link>
    </nav>
  </div>
</template>
