import { defineStore } from 'pinia'
import apiPlatform from '../services/apiPlatform'

export interface Resource {
    name: string
    title: string
    [key: string]: any
}

interface ResourcesState {
    resources: Resource[]
    loading: boolean
    error: string | null
}

export const useResourcesStore = defineStore('resources', {
    state: (): ResourcesState => ({
        resources: [],
        loading: false,
        error: null
    }),

    actions: {
        async loadResources(force = false) {
            // Don't reload if already loaded (unless forced)
            if (!force && this.resources.length > 0) return

            this.loading = true
            this.error = null

            try {
                await apiPlatform.fetchSchema(force)
                this.resources = apiPlatform.getResources()
            } catch (error: any) {
                this.error = error.message
                console.error('Failed to load resources:', error)
            } finally {
                this.loading = false
            }
        },

        clearResources() {
            this.resources = []
            this.error = null
        },

        getResourceByName(name: string) {
            return this.resources.find(r => r.name === name)
        }
    }
})
