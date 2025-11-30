<template>
  <v-switch
    :model-value="modelValue"
    @update:model-value="$emit('update:modelValue', $event)"
    :label="label"
    :error-messages="errorMessages"
    :color="modelValue ? 'success' : 'error'"
    :base-color="modelValue ? undefined : 'error'"
    hide-details="auto"
  />
</template>

<script setup lang="ts">
interface Props {
  modelValue?: boolean
  label?: string
  errorMessages?: string | string[]
  field?: any
}

defineProps<Props>()

defineEmits<{
  'update:modelValue': [value: boolean]
}>()
</script>
