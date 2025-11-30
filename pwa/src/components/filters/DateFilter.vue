<template>
  <v-menu v-model="menu" :close-on-content-click="false">
    <template v-slot:activator="{ props }">
      <v-text-field
        :model-value="displayValue"
        :label="label"
        v-bind="props"
        readonly
        density="compact"
        clearable
        hide-details
        prepend-inner-icon="mdi-calendar"
        @click:clear="clearDate"
      />
    </template>
    <v-date-picker
      v-model="internalDate"
      @update:model-value="handleUpdate"
      show-adjacent-months
      hide-header
    />
  </v-menu>
</template>

<script setup>
import { ref, computed, watch } from 'vue'

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

const emit = defineEmits(['update:modelValue', 'search'])

const menu = ref(false)
const internalDate = ref(null)

// Initialize from modelValue
if (props.modelValue) {
  internalDate.value = new Date(props.modelValue)
}

// Display formatted date
const displayValue = computed(() => {
  if (!internalDate.value) return ''
  return new Date(internalDate.value).toLocaleDateString('en-US', { 
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

function handleUpdate(date) {
  if (!date) {
    emit('update:modelValue', '')
    // Don't close menu immediately if just clearing, or maybe do? 
    // Usually clearing is done via the clear icon on the input.
    // If date is null here it means deselection in picker if supported.
    return
  }
  
  const formattedDate = formatDateForAPI(date)
  emit('update:modelValue', formattedDate)
  menu.value = false
  
  // Trigger search after a short delay to ensure the value is updated
  setTimeout(() => {
    emit('search')
  }, 100)
}

function clearDate() {
  internalDate.value = null
  emit('update:modelValue', '')
}

function formatDateForAPI(date) {
  if (!date) return ''
  const d = new Date(date)
  const year = d.getFullYear()
  const month = String(d.getMonth() + 1).padStart(2, '0')
  const day = String(d.getDate()).padStart(2, '0')
  return `${year}-${month}-${day}`
}
</script>
