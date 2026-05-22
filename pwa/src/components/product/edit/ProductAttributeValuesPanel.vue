<template>
  <div>
    <v-progress-linear v-if="loading" indeterminate color="primary" />

    <template v-else>
      <!-- New variant without product selected yet -->
      <v-alert
        v-if="isVariant && !productIri"
        type="info"
        variant="tonal"
        density="compact"
        class="mb-4"
      >
        {{ t('attributes.selectProductFirst') }}
      </v-alert>

      <!-- Variant-specific attributes shown FIRST when on a variant -->
      <AttributeSection
        v-if="isVariant && productIri"
        :title="t('attributes.variantTitle')"
        :subtitle="variantIri ? t('attributes.subtitleVariant') : t('attributes.saveVariantFirst')"
        icon="mdi-shape-outline"
        color="deep-purple"
        :scope-label="t('attributes.scopeVariant')"
        :attributes="variantAttributeValues"
        :empty-text="t('attributes.emptyVariant')"
        :add-disabled="!variantIri"
        :translating-ids="translatingIds"
        class="mb-4"
        @add="openAddDialog('variant')"
        @change="onValueChange"
        @delete="deleteVariantAttribute"
        @translate="translateAttribute"
      />

      <!-- Product-level attributes: read-only on variant page, editable on product page -->
      <AttributeSection
        v-if="productIri"
        :title="t('attributes.productTitle')"
        :subtitle="isVariant ? t('attributes.subtitleProductInherited') : t('attributes.subtitleProductShared')"
        icon="mdi-package-variant-closed"
        color="primary"
        :scope-label="t('attributes.scopeProduct')"
        :attributes="productAttributeValues"
        :empty-text="isVariant ? t('attributes.emptySharedOnVariant') : t('attributes.emptyProduct')"
        :readonly="isVariant"
        :edit-link="isVariant ? productEditLink : null"
        :translating-ids="translatingIds"
        @add="openAddDialog('product')"
        @change="onValueChange"
        @delete="deleteProductAttribute"
        @translate="translateAttribute"
      />
    </template>

    <!-- Add Attribute Value Dialog -->
    <v-dialog v-model="addDialog" max-width="600">
      <v-card>
        <v-card-title>
          {{ addDialogType === 'product' ? t('attributes.addProductAttribute') : t('attributes.addVariantAttribute') }}
        </v-card-title>
        <v-card-text>
          <AttributeValueField
            v-model="newAttributeDefinition"
            :form-data="newFormData"
            :exclude-definitions="getExcludedDefinitions()"
            :label="t('attributes.attributeLabel')"
            @update:form-data="onFormDataUpdate"
          />
        </v-card-text>
        <v-card-actions>
          <v-spacer />
          <v-btn @click="addDialog = false">{{ t('common.cancel') }}</v-btn>
          <v-btn
            color="primary"
            :disabled="!newAttributeDefinition"
            @click="addAttributeValue"
          >
            {{ t('common.add') }}
          </v-btn>
        </v-card-actions>
      </v-card>
    </v-dialog>
  </div>
</template>

<script setup lang="ts">
import { ref, computed, watch, onMounted } from 'vue'
import { useI18n } from 'vue-i18n'
import apiPlatform from '../../../services/apiPlatform'
import { useAttributeOptions } from '../../../composables/useAttributeOptions'
import { useFormLocale } from '../../../composables/useFormLocale'
import AttributeValueField from '../../fields/AttributeValueField.vue'
import AttributeSection from './AttributeSection.vue'

const { t } = useI18n()
const { ensureLoaded: ensureOptionsLoaded } = useAttributeOptions()
const formLocale = useFormLocale()

interface Props {
  productIri: string | null
  variantIri?: string | null
  isVariant?: boolean
}

const props = withDefaults(defineProps<Props>(), {
  variantIri: null,
  isVariant: false
})

const emit = defineEmits<{
  'attributes-loaded': [attributes: { productAttributes: any[], variantAttributes: any[] }]
}>()

const loading = ref(false)
const productAttributeValues = ref<any[]>([])
const variantAttributeValues = ref<any[]>([])
const attributeDefinitionsCache = ref<Record<string, any>>({})

// Track pending changes (using plain objects for better Vue reactivity)
const pendingUpdates = ref<Record<string | number, Record<string, any>>>({})
const pendingCreates = ref<any[]>([])
const pendingDeletes = ref<Record<string | number, boolean>>({})

// Map of PAV id → boolean while a translate-to-all call is in flight.
const translatingIds = ref<Record<string | number, boolean>>({})

async function translateAttribute(attrValue: any) {
  if (!attrValue?.id) return
  const id = attrValue.id

  try {
    translatingIds.value = { ...translatingIds.value, [id]: true }

    // Persist any local edits first so the server translates the latest value.
    if (hasPendingChanges()) {
      await saveChanges()
    }

    await apiPlatform.client.post(
      '/api/translate_pavs',
      { sourceAttributeValue: `/api/product_attribute_values/${id}` },
      { headers: { 'Content-Type': 'application/ld+json' } }
    )

    await loadAttributeValues()
  } catch (error) {
    console.error('Translate-to-all failed:', error)
  } finally {
    const { [id]: _removed, ...rest } = translatingIds.value
    translatingIds.value = rest
  }
}

// Add dialog state
const addDialog = ref(false)
const addDialogType = ref<'product' | 'variant'>('product')
const newAttributeDefinition = ref<string | null>(null)
const newFormData = ref<Record<string, any>>({})

let tempIdCounter = 0

// Get already used attribute definition IRIs for the current context
function getExcludedDefinitions(): string[] {
  const existingValues = addDialogType.value === 'product'
    ? productAttributeValues.value
    : variantAttributeValues.value
  return existingValues.map(av => av.attributeDefinition?.['@id']).filter(Boolean)
}

// Extract numeric ID from IRI (e.g., "/api/products/1" -> 1)
function extractIdFromIri(iri: string | null): number | null {
  if (!iri) return null
  const match = iri.match(/\/(\d+)$/)
  return match ? parseInt(match[1], 10) : null
}

const productEditLink = computed(() => {
  const productId = extractIdFromIri(props.productIri)
  return productId ? `/edit/Product/${productId}` : null
})

async function loadAttributeValues() {
  if (!props.productIri) return

  const productId = extractIdFromIri(props.productIri)
  if (!productId) return

  loading.value = true
  try {
    const params: Record<string, any> = { locale: formLocale.value }
    if (props.variantIri) {
      const variantId = extractIdFromIri(props.variantIri)
      if (variantId) {
        params.variantId = variantId
      }
    }

    const response = await apiPlatform.client.get(`/api/product_attribute_values/by_product/${productId}`, { params })
    productAttributeValues.value = response.data.productAttributes || []
    variantAttributeValues.value = response.data.variantAttributes || []

    // Cache attribute definitions
    for (const av of [...productAttributeValues.value, ...variantAttributeValues.value]) {
      if (av.attributeDefinition?.['@id']) {
        attributeDefinitionsCache.value[av.attributeDefinition['@id']] = av.attributeDefinition
      }
    }

    // Clear pending changes after reload
    pendingUpdates.value = {}
    pendingCreates.value = []
    pendingDeletes.value = {}

    // Emit loaded attributes for parent component
    emit('attributes-loaded', {
      productAttributes: productAttributeValues.value,
      variantAttributes: variantAttributeValues.value
    })
  } catch (error) {
    console.error('Failed to load attribute values:', error)
  } finally {
    loading.value = false
  }
}

function openAddDialog(type: 'product' | 'variant') {
  addDialogType.value = type
  newAttributeDefinition.value = null
  newFormData.value = {}
  addDialog.value = true
}

function onFormDataUpdate(data: Record<string, any>) {
  newFormData.value = { ...newFormData.value, ...data }
}

async function addAttributeValue() {
  if (!newAttributeDefinition.value || !props.productIri) return

  // Load the attribute definition details
  let attrDef = attributeDefinitionsCache.value[newAttributeDefinition.value]
  if (!attrDef) {
    try {
      attrDef = await apiPlatform.getByIri(newAttributeDefinition.value)
      attributeDefinitionsCache.value[newAttributeDefinition.value] = attrDef
    } catch (error) {
      console.error('Failed to load attribute definition:', error)
      return
    }
  }

  const newAttr: any = {
    _tempId: `temp_${++tempIdCounter}`,
    _isNew: true,
    attributeDefinition: attrDef,
    value: newFormData.value.value ?? null,
    option: newFormData.value.option ?? null,
    values: newFormData.value.values ?? null
  }

  // Add to pending creates
  const createData: Record<string, any> = {
    product: props.productIri,
    attributeDefinition: newAttributeDefinition.value
  }

  // Localizable attributes are stored per-locale; tag the row with the form locale.
  if (attrDef?.isLocalizable) {
    createData.locale = formLocale.value
    newAttr.locale = formLocale.value
  }

  if (addDialogType.value === 'variant' && props.variantIri) {
    createData.variant = props.variantIri
    newAttr._isVariant = true
  }

  if (newFormData.value.value !== undefined && newFormData.value.value !== null) {
    createData.value = newFormData.value.value
  }
  if (newFormData.value.option !== undefined && newFormData.value.option !== null) {
    createData.option = newFormData.value.option
  }
  if (newFormData.value.values !== undefined && Array.isArray(newFormData.value.values) && newFormData.value.values.length > 0) {
    createData.values = newFormData.value.values
  }

  newAttr._createData = createData

  pendingCreates.value.push(newAttr)

  // Add to display list
  if (addDialogType.value === 'variant') {
    variantAttributeValues.value.push(newAttr)
  } else {
    productAttributeValues.value.push(newAttr)
  }

  addDialog.value = false
}

function onValueChange(attrValue: any, data: { value?: string | null, option?: string | null, values?: string[] | null }) {
  // Update local state
  if (data.value !== undefined) attrValue.value = data.value
  if (data.option !== undefined) attrValue.option = data.option
  if (data.values !== undefined) attrValue.values = data.values

  // Track pending update
  if (attrValue.id) {
    const existing = pendingUpdates.value[attrValue.id] || {}
    pendingUpdates.value[attrValue.id] = { ...existing, ...data }
  } else if (attrValue._isNew) {
    // Update the create data
    if (data.value !== undefined) attrValue._createData.value = data.value
    if (data.option !== undefined) attrValue._createData.option = data.option
    if (data.values !== undefined) attrValue._createData.values = data.values
  }
}

function deleteProductAttribute(index: number) {
  const attrValue = productAttributeValues.value[index]
  if (!attrValue) return

  if (attrValue.id) {
    pendingDeletes.value[attrValue.id] = true
  }

  if (attrValue._isNew) {
    const createIdx = pendingCreates.value.findIndex(c => c._tempId === attrValue._tempId)
    if (createIdx !== -1) pendingCreates.value.splice(createIdx, 1)
  }

  productAttributeValues.value.splice(index, 1)
}

function deleteVariantAttribute(index: number) {
  const attrValue = variantAttributeValues.value[index]
  if (!attrValue) return

  if (attrValue.id) {
    pendingDeletes.value[attrValue.id] = true
  }

  if (attrValue._isNew) {
    const createIdx = pendingCreates.value.findIndex(c => c._tempId === attrValue._tempId)
    if (createIdx !== -1) pendingCreates.value.splice(createIdx, 1)
  }

  variantAttributeValues.value.splice(index, 1)
}

// Public method to save all pending changes
async function saveChanges(): Promise<void> {
  const promises: Promise<any>[] = []

  // Process deletes
  for (const id of Object.keys(pendingDeletes.value)) {
    promises.push(apiPlatform.delete('/api/product_attribute_values', id))
  }

  // Process updates
  for (const [id, data] of Object.entries(pendingUpdates.value)) {
    if (!pendingDeletes.value[id]) {
      promises.push(apiPlatform.update('/api/product_attribute_values', id, data))
    }
  }

  // Process creates
  for (const item of pendingCreates.value) {
    promises.push(apiPlatform.create('/api/product_attribute_values', item._createData))
  }

  if (promises.length > 0) {
    await Promise.all(promises)
    // Reload data to get fresh state
    await loadAttributeValues()
  }
}

// Check if there are pending changes
function hasPendingChanges(): boolean {
  return Object.keys(pendingUpdates.value).length > 0 || pendingCreates.value.length > 0 || Object.keys(pendingDeletes.value).length > 0
}

// Expose methods for parent component
defineExpose({
  saveChanges,
  hasPendingChanges,
  reload: loadAttributeValues
})

// Reload whenever the product, variant or selected locale changes so the
// panel shows localizable attribute values for the current locale.
watch(
  () => [props.productIri, props.variantIri, formLocale.value] as const,
  () => {
    loadAttributeValues()
  }
)

onMounted(async () => {
  // Preload options while loading attribute values
  await Promise.all([
    loadAttributeValues(),
    ensureOptionsLoaded()
  ])
})
</script>
