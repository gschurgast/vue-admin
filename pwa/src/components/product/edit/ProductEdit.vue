<template>
  <v-row>
    <v-col cols="12">
      <LocaleSelectorBar v-model="formLocale" />
    </v-col>

    <!-- Left side: Image + Form -->
    <v-col cols="12" md="6">
      <v-card>
        <v-card-title>Product Details</v-card-title>
        <v-card-text>
          <div class="d-flex">
            <!-- Product Image -->
            <div class="flex-shrink-0 mr-4" style="width: 150px;">
              <v-img
                v-if="productImage"
                :src="productImage"
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

      <ProductVariantsList :product-iri="productIri" class="mt-4" />
    </v-col>

    <!-- Right side: Attribute Values Panel -->
    <v-col cols="12" md="6">
      <ProductAttributeValuesPanel
        ref="attributeValuesPanel"
        :product-iri="productIri"
        @attributes-loaded="onAttributesLoaded"
      />
    </v-col>
  </v-row>
</template>

<script setup lang="ts">
import { ref, computed, watch, provide } from 'vue'
import ResourceForm from '../../resource/ResourceForm.vue'
import ProductAttributeValuesPanel from './ProductAttributeValuesPanel.vue'
import ProductVariantsList from './ProductVariantsList.vue'
import LocaleSelectorBar from '../../common/LocaleSelectorBar.vue'
import { provideFormLocale } from '../../../composables/useFormLocale'
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
const productImage = ref<string | null>(null)
const generatingContent = ref(false)
const formLocale = provideFormLocale()

const productIri = computed(() => {
  // Get the product IRI from formData (either @id or construct from id)
  if (localFormData.value['@id']) {
    return localFormData.value['@id']
  }
  if (localFormData.value.id) {
    return `/api/products/${localFormData.value.id}`
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
  // Find image attribute (type 'media' with code 'image') from product attributes
  const imageAttr = attributes.productAttributes.find(attr =>
    attr.attributeDefinition?.type === 'media' &&
    (attr.attributeDefinition?.code === 'image' || attr.attributeDefinition?.code === 'picture')
  )
  productImage.value = imageAttr?.value || null
}

const generateDisabled = computed(() => !productIri.value || generatingContent.value)

provide('richTextGenerate', {
  run: () => generateAiContent(),
  isLoading: generatingContent,
  disabled: generateDisabled,
  label: ref('Générer'),
})

// Generate AI content for product description.
// Returns the generated HTML without persisting — the rich text editor receives
// it and the user must save the form to commit the change.
async function generateAiContent(): Promise<string | undefined> {
  if (!productIri.value) return undefined

  const productId = localFormData.value.id
  if (!productId) return undefined

  generatingContent.value = true
  try {
    const response = await apiPlatform.client.post(`/api/products/${productId}/generate-content`, {
      locale: formLocale.value
    })
    return response.data?.generatedContent as string | undefined
  } catch (error) {
    console.error('Failed to generate AI content:', error)
    return undefined
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
