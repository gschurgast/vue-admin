<template>
  <v-autocomplete
    :model-value="modelValue"
    :label="label || 'Attribute'"
    :items="items"
    item-title="code"
    item-value="@id"
    :loading="loading"
    clearable
    @update:model-value="onChange"
  />
</template>

<script setup lang="ts">
import { ref, onMounted } from 'vue'
import apiPlatform from '../../services/apiPlatform'

interface Props {
  modelValue: string | null | undefined
  label?: string
}

defineProps<Props>()

const emit = defineEmits<{
  'update:modelValue': [value: string | null]
}>()

const items = ref<any[]>([])
const loading = ref(false)

function onChange(value: string | null) {
  emit('update:modelValue', value)
}

// Only fetch AttributeDefinitions that hold options (enum / multienum)
async function load() {
  loading.value = true
  try {
    const response = await apiPlatform.getList('/api/attribute_definitions', {
      itemsPerPage: 100,
      'type[]': ['enum', 'multienum'],
    })
    items.value = response.data || []
  } finally {
    loading.value = false
  }
}

onMounted(load)
</script>