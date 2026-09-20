import { createRouter, createWebHashHistory } from 'vue-router';
import Login from './views/Login.vue';
import Layout from './views/Layout.vue';
import Dashboard from './views/Dashboard.vue';
import Listings from './views/Listings.vue';
import Products from './views/Products.vue';
import Designs from './views/Designs.vue';

const routes = [
  { path: '/login', component: Login },
  {
    path: '/', component: Layout,
    children: [
      { path: '', redirect: '/dashboard' },
      { path: 'dashboard', component: Dashboard, meta: { title: '概览' } },
      { path: 'listings', component: Listings, meta: { title: '上架(路标)' } },
      { path: 'products', component: Products, meta: { title: '商品' } },
      { path: 'designs', component: Designs, meta: { title: '设计' } },
    ],
  },
];

const router = createRouter({ history: createWebHashHistory(), routes });

router.beforeEach((to) => {
  if (to.path !== '/login' && !localStorage.getItem('hub_token')) return '/login';
  if (to.path === '/login' && localStorage.getItem('hub_token')) return '/dashboard';
  return true;
});

export default router;
