<template>
  <v-text-field
    v-model="displayValue"
    :label="label"
    :required="required"
    :error-messages="errorMessages"
    :maxlength="50"
    counter
    @input="handleInput"
    @keydown="handleKeydown"
    clearable
  />
</template>

<script setup lang="ts">
import { ref, watch } from 'vue'

interface Props {
  modelValue: string | null | undefined
  label?: string
  required?: boolean
  errorMessages?: string[]
}

const props = withDefaults(defineProps<Props>(), {
  label: '',
  required: false,
  errorMessages: () => []
})

const emit = defineEmits<{
  'update:modelValue': [value: string | null]
}>()

const displayValue = ref(props.modelValue ?? '')

// Watch for external changes
watch(() => props.modelValue, (newValue) => {
  displayValue.value = newValue ?? ''
})

function handleInput(event: Event) {
  const input = event.target as HTMLInputElement
  // Transform to lowercase, remove invalid characters, and collapse consecutive underscores
  const transformed = input.value
    .toLowerCase()
    .replace(/[^a-z_]/g, '')
    .replace(/_+/g, '_')

  displayValue.value = transformed
  emit('update:modelValue', transformed || null)
}

function handleKeydown(event: KeyboardEvent) {
  const key = event.key
  const input = event.target as HTMLInputElement
  const cursorPos = input.selectionStart ?? 0
  const currentValue = input.value

  // Allow control keys
  if (
    event.ctrlKey ||
    event.metaKey ||
    event.altKey ||
    key === 'Backspace' ||
    key === 'Delete' ||
    key === 'Tab' ||
    key === 'Enter' ||
    key === 'Escape' ||
    key === 'ArrowLeft' ||
    key === 'ArrowRight' ||
    key === 'ArrowUp' ||
    key === 'ArrowDown' ||
    key === 'Home' ||
    key === 'End'
  ) {
    return
  }

  // Only allow lowercase letters, uppercase letters (will be transformed), and underscore
  if (!/^[a-zA-Z_]$/.test(key)) {
    event.preventDefault()
    return
  }

  // Prevent consecutive underscores
  if (key === '_') {
    const charBefore = cursorPos > 0 ? currentValue[cursorPos - 1] : ''
    const charAfter = currentValue[cursorPos] ?? ''
    if (charBefore === '_' || charAfter === '_') {
      event.preventDefault()
    }
  }
}
</script>
