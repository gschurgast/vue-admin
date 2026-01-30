<template>
  <div>
    <v-label class="mb-2">{{ label }}</v-label>

    <!-- Key-Value pairs -->
    <div v-for="(pair, index) in pairs" :key="index" class="d-flex align-center mb-2" style="gap: 16px;">
      <v-text-field
        v-model="pair.key"
        label="Key"
        density="compact"
        hide-details="auto"
        class="flex-grow-1"
        :rules="[keyRule]"
        @update:model-value="onKeyChange($event, index)"
      />
      <v-text-field
        v-model="pair.value"
        label="Value"
        density="compact"
        hide-details
        class="flex-grow-1"
        @update:model-value="onPairChange"
      />
      <v-btn
        icon
        density="compact"
        variant="text"
        color="error"
        @click="removePair(index)"
      >
        <v-icon>mdi-delete</v-icon>
      </v-btn>
      <v-btn
        icon
        density="compact"
        variant="text"
        color="primary"
        @click="addPairAfter(index)"
      >
        <v-icon>mdi-plus</v-icon>
      </v-btn>
    </div>

    <!-- Add button when no pairs -->
    <v-btn
      v-if="pairs.length === 0"
      variant="tonal"
      size="small"
      prepend-icon="mdi-plus"
      @click="addPair"
    >
      Add
    </v-btn>

    <!-- Error messages -->
    <div v-if="errorMessages?.length" class="mt-2">
      <v-alert
        v-for="(error, index) in errorMessages"
        :key="index"
        type="error"
        density="compact"
        variant="tonal"
      >
        {{ error }}
      </v-alert>
    </div>
  </div>
</template>

<script setup lang="ts">
import { ref, watch, onMounted } from 'vue'

interface KeyValuePair {
  key: string
  value: string
}

interface Props {
  modelValue: string | Record<string, any> | null | undefined
  label?: string
  required?: boolean
  errorMessages?: string[]
}

const props = withDefaults(defineProps<Props>(), {
  label: 'JSON Data',
  required: false,
  errorMessages: () => []
})

const emit = defineEmits<{
  'update:modelValue': [value: Record<string, any> | null]
}>()

const pairs = ref<KeyValuePair[]>([])

// Key validation rule: camelCase with underscores allowed
const keyRegex = /^[a-z][a-zA-Z0-9_]*$/
function keyRule(value: string): boolean | string {
  if (!value) return true
  return keyRegex.test(value) || 'Key must start with lowercase and contain only letters, numbers, and underscores'
}

function onKeyChange(value: string, index: number) {
  // Only allow valid characters, don't auto-convert
  const sanitized = value.replace(/[^a-zA-Z0-9_]/g, '')
  if (sanitized !== value) {
    pairs.value[index].key = sanitized
  }
  onPairChange()
}

function parseValueToPairs(value: string | Record<string, any> | null | undefined): KeyValuePair[] {
  if (!value) return []

  let obj: any = value

  // If it's a string, try to parse it as JSON
  if (typeof value === 'string') {
    try {
      obj = JSON.parse(value)
    } catch {
      return []
    }
  }

  // Convert object to key-value pairs
  if (typeof obj === 'object' && obj !== null && !Array.isArray(obj)) {
    return Object.entries(obj).map(([key, val]) => ({
      key,
      value: typeof val === 'string' ? val : JSON.stringify(val)
    }))
  }

  return []
}

function pairsToObject(): Record<string, any> | null {
  const validPairs = pairs.value.filter(p => p.key.trim() !== '')
  if (validPairs.length === 0) return null

  const obj: Record<string, any> = {}
  for (const pair of validPairs) {
    // Try to parse value as JSON (for numbers, booleans, objects)
    let value: any = pair.value
    try {
      value = JSON.parse(pair.value)
    } catch {
      // Keep as string
    }
    obj[pair.key] = value
  }
  return obj
}

function addPair() {
  pairs.value.push({ key: '', value: '' })
}

function addPairAfter(index: number) {
  pairs.value.splice(index + 1, 0, { key: '', value: '' })
}

function removePair(index: number) {
  pairs.value.splice(index, 1)
  onPairChange()
}

const isInternalUpdate = ref(false)

function onPairChange() {
  isInternalUpdate.value = true
  emit('update:modelValue', pairsToObject())
  // Reset flag after next tick
  setTimeout(() => {
    isInternalUpdate.value = false
  }, 0)
}

// Initialize pairs from modelValue
watch(() => props.modelValue, (value) => {
  // Skip if this is an internal update to avoid overwriting pairs with duplicate keys
  if (isInternalUpdate.value) return

  const newPairs = parseValueToPairs(value)
  // Only update if different to avoid infinite loops
  if (JSON.stringify(newPairs) !== JSON.stringify(pairs.value)) {
    pairs.value = newPairs
  }
}, { immediate: true })

onMounted(() => {
  // Add an empty pair if no data
  if (pairs.value.length === 0) {
    pairs.value.push({ key: '', value: '' })
  }
})
</script>
