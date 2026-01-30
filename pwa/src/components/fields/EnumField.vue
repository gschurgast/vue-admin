<template>
  <v-select
    v-model="localValue"
    :label="label"
    :items="items"
    :required="required"
    :error-messages="errorMessages"
    :loading="loading"
    clearable
  />
</template>

<script setup lang="ts">
import { computed } from 'vue'

interface Props {
  modelValue: string | null | undefined
  label?: string
  required?: boolean
  errorMessages?: string[]
  enumValues?: string[]
  loading?: boolean
}

const props = withDefaults(defineProps<Props>(), {
  label: '',
  required: false,
  errorMessages: () => [],
  enumValues: () => [],
  loading: false
})

const emit = defineEmits<{
  'update:modelValue': [value: string | null]
}>()

const localValue = computed({
  get: () => props.modelValue ?? null,
  set: (v) => emit('update:modelValue', v)
})

const items = computed(() => {
  return props.enumValues.map(value => ({
    title: value,
    value: value
  }))
})
</script>
