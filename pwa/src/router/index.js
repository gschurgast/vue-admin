import { createRouter, createWebHistory } from 'vue-router'
import HomeView from '../views/HomeView.vue'

const router = createRouter({
    history: createWebHistory(import.meta.env.BASE_URL),
    routes: [
        {
            path: '/',
            name: 'home',
            component: HomeView
        },
        {
            path: '/resource/:resource',
            name: 'resource',
            component: () => import('../pages/resource/[resource].vue')
        },
        {
            path: '/edit/:resource/:id',
            name: 'edit',
            component: () => import('../pages/edit/[resource]/[id].vue')
        },
        {
            path: '/show/:resource/:id',
            name: 'show',
            component: () => import('../pages/show/[resource]/[id].vue')
        }
    ]
})

export default router