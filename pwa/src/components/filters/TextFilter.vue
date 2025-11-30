<template>
  <v-text-field
    :model-value="modelValue"
    :label="label"
    density="compact"
    clearable
    hide-details
    @update:model-value="$emit('update:modelValue', $event)"
  />
</template>

<script setup>
const props = defineProps({
  filter: {
    type: Object,
    default: () => ({})
  },
  modelValue: {
    type: String,
    default: ''
  },
  label: {
    type: String,
    required: true
  }
})

defineEmits(['update:modelValue'])
</script>
