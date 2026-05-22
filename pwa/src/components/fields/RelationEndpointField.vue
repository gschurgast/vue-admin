<template>
  <v-autocomplete
    v-if="showField"
    v-model="selectedEndpoint"
    :label="label"
    :items="endpoints"
    item-title="label"
    item-value="path"
    :loading="loading"
    :required="required"
    :error-messages="errorMessages"
    clearable
    @update:model-value="onEndpointChange"
  >
    <template #prepend-inner>
      <v-icon color="primary">mdi-link</v-icon>
    </template>
    <template #item="slotProps">
      <v-list-item
        v-bind="slotProps?.props ?? {}"
        :subtitle="slotProps?.item?.raw?.path ?? ''"
      />
    </template>
  </v-autocomplete>
</template>

<script setup lang="ts">
import { ref, computed, watch, onMounted } from 'vue'
import apiPlatform from '../../services/apiPlatform'

interface Props {
  modelValue: string | null | undefined
  formData?: Record<string, any>
  label?: string
  required?: boolean
  errorMessages?: string[]
}

const props = withDefaults(defineProps<Props>(), {
  label: 'Relation Endpoint',
  formData: () => ({}),
  required: false,
  errorMessages: () => []
})

const emit = defineEmits<{
  'update:modelValue': [value: string | null]
}>()

const selectedEndpoint = ref<string | null>(props.modelValue ?? null)
const loading = ref(false)
const endpoints = ref<Array<{ label: string; path: string; name: string }>>([])

// Only show field when type is 'relation'
const showField = computed(() => {
  return props.formData?.type === 'relation'
})

async function loadEndpoints() {
  loading.value = true
  try {
    await apiPlatform.fetchSchema()
    const resources = apiPlatform.getResources()

    endpoints.value = resources
      .filter(r => {
        // Only include resources that have GetCollection operation and are not hidden
        const hasGetCollection = apiPlatform.hasCollectionOperation(r.name, 'GET')
        const isHidden = apiPlatform.isResourceHidden(r.name)
        return hasGetCollection && !isHidden
      })
      .map(r => ({
        label: r.title || r.name,
        path: apiPlatform.getResourcePath(r.name),
        name: r.name
      }))
      .sort((a, b) => a.label.localeCompare(b.label))
  } catch (error) {
    console.error('Failed to load endpoints:', error)
  } finally {
    loading.value = false
  }
}

function onEndpointChange(value: string | null) {
  emit('update:modelValue', value)
}

// Watch for external value changes
watch(() => props.modelValue, (value) => {
  if (value !== selectedEndpoint.value) {
    selectedEndpoint.value = value ?? null
  }
})

// Clear value when type changes away from 'relation'
watch(() => props.formData?.type, (newType, oldType) => {
  if (oldType === 'relation' && newType !== 'relation') {
    selectedEndpoint.value = null
    emit('update:modelValue', null)
  }
})

onMounted(() => {
  loadEndpoints()
})
</script>
