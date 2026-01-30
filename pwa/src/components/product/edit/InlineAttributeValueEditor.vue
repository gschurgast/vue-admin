<template>
  <div class="inline-editor">
    <!-- TEXT -->
    <v-text-field
      v-if="attributeType === 'text'"
      v-model="localValue"
      :label="label"
      clearable
      @update:model-value="emitChange"
    />

    <!-- TEXTAREA -->
    <v-textarea
      v-else-if="attributeType === 'textarea'"
      v-model="localValue"
      :label="label"
      rows="3"
      auto-grow
      clearable
      @update:model-value="emitChange"
    />

    <!-- RICHTEXT -->
    <RichTextField
      v-else-if="attributeType === 'richtext'"
      v-model="localValue"
      :label="label"
      @update:model-value="emitChange"
    />

    <!-- NUMBER / DECIMAL -->
    <v-text-field
      v-else-if="attributeType === 'number' || attributeType === 'decimal'"
      v-model.number="localValue"
      :label="label"
      type="number"
      step="any"
      clearable
      @update:model-value="emitChange"
    />

    <!-- INTEGER -->
    <v-text-field
      v-else-if="attributeType === 'integer'"
      v-model.number="localValue"
      :label="label"
      type="number"
      step="1"
      clearable
      @update:model-value="emitChange"
    />

    <!-- BOOLEAN -->
    <v-switch
      v-else-if="attributeType === 'boolean'"
      v-model="booleanValue"
      :label="label"
      color="primary"
      @update:model-value="onBooleanChange"
    />

    <!-- ENUM -->
    <v-select
      v-else-if="attributeType === 'enum'"
      v-model="localOption"
      :items="attributeOptions"
      item-title="code"
      item-value="@id"
      :label="label"
      clearable
      :loading="loadingOptions"
      @update:model-value="emitOptionChange"
    />

    <!-- MULTIENUM -->
    <v-select
      v-else-if="attributeType === 'multienum'"
      v-model="localValues"
      :items="attributeOptions"
      item-title="code"
      item-value="@id"
      :label="label"
      multiple
      chips
      closable-chips
      clearable
      :loading="loadingOptions"
      @update:model-value="emitValuesChange"
    />

    <!-- MEASURE -->
    <div v-else-if="attributeType === 'measure'" class="d-flex gap-2">
      <v-text-field
        v-model.number="measureValue"
        :label="label"
        type="number"
        step="any"
        class="flex-grow-1"
        clearable
        @update:model-value="emitMeasureChange"
      />
      <v-text-field
        v-model="measureUnit"
        :label="definition?.unit ? `Unit (${definition.unit})` : 'Unit'"
        style="max-width: 120px"
        :placeholder="definition?.unit || ''"
        @update:model-value="emitMeasureChange"
      />
    </div>

    <!-- Fallback -->
    <v-text-field
      v-else
      v-model="localValue"
      :label="label"
      clearable
      @update:model-value="emitChange"
    />
  </div>
</template>

<script setup lang="ts">
import { ref, computed, onMounted } from 'vue'
import { useAttributeOptions } from '../../../composables/useAttributeOptions'
import { extractIri } from '../../../utils/resourceConfig'
import RichTextField from '../../fields/RichTextField.vue'

interface Props {
  attributeValue: {
    attributeDefinition?: any
    value?: string | null
    values?: string[] | null
    option?: any
  }
  label?: string
}

const props = withDefaults(defineProps<Props>(), {
  label: ''
})

const emit = defineEmits<{
  'change': [data: { value?: string | null, option?: string | null, values?: string[] | null }]
}>()

const { loading: loadingOptions, getOptionsForDefinition, getOption, ensureLoaded } = useAttributeOptions()

const localValue = ref<string | null>(null)
const localOption = ref<string | null>(null)
const localValues = ref<string[]>([])
const booleanValue = ref(false)
const measureValue = ref<number | null>(null)
const measureUnit = ref<string>('')
const isInitializing = ref(false)

const definition = computed(() => props.attributeValue.attributeDefinition)
const attributeType = computed(() => definition.value?.type || 'text')

// Get options from shared cache with fallback for mismatched options
const attributeOptions = computed(() => {
  if (!definition.value?.['@id']) return []
  const definitionOptions = getOptionsForDefinition(definition.value['@id'])
  const definitionIris = new Set(definitionOptions.map((o: any) => o['@id']))
  const additionalOptions: any[] = []

  // Add current option if not in definition's options
  if (localOption.value && !definitionIris.has(localOption.value)) {
    const opt = getOption(localOption.value)
    if (opt) additionalOptions.push(opt)
  }

  // Add missing multienum options
  for (const iri of localValues.value) {
    if (!definitionIris.has(iri)) {
      const opt = getOption(iri)
      if (opt) additionalOptions.push(opt)
    }
  }

  return additionalOptions.length > 0 ? [...definitionOptions, ...additionalOptions] : definitionOptions
})

function initializeValues() {
  isInitializing.value = true

  const value = props.attributeValue.value
  localValue.value = value ?? null

  // Extract option IRI
  const option = props.attributeValue.option
  if (option) {
    localOption.value = extractIri(option)
  } else {
    localOption.value = null
  }

  // Multi values
  const values = props.attributeValue.values
  if (Array.isArray(values)) {
    localValues.value = values.map(v => extractIri(v) || v)
  } else {
    localValues.value = []
  }

  // Boolean
  booleanValue.value = value === 'true' || value === '1'

  // Measure
  if (attributeType.value === 'measure' && value) {
    try {
      const parsed = JSON.parse(value)
      measureValue.value = parsed.value ?? null
      measureUnit.value = parsed.unit ?? ''
    } catch {
      measureValue.value = null
      measureUnit.value = ''
    }
  }

  setTimeout(() => {
    isInitializing.value = false
  }, 0)
}

function emitChange() {
  if (isInitializing.value) return

  const stringValue = localValue.value !== null && localValue.value !== undefined
    ? String(localValue.value)
    : null
  emit('change', { value: stringValue })
}

function onBooleanChange(value: boolean) {
  if (isInitializing.value) return
  emit('change', { value: value ? 'true' : 'false' })
}

function emitOptionChange() {
  if (isInitializing.value) return
  emit('change', { option: localOption.value })
}

function emitValuesChange() {
  if (isInitializing.value) return
  emit('change', { values: [...localValues.value] })
}

function emitMeasureChange() {
  if (isInitializing.value) return

  const measureData = JSON.stringify({
    value: measureValue.value,
    unit: measureUnit.value || definition.value?.unit || ''
  })
  emit('change', { value: measureData })
}

onMounted(async () => {
  await ensureLoaded()
  initializeValues()
})
</script>
