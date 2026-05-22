<template>
  <RelationSearchField
    :model-value="modelValue"
    :endpoint="filter.endpoint"
    :label="label"
    @update:model-value="onChange"
  />
</template>

<script setup lang="ts">
import RelationSearchField from '../fields/RelationSearchField.vue'

interface Props {
  filter: { field: string; endpoint?: string | null }
  modelValue: string | null | undefined
  label?: string
}

defineProps<Props>()

const emit = defineEmits<{
  'update:modelValue': [value: string | null]
}>()

function onChange(value: string | null) {
  emit('update:modelValue', value)
}
</script>