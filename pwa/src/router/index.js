import { createRouter, createWebHistory } from 'vue-router'
import HomeView from '../views/HomeView.vue'
import apiPlatform from '../services/apiPlatform'

const router = createRouter({
    history: createWebHistory(import.meta.env.BASE_URL),
    routes: [
        {
            path: '/login',
            name: 'login',
            component: () => import('../pages/auth/login.vue'),
            meta: { requiresAuth: false }
        },
        {
            path: '/',
            name: 'home',
            component: HomeView,
            meta: { requiresAuth: true }
        },
        {
            path: '/resource/:resource',
            name: 'resource',
            component: () => import('../pages/resource/[resource].vue'),
            meta: { requiresAuth: true }
        },
        {
            path: '/edit/:resource/:id',
            name: 'edit',
            component: () => import('../pages/edit/[resource]/[id].vue'),
            meta: { requiresAuth: true }
        },
        {
            path: '/show/:resource/:id',
            name: 'show',
            component: () => import('../pages/show/[resource]/[id].vue'),
            meta: { requiresAuth: true }
        },
    ]
})

// Navigation guard for authentication
router.beforeEach((to, from, next) => {
    const isAuthenticated = apiPlatform.isAuthenticated()

    if (to.meta.requiresAuth && !isAuthenticated) {
        next({ name: 'login', query: { redirect: to.fullPath } })
    } else if (to.name === 'login' && isAuthenticated) {
        next({ name: 'home' })
    } else {
        next()
    }
})

// Set up unauthorized callback to redirect to login
apiPlatform.setOnUnauthorized(() => {
    router.push({ name: 'login' })
})

export default router
