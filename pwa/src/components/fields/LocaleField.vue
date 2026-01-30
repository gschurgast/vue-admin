<template>
  <v-select
    v-model="localeValue"
    :label="label"
    :items="locales"
    item-title="label"
    item-value="code"
    :loading="loading"
    :required="required"
    :error-messages="errorMessages"
    clearable
    @update:model-value="onChange"
  >
    <template #item="{ item, props }">
      <v-list-item v-bind="props">
        <template #prepend>
          <span class="mr-2">{{ item.raw.flag }}</span>
        </template>
      </v-list-item>
    </template>
    <template #selection="{ item }">
      <span>{{ item.raw.flag }} {{ item.raw.label }}</span>
    </template>
  </v-select>
</template>

<script setup lang="ts">
import { ref, watch, onMounted } from 'vue'
import { useLocales } from '../../composables/useLocales'

interface Props {
  modelValue: string | null | undefined
  label?: string
  required?: boolean
  errorMessages?: string[]
  field?: any
}

const props = withDefaults(defineProps<Props>(), {
  label: 'Locale',
  required: false,
  errorMessages: () => []
})

const emit = defineEmits<{
  'update:modelValue': [value: string | null]
}>()

const { locales, loading, loadLocales } = useLocales()
const localeValue = ref<string | null>(props.modelValue ?? null)

function onChange(value: string | null) {
  emit('update:modelValue', value)
}

watch(() => props.modelValue, (value) => {
  if (value !== localeValue.value) {
    localeValue.value = value ?? null
  }
}, { immediate: true })

onMounted(() => {
  loadLocales()
})
</script>
