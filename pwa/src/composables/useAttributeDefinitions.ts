import { ref } from 'vue'
import apiPlatform from '../services/apiPlatform'

// Shared state across all component instances
const definitions = ref<Record<string, any>>({})
const definitionsList = ref<any[]>([])
const loading = ref(false)
const loaded = ref(false)
let loadPromise: Promise<void> | null = null

async function loadAllDefinitions() {
  if (loaded.value) return
  if (loadPromise) return loadPromise

  loading.value = true
  loadPromise = apiPlatform.getList('/api/attribute_definitions', { itemsPerPage: 500 })
    .then(response => {
      const definitionsMap: Record<string, any> = {}
      for (const definition of response.data) {
        definitionsMap[definition['@id']] = definition
      }
      definitions.value = definitionsMap
      definitionsList.value = response.data
      loaded.value = true
    })
    .catch(error => {
      console.error('Failed to load attribute definitions:', error)
    })
    .finally(() => {
      loading.value = false
      loadPromise = null
    })

  return loadPromise
}

export function useAttributeDefinitions() {
  // Load definitions on first use
  if (!loaded.value && !loading.value) {
    loadAllDefinitions()
  }

  function getDefinition(iri: string): any | null {
    return definitions.value[iri] || null
  }

  function getDefinitionLabel(iri: string): string {
    if (!iri) return '-'
    const definition = definitions.value[iri]
    if (definition) {
      return definition.code || definition.name || iri
    }
    return iri.replace('/api/attribute_definitions/', 'Attr #')
  }

  async function ensureLoaded(): Promise<void> {
    if (loaded.value) return
    await loadAllDefinitions()
  }

  return {
    definitions,
    definitionsList,
    loading,
    loaded,
    loadAllDefinitions,
    ensureLoaded,
    getDefinition,
    getDefinitionLabel
  }
}
