<template>
  <div class="d-flex gap-2">
    <!-- Date Picker -->
    <v-menu v-model="menu" :close-on-content-click="false">
      <template v-slot:activator="{ props }">
        <v-text-field
          :model-value="displayDate"
          :label="label"
          :error-messages="errorMessages"
          :required="required"
          v-bind="props"
          readonly
          clearable
          prepend-inner-icon="mdi-calendar"
          class="flex-grow-1 mr-2"
          @click:clear="clearDate"
        />
      </template>
      <v-date-picker
        v-model="internalDate"
        @update:model-value="handleDateUpdate"
        show-adjacent-months
        hide-header
        color="primary"
      />
    </v-menu>

    <!-- Time Picker (Text Field) -->
    <v-text-field
      v-model="internalTime"
      label="Time"
      type="time"
      prepend-inner-icon="mdi-clock-outline"
      style="max-width: 150px"
      @update:model-value="handleTimeUpdate"
    />
  </div>
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
const internalTime = ref<string>('')

// Initialize from modelValue
if (props.modelValue) {
  const d = new Date(props.modelValue)
  internalDate.value = d
  internalTime.value = formatTime(d)
}

// Display formatted date
const displayDate = computed(() => {
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
    internalTime.value = ''
  } else {
    const d = new Date(newValue)
    internalDate.value = d
    internalTime.value = formatTime(d)
  }
})

function handleDateUpdate(date: unknown) {
  if (!date) {
    internalDate.value = null
    updateModel()
    return
  }
  
  internalDate.value = date as Date
  menu.value = false
  updateModel()
}

function handleTimeUpdate(time: string) {
  internalTime.value = time
  updateModel()
}

function clearDate() {
  internalDate.value = null
  internalTime.value = ''
  emit('update:modelValue', null)
}

function updateModel() {
  if (!internalDate.value) {
    emit('update:modelValue', null)
    return
  }
  
  const d = new Date(internalDate.value)
  
  if (internalTime.value) {
    const [hours, minutes] = internalTime.value.split(':').map(Number)
    d.setHours(hours)
    d.setMinutes(minutes)
  } else {
    // Default to 00:00 if no time selected
    d.setHours(0)
    d.setMinutes(0)
  }
  
  // Format as ISO string for API (YYYY-MM-DDTHH:mm:ss)
  // Note: Using local time components to construct the string to avoid timezone shifts
  const year = d.getFullYear()
  const month = String(d.getMonth() + 1).padStart(2, '0')
  const day = String(d.getDate()).padStart(2, '0')
  const hours = String(d.getHours()).padStart(2, '0')
  const minutes = String(d.getMinutes()).padStart(2, '0')
  const seconds = '00'
  
  const isoString = `${year}-${month}-${day}T${hours}:${minutes}:${seconds}`
  emit('update:modelValue', isoString)
}

function formatTime(date: Date): string {
  const hours = String(date.getHours()).padStart(2, '0')
  const minutes = String(date.getMinutes()).padStart(2, '0')
  return `${hours}:${minutes}`
}
</script>
