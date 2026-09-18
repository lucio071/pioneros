<script setup lang="ts">
import { computed } from 'vue'
import { useRoute } from 'vue-router'
import SponsorBanner from './components/SponsorBanner.vue'

const route = useRoute()
const isAdmin = computed(() => route.path.startsWith('/admin') || route.path === '/login')
</script>

<template>
  <SponsorBanner v-if="!isAdmin" />
  <router-view />
</template>
