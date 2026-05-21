<template>
  <v-row>
    <!-- Left side: Form -->
    <v-col cols="12" md="6">
      <v-card>
        <v-card-title class="d-flex align-center">
          <span>Variant Details</span>
          <v-spacer />
          <v-btn
            color="primary"
            size="small"
            variant="text"
            style="font-size: 0.75rem;"
            :loading="generatingContent"
            :disabled="!variantIri || generatingContent"
            @click="generateAiContent"
          >
            <v-icon start size="small">mdi-creation</v-icon>
            Generate Description
          </v-btn>
        </v-card-title>
        <v-card-text>
          <div class="d-flex">
            <!-- Variant Image -->
            <div class="flex-shrink-0 mr-4" style="width: 150px;">
              <v-img
                v-if="variantImage"
                :src="variantImage"
                width="150"
                max-height="200"
                cover
                rounded="lg"
                class="elevation-2"
              />
              <v-sheet
                v-else
                width="150"
                height="150"
                rounded="lg"
                color="grey-lighten-3"
                class="d-flex align-center justify-center"
              >
                <v-icon size="48" color="grey-lighten-1">mdi-image-off</v-icon>
              </v-sheet>
            </div>

            <!-- Form -->
            <div class="flex-grow-1">
              <ResourceForm
                v-model="localFormData"
                :fields="fields"
                :custom-components="customComponents"
                :relation-data="relationData"
                :loading-relations="loadingRelations"
                :field-errors="fieldErrors"
              />
            </div>
          </div>
        </v-card-text>
      </v-card>

      <ProductVariantsList
        :product-iri="productIri"
        :current-variant-id="localFormData.id"
        class="mt-4"
      />
    </v-col>

    <!-- Right side: Attribute Values Panel -->
    <v-col cols="12" md="6">
      <ProductAttributeValuesPanel
        ref="attributeValuesPanel"
        :product-iri="productIri"
        :variant-iri="variantIri"
        :is-variant="true"
        @attributes-loaded="onAttributesLoaded"
      />
    </v-col>
  </v-row>
</template>

<script setup lang="ts">
import { ref, computed, watch } from 'vue'
import ResourceForm from '../../resource/ResourceForm.vue'
import ProductAttributeValuesPanel from '../../product/edit/ProductAttributeValuesPanel.vue'
import ProductVariantsList from '../../product/edit/ProductVariantsList.vue'
import apiPlatform from '../../../services/apiPlatform'

interface Props {
  formData: Record<string, any>
  fields: Array<any>
  customComponents?: Record<string, any>
  relationData?: Record<string, any>
  loadingRelations?: Record<string, boolean>
  fieldErrors?: Record<string, string[]>
  resourceName?: string
}

const props = withDefaults(defineProps<Props>(), {
  customComponents: () => ({}),
  relationData: () => ({}),
  loadingRelations: () => ({}),
  fieldErrors: () => ({})
})

const emit = defineEmits<{
  'update:formData': [value: Record<string, any>]
  'content-generated': [data: { content: string, attributeValueId: string }]
}>()

const localFormData = ref({ ...props.formData })
const attributeValuesPanel = ref<InstanceType<typeof ProductAttributeValuesPanel> | null>(null)
const generatingContent = ref(false)
const variantImage = ref<string | null>(null)

const productIri = computed(() => {
  // Get the product IRI from formData
  const product = localFormData.value.product
  if (!product) return null
  if (typeof product === 'string') return product
  if (product['@id']) return product['@id']
  return null
})

const variantIri = computed(() => {
  // Get the variant IRI from formData
  if (localFormData.value['@id']) {
    return localFormData.value['@id']
  }
  if (localFormData.value.id) {
    return `/api/product_variants/${localFormData.value.id}`
  }
  return null
})

// Watch for external formData changes
watch(() => props.formData, (newValue) => {
  if (JSON.stringify(newValue) !== JSON.stringify(localFormData.value)) {
    localFormData.value = { ...newValue }
  }
}, { deep: true })

// Emit changes to parent
watch(localFormData, (newValue) => {
  if (JSON.stringify(newValue) !== JSON.stringify(props.formData)) {
    emit('update:formData', { ...newValue })
  }
}, { deep: true })

// Handle attributes loaded event to extract image
function onAttributesLoaded(attributes: { productAttributes: any[], variantAttributes: any[] }) {
  // First check variant attributes for image, then fall back to product attributes
  const allAttributes = [...(attributes.variantAttributes || []), ...(attributes.productAttributes || [])]
  const imageAttr = allAttributes.find(attr =>
    attr.attributeDefinition?.type === 'media' &&
    (attr.attributeDefinition?.code === 'image' || attr.attributeDefinition?.code === 'picture')
  )
  variantImage.value = imageAttr?.value || null
}

// Generate AI content for variant description
async function generateAiContent() {
  if (!variantIri.value) return

  const variantId = localFormData.value.id
  if (!variantId) return

  generatingContent.value = true
  try {
    const response = await apiPlatform.client.post(`/api/product_variants/${variantId}/generate-content`, {
      locale: 'fr_FR'
    })

    const { generatedContent, attributeValueId } = response.data

    // Emit event to parent
    emit('content-generated', { content: generatedContent, attributeValueId })

    // Reload attribute values to show the new description
    if (attributeValuesPanel.value) {
      await attributeValuesPanel.value.reload()
    }
  } catch (error) {
    console.error('Failed to generate AI content:', error)
  } finally {
    generatingContent.value = false
  }
}

// Public method to save attribute values
async function saveAttributeValues(): Promise<void> {
  if (attributeValuesPanel.value) {
    await attributeValuesPanel.value.saveChanges()
  }
}

// Check if there are pending attribute value changes
function hasPendingChanges(): boolean {
  return attributeValuesPanel.value?.hasPendingChanges?.() ?? false
}

// Expose methods for parent component
defineExpose({
  saveAttributeValues,
  hasPendingChanges
})
</script>
