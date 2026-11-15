import { createRouter, createWebHistory } from 'vue-router'
import { useAuthStore } from './stores/auth'

export const router = createRouter({
  history: createWebHistory(import.meta.env.BASE_URL),
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
        { path: 'exchange', name: 'exchange', component: () => import('./views/ExchangeView.vue') },
        { path: 'kaspi', name: 'kaspi', component: () => import('./views/kaspi/KaspiDashboardView.vue') },
        { path: 'kaspi/orders', name: 'kaspi-orders', component: () => import('./views/kaspi/KaspiOrdersView.vue') },
        {
          path: 'kaspi/orders/:id',
          name: 'kaspi-order',
          component: () => import('./views/kaspi/KaspiOrderView.vue'),
          props: true,
        },
        { path: 'kaspi/products', name: 'kaspi-products', component: () => import('./views/kaspi/KaspiProductsView.vue') },
        { path: 'kaspi/settings', name: 'kaspi-settings', component: () => import('./views/kaspi/KaspiSettingsView.vue') },
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
