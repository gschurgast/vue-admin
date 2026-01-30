import { ref, computed } from 'vue'
import apiPlatform from '../services/apiPlatform'

// Shared state across all component instances
const options = ref<Record<string, any>>({})
const optionsByDefinition = ref<Record<string, string[]>>({})
const loading = ref(false)
const loaded = ref(false)
let loadPromise: Promise<void> | null = null

async function loadAllOptions() {
  if (loaded.value) return
  if (loadPromise) return loadPromise

  loading.value = true
  loadPromise = apiPlatform.getList('/api/attribute_options', { itemsPerPage: 500 })
    .then(response => {
      const optionsMap: Record<string, any> = {}
      const byDefinition: Record<string, string[]> = {}

      for (const option of response.data) {
        const iri = option['@id']
        optionsMap[iri] = option

        // Group by attribute definition
        const definitionIri = option.attribute?.['@id'] || option.attribute
        if (definitionIri) {
          if (!byDefinition[definitionIri]) {
            byDefinition[definitionIri] = []
          }
          byDefinition[definitionIri].push(iri)
        }
      }

      options.value = optionsMap
      optionsByDefinition.value = byDefinition
      loaded.value = true
    })
    .catch(error => {
      console.error('Failed to load attribute options:', error)
    })
    .finally(() => {
      loading.value = false
      loadPromise = null
    })

  return loadPromise
}

export function useAttributeOptions() {
  // Load options on first use
  if (!loaded.value && !loading.value) {
    loadAllOptions()
  }

  function getOptionLabel(iri: string): string {
    if (!iri) return '-'
    const option = options.value[iri]
    if (option) {
      return option.code || option.name || iri
    }
    // Return IRI without /api/attribute_options/ prefix as fallback
    return iri.replace('/api/attribute_options/', 'Option #')
  }

  function getOption(iri: string): any | null {
    return options.value[iri] || null
  }

  function getOptionsForDefinition(definitionIri: string): any[] {
    const iris = optionsByDefinition.value[definitionIri] || []
    return iris.map(iri => options.value[iri]).filter(Boolean)
  }

  async function ensureLoaded(): Promise<void> {
    if (loaded.value) return
    await loadAllOptions()
  }

  return {
    options,
    loading,
    loaded,
    loadAllOptions,
    ensureLoaded,
    getOptionLabel,
    getOption,
    getOptionsForDefinition
  }
}
