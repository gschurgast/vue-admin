<template>
  <v-autocomplete
    v-model="localValue"
    :label="label"
    :items="attributes"
    item-title="code"
    item-value="@id"
    :loading="loading"
    :required="required"
    :error-messages="errorMessages"
    clearable
  />
</template>

<script setup lang="ts">
import { ref, computed, onMounted } from 'vue'
import apiPlatform from '../../services/apiPlatform'

interface Props {
  modelValue: string | null | undefined
  label?: string
  required?: boolean
  errorMessages?: string[]
  field?: any
}

const props = withDefaults(defineProps<Props>(), {
  label: 'Attribute',
  required: false,
  errorMessages: () => []
})

const emit = defineEmits<{
  'update:modelValue': [value: string | null]
}>()

const loading = ref(false)
const attributes = ref<any[]>([])

const localValue = computed({
  get: () => props.modelValue ?? null,
  set: (v) => emit('update:modelValue', v)
})

async function loadAttributes() {
  loading.value = true
  try {
    const response = await apiPlatform.getList('/api/attribute_definitions', {
      itemsPerPage: 100,
      'type[]': ['enum', 'multienum']
    })
    attributes.value = response.data
  } catch (error) {
    console.error('Failed to load attributes:', error)
  } finally {
    loading.value = false
  }
}

onMounted(() => {
  loadAttributes()
})
</script>
