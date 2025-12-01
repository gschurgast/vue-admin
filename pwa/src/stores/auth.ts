import { defineStore } from 'pinia'
import { ref, computed } from 'vue'
import apiPlatform from '../services/apiPlatform'

export interface User {
    id?: number
    email: string
    firstName?: string
    lastName?: string
    picture?: string
    roles: string[]
}

export const useAuthStore = defineStore('auth', () => {
    const token = ref<string | null>(localStorage.getItem('auth_token'))
    const user = ref<User | null>(null)
    const loading = ref(false)
    const error = ref<string | null>(null)

    // Initialize user from localStorage
    const storedUser = localStorage.getItem('auth_user')
    if (storedUser) {
        try {
            user.value = JSON.parse(storedUser)
        } catch {
            localStorage.removeItem('auth_user')
        }
    }

    const isAuthenticated = computed(() => !!token.value)
    const isAdmin = computed(() => user.value?.roles?.includes('ROLE_ADMIN') ?? false)
    const fullName = computed(() => {
        if (!user.value) return ''
        const name = [user.value.firstName, user.value.lastName].filter(Boolean).join(' ')
        return name || user.value.email
    })
    const pictureUrl = computed(() => {
        if (!user.value?.picture) return null
        const baseUrl = import.meta.env.VITE_API_URL || 'http://localhost:8080'
        return `${baseUrl}${user.value.picture}`
    })

    async function login(email: string, password: string): Promise<boolean> {
        loading.value = true
        error.value = null

        try {
            const response = await apiPlatform.login(email, password)
            token.value = response.token

            // Parse user data from JWT token
            const payload = parseJwt(response.token)
            user.value = {
                id: payload.id,
                email: payload.username || email,
                firstName: payload.firstName,
                lastName: payload.lastName,
                picture: payload.picture,
                roles: payload.roles || ['ROLE_USER']
            }
            localStorage.setItem('auth_user', JSON.stringify(user.value))

            return true
        } catch (e: any) {
            error.value = e.response?.data?.message || 'Invalid credentials'
            return false
        } finally {
            loading.value = false
        }
    }

    async function fetchProfile(): Promise<void> {
        if (!user.value?.id) return

        try {
            const profile = await apiPlatform.getUser(user.value.id)
            user.value = {
                id: profile.id,
                email: profile.email,
                firstName: profile.firstName,
                lastName: profile.lastName,
                picture: profile.picture,
                roles: profile.roles || ['ROLE_USER'],
            }
            localStorage.setItem('auth_user', JSON.stringify(user.value))
        } catch (e) {
            console.error('Failed to fetch profile:', e)
        }
    }

    function logout() {
        apiPlatform.logout()
        token.value = null
        user.value = null
    }

    function parseJwt(token: string): any {
        try {
            const base64Url = token.split('.')[1]
            const base64 = base64Url.replace(/-/g, '+').replace(/_/g, '/')
            const jsonPayload = decodeURIComponent(
                atob(base64)
                    .split('')
                    .map((c) => '%' + ('00' + c.charCodeAt(0).toString(16)).slice(-2))
                    .join('')
            )
            return JSON.parse(jsonPayload)
        } catch {
            return {}
        }
    }

    // Check if token is expired
    function isTokenExpired(): boolean {
        if (!token.value) return true
        const payload = parseJwt(token.value)
        if (!payload.exp) return false
        return Date.now() >= payload.exp * 1000
    }

    // Initialize: check if token is valid
    function checkAuth() {
        if (token.value && isTokenExpired()) {
            logout()
        }
    }

    return {
        token,
        user,
        loading,
        error,
        isAuthenticated,
        isAdmin,
        fullName,
        pictureUrl,
        login,
        logout,
        checkAuth,
        isTokenExpired,
        fetchProfile,
    }
})
