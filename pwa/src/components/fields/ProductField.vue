<template>
  <v-autocomplete
    v-model="productValue"
    :label="label"
    :items="products"
    item-title="skuRoot"
    item-value="@id"
    :loading="loading"
    :required="required"
    :error-messages="errorMessages"
    clearable
    @update:search="onSearch"
    @update:model-value="onChange"
  />
</template>

<script setup lang="ts">
import { ref, watch, onMounted } from 'vue'
import apiPlatform from '../../services/apiPlatform'

interface Props {
  modelValue: string | null | undefined
  label?: string
  required?: boolean
  errorMessages?: string[]
  field?: any
}

const props = withDefaults(defineProps<Props>(), {
  label: 'Product',
  required: false,
  errorMessages: () => []
})

const emit = defineEmits<{
  'update:modelValue': [value: string | null]
}>()

const products = ref<any[]>([])
const loading = ref(false)
const productValue = ref<string | null>(null)

let searchTimeout: ReturnType<typeof setTimeout> | null = null

// Extract IRI from value (can be string IRI or object with @id)
function extractIri(value: any): string | null {
  if (!value) return null
  if (typeof value === 'string') return value
  if (typeof value === 'object' && value['@id']) return value['@id']
  return null
}

async function loadInitialProduct() {
  const iri = extractIri(props.modelValue)
  if (!iri) {
    await searchProducts('')
    return
  }

  loading.value = true
  try {
    // Load the selected product first
    const product = await apiPlatform.getByIri(iri)
    products.value = [product]
    productValue.value = iri

    // Then load more products for the dropdown
    const response = await apiPlatform.getList('/api/products', { itemsPerPage: 20 })
    // Merge without duplicates
    const existingIds = new Set(products.value.map(p => p['@id']))
    const newProducts = response.data.filter((p: any) => !existingIds.has(p['@id']))
    products.value = [...products.value, ...newProducts]
  } catch (error) {
    console.error('Failed to load initial product:', error)
    await searchProducts('')
  } finally {
    loading.value = false
  }
}

async function searchProducts(query: string) {
  loading.value = true
  try {
    const params: Record<string, any> = { itemsPerPage: 20 }
    if (query) {
      params.skuRoot = query
    }
    const response = await apiPlatform.getList('/api/products', params)

    // Keep the currently selected product in the list
    if (productValue.value) {
      const currentProduct = products.value.find(p => p['@id'] === productValue.value)
      if (currentProduct) {
        const existingIds = new Set(response.data.map((p: any) => p['@id']))
        if (!existingIds.has(currentProduct['@id'])) {
          response.data.unshift(currentProduct)
        }
      }
    }

    products.value = response.data
  } catch (error) {
    console.error('Failed to search products:', error)
  } finally {
    loading.value = false
  }
}

function onSearch(query: string) {
  if (searchTimeout) clearTimeout(searchTimeout)
  searchTimeout = setTimeout(() => {
    searchProducts(query)
  }, 300)
}

function onChange(value: string | null) {
  emit('update:modelValue', value)
}

// Sync with external modelValue changes
watch(() => props.modelValue, (value) => {
  const iri = extractIri(value)
  if (iri !== productValue.value) {
    productValue.value = iri
    if (iri && !products.value.find(p => p['@id'] === iri)) {
      loadInitialProduct()
    }
  }
})

onMounted(() => {
  loadInitialProduct()
})
</script>
