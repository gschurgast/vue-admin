<template>
  <v-number-input
    v-model="localValue"
    :label="label"
    :required="required"
    :error-messages="errorMessages"
    :step="step"
    controlVariant="stacked"
  />
</template>

<script setup lang="ts">
import { computed } from 'vue'

interface Props {
  modelValue: number | string | null | undefined
  label?: string
  required?: boolean
  errorMessages?: string[]
  step?: number | string
  field?: any
}

const props = withDefaults(defineProps<Props>(), {
  label: '',
  required: false,
  errorMessages: () => [],
  step: 1
})

const emit = defineEmits<{
  'update:modelValue': [value: number | null]
}>()

const localValue = computed({
  get: () => props.modelValue ?? null,
  set: (v) => emit('update:modelValue', v === null ? null : Number(v))
})
</script>
