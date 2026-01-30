import { ref } from 'vue'
import apiPlatform from '../services/apiPlatform'

export interface LocaleItem {
  code: string
  label: string
  flag: string
}

const locales = ref<LocaleItem[]>([])
const loading = ref(false)
const loaded = ref(false)

export function useLocales() {
  async function loadLocales() {
    if (loaded.value) return

    loading.value = true
    try {
      const response = await apiPlatform.getList('/api/locales')
      locales.value = response.data
      loaded.value = true
    } catch (error) {
      console.error('Failed to load locales:', error)
    } finally {
      loading.value = false
    }
  }

  return {
    locales,
    loading,
    loadLocales
  }
}
