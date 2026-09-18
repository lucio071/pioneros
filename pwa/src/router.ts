import { createRouter, createWebHistory } from 'vue-router'
import { useAuthStore } from './stores/auth'

const router = createRouter({
  history: createWebHistory(),
  routes: [
    {
      path: '/',
      component: () => import('./views/public/HomeView.vue'),
    },
    {
      path: '/vivo',
      component: () => import('./views/public/VivoView.vue'),
    },
    {
      path: '/elegir-tripulacion',
      component: () => import('./views/public/ElegirTripulacionView.vue'),
    },
    {
      path: '/resultados',
      component: () => import('./views/public/ResultadosView.vue'),
    },
    {
      path: '/login',
      component: () => import('./views/admin/LoginView.vue'),
    },
    {
      path: '/admin',
      component: () => import('./views/admin/DashboardView.vue'),
      meta: { requiresAuth: true },
    },
    {
      path: '/admin/fecha/:id',
      component: () => import('./views/admin/FechaDetailView.vue'),
      meta: { requiresAuth: true },
    },
    {
      path: '/admin/dispositivos',
      component: () => import('./views/admin/DispositivosView.vue'),
      meta: { requiresAuth: true },
    },
    {
      path: '/admin/campeonatos',
      component: () => import('./views/admin/CampeonatosListView.vue'),
      meta: { requiresAuth: true },
    },
    {
      path: '/admin/campeonatos/:id',
      component: () => import('./views/admin/CampeonatoDetailView.vue'),
      meta: { requiresAuth: true },
    },
    {
      path: '/campeonato/:id?',
      component: () => import('./views/public/CampeonatoView.vue'),
    },
    {
      path: '/sponsors',
      component: () => import('./views/public/SponsorsView.vue'),
    },
    {
      path: '/admin/patrocinadores',
      component: () => import('./views/admin/PatrocinadoresListView.vue'),
      meta: { requiresAuth: true },
    },
    {
      path: '/admin/patrocinadores/:id',
      component: () => import('./views/admin/PatrocinadorDetailView.vue'),
      meta: { requiresAuth: true },
    },
  ],
})

router.beforeEach(async (to) => {
  if (to.meta.requiresAuth) {
    const auth = useAuthStore()
    if (!auth.user) {
      await auth.checkAuth()
      if (!auth.user) return '/login'
    }
  }
})

export default router
