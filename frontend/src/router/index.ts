import { createRouter, createWebHistory } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import EinkaufslisteView from '../views/EinkaufslisteView.vue'
import HaushaltView from '../views/HaushaltView.vue'
import LoginView from '../views/LoginView.vue'
import RegisterView from '../views/RegisterView.vue'
import RezeptBearbeitenView from '../views/RezeptBearbeitenView.vue'
import RezeptDetailView from '../views/RezeptDetailView.vue'
import RezepteView from '../views/RezepteView.vue'
import RezeptImportView from '../views/RezeptImportView.vue'
import WochenplanView from '../views/WochenplanView.vue'

declare module 'vue-router' {
  interface RouteMeta {
    requiresAuth?: boolean
    guestOnly?: boolean
  }
}

const router = createRouter({
  history: createWebHistory(import.meta.env.BASE_URL),
  routes: [
    {
      path: '/',
      name: 'wochenplan',
      component: WochenplanView,
      meta: { requiresAuth: true },
    },
    {
      path: '/rezepte',
      name: 'recipes',
      component: RezepteView,
      meta: { requiresAuth: true },
    },
    {
      path: '/rezepte/import',
      name: 'recipe-import',
      component: RezeptImportView,
      meta: { requiresAuth: true },
    },
    {
      path: '/rezepte/:id',
      name: 'recipe-detail',
      component: RezeptDetailView,
      meta: { requiresAuth: true },
    },
    {
      path: '/rezepte/:id/bearbeiten',
      name: 'recipe-edit',
      component: RezeptBearbeitenView,
      meta: { requiresAuth: true },
    },
    {
      path: '/einkaufsliste',
      name: 'shopping-list',
      component: EinkaufslisteView,
      meta: { requiresAuth: true },
    },
    {
      path: '/haushalt',
      name: 'household',
      component: HaushaltView,
      meta: { requiresAuth: true },
    },
    {
      path: '/login',
      name: 'login',
      component: LoginView,
      meta: { guestOnly: true },
    },
    {
      path: '/register',
      name: 'register',
      component: RegisterView,
      meta: { guestOnly: true },
    },
  ],
})

router.beforeEach(async (to) => {
  const auth = useAuthStore()

  if (auth.token && !auth.user) {
    try {
      await auth.fetchUser()
    } catch {
      // An invalid/expired token is cleared by the response interceptor.
    }
  }

  if (to.meta.requiresAuth && !auth.isAuthenticated) {
    return { name: 'login' }
  }

  if (to.meta.guestOnly && auth.isAuthenticated) {
    return { name: 'wochenplan' }
  }
})

export default router
