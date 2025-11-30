<template>
  <v-menu v-model="menu" :close-on-content-click="false">
    <template v-slot:activator="{ props }">
      <v-text-field
        :model-value="displayValue"
        :label="label"
        :error-messages="errorMessages"
        :required="required"
        v-bind="props"
        readonly
        clearable
        prepend-inner-icon="mdi-calendar"
        @click:clear="clearDate"
      />
    </template>
    <v-date-picker
      v-model="internalDate"
      @update:model-value="handleUpdate"
      show-adjacent-months
      hide-header
      color="primary"
    />
  </v-menu>
</template>

<script setup lang="ts">
import { ref, computed, watch } from 'vue'

const props = defineProps({
  modelValue: {
    type: [String, null],
    default: null
  },
  label: {
    type: String,
    required: true
  },
  required: {
    type: Boolean,
    default: false
  },
  errorMessages: {
    type: Array as () => string[],
    default: () => []
  },
  field: {
    type: Object,
    default: () => ({})
  }
})

const emit = defineEmits(['update:modelValue'])

const menu = ref(false)
const internalDate = ref<Date | null>(null)

// Initialize from modelValue
if (props.modelValue) {
  internalDate.value = new Date(props.modelValue)
}

// Display formatted date
const displayValue = computed(() => {
  if (!internalDate.value) return ''
  return new Date(internalDate.value).toLocaleDateString(undefined, { 
    year: 'numeric', 
    month: 'short', 
    day: 'numeric' 
  })
})

// Watch for external changes
watch(() => props.modelValue, (newValue) => {
  if (!newValue) {
    internalDate.value = null
  } else {
    internalDate.value = new Date(newValue)
  }
})

function handleUpdate(date: unknown) {
  if (!date) {
    emit('update:modelValue', null)
    return
  }
  
  const d = date as Date
  const formattedDate = formatDateForAPI(d)
  emit('update:modelValue', formattedDate)
  menu.value = false
}

function clearDate() {
  internalDate.value = null
  emit('update:modelValue', null)
}

function formatDateForAPI(date: Date): string {
  const year = date.getFullYear()
  const month = String(date.getMonth() + 1).padStart(2, '0')
  const day = String(date.getDate()).padStart(2, '0')
  return `${year}-${month}-${day}`
}
</script>
