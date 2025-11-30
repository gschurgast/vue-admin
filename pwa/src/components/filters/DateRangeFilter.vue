<template>
  <v-row dense>
    <v-col cols="6">
      <v-menu
        v-model="menuFrom"
        :close-on-content-click="false"
        transition="scale-transition"
        offset-y
        min-width="290px"
      >
        <template v-slot:activator="{ props }">
          <v-text-field
            :model-value="formattedFromDate"
            :label="`${label} (From)`"
            prepend-icon="mdi-calendar"
            readonly
            v-bind="props"
            density="compact"
            hide-details
            clearable
            @click:clear="clearFromDate"
          ></v-text-field>
        </template>
        <v-date-picker
          v-model="internalFromDate"
          @update:model-value="handleFromUpdate"
          :max="internalToDate"
          show-adjacent-months
        ></v-date-picker>
      </v-menu>
    </v-col>
    <v-col cols="6">
      <v-menu
        v-model="menuTo"
        :close-on-content-click="false"
        transition="scale-transition"
        offset-y
        min-width="290px"
      >
        <template v-slot:activator="{ props }">
          <v-text-field
            :model-value="formattedToDate"
            :label="`${label} (To)`"
            prepend-icon="mdi-calendar"
            readonly
            v-bind="props"
            density="compact"
            hide-details
            clearable
            @click:clear="clearToDate"
          ></v-text-field>
        </template>
        <v-date-picker
          v-model="internalToDate"
          @update:model-value="handleToUpdate"
          :min="internalFromDate"
          show-adjacent-months
        ></v-date-picker>
      </v-menu>
    </v-col>
  </v-row>
</template>

<script setup>
import { ref, computed, watch } from 'vue'

const props = defineProps({
  modelValue: {
    type: Object,
    default: () => ({ after: null, before: null })
  },
  label: {
    type: String,
    default: 'Date Range'
  },
  filter: Object
})

const emit = defineEmits(['update:modelValue'])

const menuFrom = ref(false)
const menuTo = ref(false)
const internalFromDate = ref(props.modelValue?.after || null)
const internalToDate = ref(props.modelValue?.before || null)

const formattedFromDate = computed(() => {
  return internalFromDate.value ? new Date(internalFromDate.value).toLocaleDateString() : ''
})

const formattedToDate = computed(() => {
  return internalToDate.value ? new Date(internalToDate.value).toLocaleDateString() : ''
})

function handleFromUpdate(date) {
  internalFromDate.value = date
  menuFrom.value = false
  
  // If 'to' date is set and is before the new 'from' date, clear it
  if (internalToDate.value && new Date(internalToDate.value) < new Date(date)) {
    internalToDate.value = null
  }
  
  updateValue()
}

function handleToUpdate(date) {
  internalToDate.value = date
  menuTo.value = false
  
  // If 'from' date is set and is after the new 'to' date, clear it
  if (internalFromDate.value && new Date(internalFromDate.value) > new Date(date)) {
    internalFromDate.value = null
  }
  
  updateValue()
}

function clearFromDate() {
  internalFromDate.value = null
  updateValue()
}

function clearToDate() {
  internalToDate.value = null
  updateValue()
}

function updateValue() {
  const result = {}
  
  if (internalFromDate.value) {
    result.after = formatDateForAPI(internalFromDate.value)
  }
  if (internalToDate.value) {
    result.before = formatDateForAPI(internalToDate.value)
  }
  
  emit('update:modelValue', Object.keys(result).length > 0 ? result : null)
}

function formatDateForAPI(date) {
  if (!date) return null
  const d = new Date(date)
  const year = d.getFullYear()
  const month = String(d.getMonth() + 1).padStart(2, '0')
  const day = String(d.getDate()).padStart(2, '0')
  return `${year}-${month}-${day}`
}

// Watch for external changes
watch(() => props.modelValue, (newValue) => {
  if (newValue) {
    internalFromDate.value = newValue.after ? new Date(newValue.after) : null
    internalToDate.value = newValue.before ? new Date(newValue.before) : null
  }
}, { deep: true })
</script>
