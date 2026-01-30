<template>
  <v-autocomplete
    v-model="localValue"
    :label="label"
    :items="items"
    :item-title="itemTitle"
    item-value="@id"
    :loading="loading"
    :required="required"
    :error-messages="errorMessages"
    clearable
  />
</template>

<script setup lang="ts">
import { computed } from 'vue'

interface Props {
  modelValue: string | null | undefined
  label?: string
  items: Array<any>
  itemTitle?: string
  loading?: boolean
  required?: boolean
  errorMessages?: string[]
  field?: any
}

const props = withDefaults(defineProps<Props>(), {
  label: '',
  items: () => [],
  itemTitle: 'name',
  loading: false,
  required: false,
  errorMessages: () => []
})

const emit = defineEmits<{
  'update:modelValue': [value: string | null]
}>()

const localValue = computed({
  get: () => props.modelValue ?? null,
  set: (v) => emit('update:modelValue', v)
})
</script>
