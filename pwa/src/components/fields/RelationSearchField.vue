<template>
  <v-autocomplete
    v-model="selectedValue"
    :label="label"
    :items="items"
    :item-title="displayField"
    item-value="@id"
    :loading="loading"
    :search="searchQuery"
    :no-data-text="noDataText"
    clearable
    return-object
    @update:search="onSearch"
    @update:model-value="onSelectionChange"
  >
    <template #item="{ item, props: itemProps }">
      <v-list-item v-bind="itemProps">
        <template #subtitle v-if="subtitleField && item.raw[subtitleField]">
          {{ item.raw[subtitleField] }}
        </template>
      </v-list-item>
    </template>
    <template #chip="{ item }">
      <v-chip size="small">
        {{ item.raw[displayField] || item.raw['@id'] }}
      </v-chip>
    </template>
  </v-autocomplete>
</template>

<script setup lang="ts">
import { ref, computed, watch, onMounted } from 'vue'
import apiPlatform from '../../services/apiPlatform'

interface Props {
  modelValue: string | null | undefined
  endpoint: string | null | undefined
  label?: string
  displayField?: string
  subtitleField?: string
}

const props = withDefaults(defineProps<Props>(), {
  label: 'Select relation',
  displayField: 'code',
  subtitleField: ''
})

const emit = defineEmits<{
  'update:modelValue': [value: string | null]
}>()

const selectedValue = ref<any>(null)
const items = ref<any[]>([])
const loading = ref(false)
const searchQuery = ref('')
const searchTimeout = ref<ReturnType<typeof setTimeout> | null>(null)

const noDataText = computed(() => {
  if (!props.endpoint) return 'No endpoint configured'
  if (loading.value) return 'Loading...'
  if (searchQuery.value.length < 1) return 'Type to search'
  return 'No results found'
})

// Determine the best display field based on the item properties
function getDisplayValue(item: any): string {
  // Try common display fields in order of preference
  const displayFields = [props.displayField, 'code', 'name', 'title', 'label', 'skuRoot', '@id']
  for (const field of displayFields) {
    if (item[field]) return item[field]
  }
  return item['@id'] || 'Unknown'
}

async function searchItems(query: string) {
  if (!props.endpoint) {
    items.value = []
    return
  }

  loading.value = true
  try {
    // Build search params - try common search fields
    const params: Record<string, any> = {
      itemsPerPage: 20
    }

    if (query) {
      // Try multiple search strategies
      // API Platform typically uses property names directly for filters
      params['code'] = query
    }

    const response = await apiPlatform.getList(props.endpoint, params)
    items.value = response.data || []

    // If current value is not in results, try to fetch it separately
    if (props.modelValue && !items.value.find((i: any) => i['@id'] === props.modelValue)) {
      try {
        const currentItem = await apiPlatform.getByIri(props.modelValue)
        if (currentItem) {
          items.value = [currentItem, ...items.value]
        }
      } catch {
        // Ignore if we can't fetch the current item
      }
    }
  } catch (error) {
    console.error('Failed to search items:', error)
    items.value = []
  } finally {
    loading.value = false
  }
}

function onSearch(query: string) {
  searchQuery.value = query

  // Debounce search
  if (searchTimeout.value) {
    clearTimeout(searchTimeout.value)
  }

  searchTimeout.value = setTimeout(() => {
    searchItems(query)
  }, 300)
}

function onSelectionChange(item: any) {
  if (item) {
    emit('update:modelValue', item['@id'])
  } else {
    emit('update:modelValue', null)
  }
}

// Load initial data and current value
async function loadInitialData() {
  if (!props.endpoint) return

  loading.value = true
  try {
    // Load first page of items
    const response = await apiPlatform.getList(props.endpoint, { itemsPerPage: 20 })
    items.value = response.data || []

    // If we have a current value, try to load it
    if (props.modelValue) {
      const existingItem = items.value.find((i: any) => i['@id'] === props.modelValue)
      if (existingItem) {
        selectedValue.value = existingItem
      } else {
        try {
          const currentItem = await apiPlatform.getByIri(props.modelValue)
          if (currentItem) {
            items.value = [currentItem, ...items.value]
            selectedValue.value = currentItem
          }
        } catch {
          // If item doesn't exist, just show the IRI
          selectedValue.value = { '@id': props.modelValue, [props.displayField]: props.modelValue }
        }
      }
    }
  } catch (error) {
    console.error('Failed to load initial data:', error)
  } finally {
    loading.value = false
  }
}

// Watch for endpoint changes
watch(() => props.endpoint, () => {
  items.value = []
  selectedValue.value = null
  loadInitialData()
})

// Watch for external value changes
watch(() => props.modelValue, async (newValue) => {
  if (!newValue) {
    selectedValue.value = null
    return
  }

  // Check if the new value is already in our items
  const existingItem = items.value.find((i: any) => i['@id'] === newValue)
  if (existingItem) {
    selectedValue.value = existingItem
  } else if (props.endpoint) {
    // Try to fetch the item
    try {
      const item = await apiPlatform.getByIri(newValue)
      if (item) {
        items.value = [item, ...items.value.filter((i: any) => i['@id'] !== newValue)]
        selectedValue.value = item
      }
    } catch {
      selectedValue.value = { '@id': newValue, [props.displayField]: newValue }
    }
  }
}, { immediate: true })

onMounted(() => {
  loadInitialData()
})
</script>
