<template>
  <div>
    <!-- Product Field with search-as-you-type -->
    <v-autocomplete
      v-model="productValue"
      :label="label"
      :items="products"
      item-title="skuRoot"
      item-value="@id"
      :loading="loadingProducts || loadingProduct"
      :required="required"
      :error-messages="errorMessages"
      clearable
      class="mb-4"
      @update:search="onProductSearch"
      @update:model-value="onProductChange"
    />

    <!-- Variant Field - only shown when product is selected -->
    <v-autocomplete
      v-if="productValue"
      v-model="variantValue"
      label="Variant"
      :items="variants"
      item-title="sku"
      item-value="@id"
      :loading="loadingVariants"
      clearable
      @update:model-value="onVariantChange"
    />
  </div>
</template>

<script setup lang="ts">
import { ref, watch, onMounted } from 'vue'
import apiPlatform from '../../services/apiPlatform'

interface Props {
  modelValue: string | null | undefined
  formData?: Record<string, any>
  label?: string
  required?: boolean
  errorMessages?: string[]
  field?: any
}

const props = withDefaults(defineProps<Props>(), {
  label: 'Product',
  formData: () => ({}),
  required: false,
  errorMessages: () => []
})

const emit = defineEmits<{
  'update:modelValue': [value: string | null]
  'update:formData': [value: Record<string, any>]
}>()

const products = ref<any[]>([])
const variants = ref<any[]>([])
const loadingProducts = ref(false)
const loadingVariants = ref(false)
const loadingProduct = ref(false)

// Extract IRI from value (can be string IRI or object with @id)
function extractIri(value: any): string | null {
  if (!value) return null
  if (typeof value === 'string') return value
  if (typeof value === 'object' && value['@id']) return value['@id']
  return null
}

const productValue = ref<string | null>(null)
const variantValue = ref<string | null>(null)
const initialized = ref(false)

let searchTimeout: ReturnType<typeof setTimeout> | null = null

async function searchProducts(query: string) {
  loadingProducts.value = true
  try {
    const params: Record<string, any> = { itemsPerPage: 20 }
    if (query) {
      params.skuRoot = query
    }
    const response = await apiPlatform.getList('/api/products', params)
    products.value = response.data
  } catch (error) {
    console.error('Failed to search products:', error)
  } finally {
    loadingProducts.value = false
  }
}

function onProductSearch(query: string) {
  if (searchTimeout) clearTimeout(searchTimeout)
  searchTimeout = setTimeout(() => {
    searchProducts(query)
  }, 300)
}

async function loadVariants(productIri: string) {
  loadingVariants.value = true
  variants.value = []
  try {
    const response = await apiPlatform.getList('/api/product_variants', {
      product: productIri,
      itemsPerPage: 100
    })
    variants.value = response.data
  } catch (error) {
    console.error('Failed to load variants:', error)
  } finally {
    loadingVariants.value = false
  }
}

function onProductChange(value: string | null) {
  emit('update:modelValue', value)
  // Clear variant when product changes
  variantValue.value = null
  emit('update:formData', { variant: null })

  if (value) {
    loadVariants(value)
  } else {
    variants.value = []
  }
}

function onVariantChange(value: string | null) {
  emit('update:formData', { variant: value })
}

// Load product by IRI to add to products list for display
async function loadProductByIri(iri: string) {
  loadingProduct.value = true
  try {
    const product = await apiPlatform.getByIri(iri)
    // Add to products list if not already present
    if (!products.value.find(p => p['@id'] === iri)) {
      products.value = [product, ...products.value]
    }
  } catch (error) {
    console.error('Failed to load product:', error)
  } finally {
    loadingProduct.value = false
  }
}

// Sync with external modelValue changes and load variants for existing records
watch(() => props.modelValue, async (value) => {
  const iri = extractIri(value)
  if (iri !== productValue.value) {
    productValue.value = iri
  }
  if (iri) {
    // Load product data to display skuRoot
    if (!products.value.find(p => p['@id'] === iri)) {
      await loadProductByIri(iri)
    }
    if (variants.value.length === 0) {
      await loadVariants(iri)
    }
    initialized.value = true
  }
}, { immediate: true })

// Sync variant from formData
watch(() => props.formData?.variant, (value) => {
  const iri = extractIri(value)
  if (iri !== variantValue.value) {
    variantValue.value = iri
  }
}, { immediate: true })
</script>
