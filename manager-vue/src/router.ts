import { createRouter, createWebHistory } from 'vue-router'
import { useAuthStore } from './stores/auth'

export const router = createRouter({
  history: createWebHistory('/manager/'),
  routes: [
    { path: '/login', name: 'login', component: () => import('./views/LoginView.vue'), meta: { guest: true } },
    {
      path: '/',
      component: () => import('./views/AppLayout.vue'),
      children: [
        { path: '', name: 'dashboard', component: () => import('./views/DashboardView.vue') },
        { path: 'orders', name: 'orders', component: () => import('./views/OrdersView.vue') },
        { path: 'orders/:id', name: 'order', component: () => import('./views/OrderView.vue'), props: true },
        { path: 'companies', name: 'companies', component: () => import('./views/CompaniesView.vue') },
        { path: 'companies/:id', name: 'company', component: () => import('./views/CompanyView.vue'), props: true },
      ],
    },
    { path: '/:pathMatch(.*)*', redirect: '/' },
  ],
})

router.beforeEach(async (to) => {
  const auth = useAuthStore()
  if (!auth.ready) await auth.load()
  if (!to.meta.guest && !auth.isStaff) return { name: 'login' }
  if (to.meta.guest && auth.isStaff) return { name: 'dashboard' }
})
