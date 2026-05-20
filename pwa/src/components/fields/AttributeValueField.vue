<template>
  <div>
    <!-- Attribute Definition Select -->
    <v-select
      v-model="attributeDefinitionValue"
      :label="label"
      :items="filteredDefinitions"
      item-title="code"
      item-value="@id"
      :loading="loadingDefinitions"
      :required="required"
      :error-messages="errorMessages"
      clearable
      class="mb-4"
      @update:model-value="onDefinitionChange"
    />

    <!-- Dynamic Value Input based on Attribute Type -->
    <template v-if="selectedDefinition">
      <!-- TEXT -->
      <v-text-field
        v-if="selectedDefinition.type === 'text'"
        v-model="valueData"
        label="Value"
        :hint="definitionHint"
        :persistent-hint="!!definitionHint"
        :rules="textRules"
        :required="selectedDefinition.isRequired"
        @update:model-value="onValueChange"
      />

      <!-- TEXTAREA -->
      <v-textarea
        v-else-if="selectedDefinition.type === 'textarea'"
        v-model="valueData"
        label="Value"
        rows="3"
        :hint="definitionHint"
        :persistent-hint="!!definitionHint"
        :rules="textRules"
        :required="selectedDefinition.isRequired"
        @update:model-value="onValueChange"
      />

      <!-- RICHTEXT -->
      <RichTextField
        v-else-if="selectedDefinition.type === 'richtext'"
        v-model="valueData"
        label="Value (Rich Text)"
        @update:model-value="onValueChange"
      />

      <!-- NUMBER / DECIMAL -->
      <v-text-field
        v-else-if="selectedDefinition.type === 'number' || selectedDefinition.type === 'decimal'"
        v-model.number="valueData"
        label="Value"
        type="number"
        step="any"
        :min="selectedDefinition.validationRules?.min"
        :max="selectedDefinition.validationRules?.max"
        :hint="definitionHint"
        :persistent-hint="!!definitionHint"
        :rules="numberRules"
        :required="selectedDefinition.isRequired"
        @update:model-value="onValueChange"
      />

      <!-- INTEGER -->
      <v-text-field
        v-else-if="selectedDefinition.type === 'integer'"
        v-model.number="valueData"
        label="Value"
        type="number"
        step="1"
        :min="selectedDefinition.validationRules?.min"
        :max="selectedDefinition.validationRules?.max"
        :hint="definitionHint"
        :persistent-hint="!!definitionHint"
        :rules="numberRules"
        :required="selectedDefinition.isRequired"
        @update:model-value="onValueChange"
      />

      <!-- BOOLEAN -->
      <v-switch
        v-else-if="selectedDefinition.type === 'boolean'"
        v-model="booleanValue"
        label="Value"
        color="primary"
        @update:model-value="onBooleanChange"
      />

      <!-- ENUM (single select) -->
      <v-autocomplete
        v-else-if="selectedDefinition.type === 'enum'"
        v-model="optionValue"
        label="Option"
        :items="attributeOptions"
        item-title="code"
        item-value="@id"
        :loading="loadingOptions"
        clearable
        @update:model-value="onOptionChange"
      />

      <!-- MULTI_ENUM (multiple select) -->
      <v-autocomplete
        v-else-if="selectedDefinition.type === 'multienum'"
        v-model="valuesData"
        label="Options"
        :items="attributeOptions"
        item-title="code"
        item-value="@id"
        :loading="loadingOptions"
        multiple
        chips
        closable-chips
        @update:model-value="onValuesChange"
      />

      <!-- MEDIA -->
      <v-text-field
        v-else-if="selectedDefinition.type === 'media'"
        v-model="valueData"
        label="Media URL"
        hint="Enter media file path or URL"
        @update:model-value="onValueChange"
      />

      <!-- RELATION -->
      <RelationSearchField
        v-else-if="selectedDefinition.type === 'relation'"
        v-model="valueData"
        :endpoint="selectedDefinition.relationEndpoint"
        label="Relation"
        @update:model-value="onValueChange"
      />

      <!-- JSON -->
      <JsonKeyValueField
        v-else-if="selectedDefinition.type === 'json'"
        v-model="valueData"
        label="JSON Value"
        @update:model-value="onValueChange"
      />

      <!-- MEASURE (value + unit) -->
      <div v-else-if="selectedDefinition.type === 'measure'" class="d-flex gap-2">
        <v-text-field
          v-model.number="measureValue"
          label="Value"
          type="number"
          step="any"
          :min="selectedDefinition.validationRules?.min"
          :max="selectedDefinition.validationRules?.max"
          :rules="numberRules"
          :required="selectedDefinition.isRequired"
          :hint="definitionHint"
          :persistent-hint="!!definitionHint"
          class="flex-grow-1"
          @update:model-value="onMeasureChange"
        />
        <v-text-field
          v-model="measureUnit"
          :label="selectedDefinition.unit ? `Unit (${selectedDefinition.unit})` : 'Unit'"
          style="max-width: 120px"
          :placeholder="selectedDefinition.unit || ''"
          @update:model-value="onMeasureChange"
        />
      </div>

      <!-- Fallback -->
      <v-text-field
        v-else
        v-model="valueData"
        label="Value"
        @update:model-value="onValueChange"
      />
    </template>
  </div>
</template>

<script setup lang="ts">
import { ref, computed, watch, onMounted } from 'vue'
import { useAttributeDefinitions } from '../../composables/useAttributeDefinitions'
import { useAttributeOptions } from '../../composables/useAttributeOptions'
import { useAttributeValidation } from '../../composables/useAttributeValidation'
import { extractIri } from '../../utils/resourceConfig'
import JsonKeyValueField from './JsonKeyValueField.vue'
import RichTextField from './RichTextField.vue'
import RelationSearchField from './RelationSearchField.vue'

interface Props {
  modelValue: string | null | undefined
  formData?: Record<string, any>
  label?: string
  required?: boolean
  errorMessages?: string[]
  field?: any
  excludeDefinitions?: string[]
}

const props = withDefaults(defineProps<Props>(), {
  label: 'Attribute Definition',
  formData: () => ({}),
  required: false,
  errorMessages: () => [],
  excludeDefinitions: () => []
})

const emit = defineEmits<{
  'update:modelValue': [value: string | null]
  'update:formData': [value: Record<string, any>]
  'definition-change': [definition: { isLocalizable: boolean, isScopable: boolean } | null]
}>()

const {
  definitionsList,
  loading: loadingDefinitions,
  getDefinition,
  ensureLoaded: ensureDefinitionsLoaded
} = useAttributeDefinitions()

const {
  loading: loadingOptions,
  getOptionsForDefinition,
  getOption,
  ensureLoaded: ensureOptionsLoaded
} = useAttributeOptions()

const selectedDefinition = ref<any>(null)
const { helpText: definitionHint, textRules, numberRules } = useAttributeValidation(selectedDefinition)

// Filter definitions based on excludeDefinitions prop
const filteredDefinitions = computed(() => {
  if (!props.excludeDefinitions || props.excludeDefinitions.length === 0) {
    return definitionsList.value
  }
  const excludeSet = new Set(props.excludeDefinitions)
  return definitionsList.value.filter((d: any) => !excludeSet.has(d['@id']))
})

// Get options from shared cache with fallback for mismatched options
const attributeOptions = computed(() => {
  if (!selectedDefinition.value?.['@id']) return []
  const definitionOptions = getOptionsForDefinition(selectedDefinition.value['@id'])
  const definitionIris = new Set(definitionOptions.map((o: any) => o['@id']))
  const additionalOptions: any[] = []

  // Add current option if not in definition's options
  if (optionValue.value && !definitionIris.has(optionValue.value)) {
    const opt = getOption(optionValue.value)
    if (opt) additionalOptions.push(opt)
  }

  // Add missing multienum options
  for (const iri of valuesData.value) {
    if (!definitionIris.has(iri)) {
      const opt = getOption(iri)
      if (opt) additionalOptions.push(opt)
    }
  }

  return additionalOptions.length > 0 ? [...definitionOptions, ...additionalOptions] : definitionOptions
})

const attributeDefinitionValue = ref<string | null>(extractIri(props.modelValue))

// Value fields
const valueData = ref<string | null>(props.formData?.value ?? null)
const optionValue = ref<string | null>(extractIri(props.formData?.option))
const valuesData = ref<string[]>(
  Array.isArray(props.formData?.values)
    ? props.formData.values.map((v: any) => extractIri(v) || v)
    : []
)
const booleanValue = ref(props.formData?.value === 'true' || props.formData?.value === '1')
const measureValue = ref<number | null>(null)
const measureUnit = ref<string>('')

// Parse measure value from JSON
function parseMeasureFromValue() {
  if (props.formData?.value) {
    try {
      const parsed = JSON.parse(props.formData.value)
      measureValue.value = parsed.value ?? null
      measureUnit.value = parsed.unit ?? ''
    } catch {
      measureValue.value = null
      measureUnit.value = ''
    }
  }
}

function applyDefaultValue(definition: any) {
  const dv = definition?.defaultValue
  const type = definition?.type

  // Reset all
  valueData.value = null
  optionValue.value = null
  valuesData.value = []
  booleanValue.value = false
  measureValue.value = null
  measureUnit.value = definition?.unit || ''

  if (dv === null || dv === undefined || dv === '') {
    emit('update:formData', { value: null, option: null, values: null })
    return
  }

  if (type === 'boolean') {
    booleanValue.value = dv === 'true' || dv === '1' || dv === true
    emit('update:formData', { value: booleanValue.value ? 'true' : 'false' })
  } else if (type === 'enum') {
    optionValue.value = String(dv)
    emit('update:formData', { option: optionValue.value })
  } else if (type === 'multienum') {
    try {
      const parsed = typeof dv === 'string' ? JSON.parse(dv) : dv
      valuesData.value = Array.isArray(parsed) ? parsed.map(String) : []
    } catch {
      valuesData.value = []
    }
    emit('update:formData', { values: valuesData.value })
  } else if (type === 'measure') {
    try {
      const parsed = typeof dv === 'string' ? JSON.parse(dv) : dv
      measureValue.value = parsed?.value ?? null
      measureUnit.value = parsed?.unit ?? definition?.unit ?? ''
      emit('update:formData', {
        value: JSON.stringify({ value: measureValue.value, unit: measureUnit.value })
      })
    } catch {
      emit('update:formData', { value: null })
    }
  } else {
    valueData.value = String(dv)
    emit('update:formData', { value: valueData.value })
  }
}

function onDefinitionChange(value: string | null) {
  emit('update:modelValue', value)

  if (value) {
    const definition = getDefinition(value) || definitionsList.value.find((d: any) => d['@id'] === value)
    if (definition) {
      selectedDefinition.value = definition
      emit('definition-change', {
        isLocalizable: definition.isLocalizable ?? false,
        isScopable: definition.isScopable ?? false
      })
      applyDefaultValue(definition)
      return
    }
  }

  // Clear when no definition
  selectedDefinition.value = null
  valueData.value = null
  optionValue.value = null
  valuesData.value = []
  booleanValue.value = false
  measureValue.value = null
  measureUnit.value = ''
  emit('update:formData', { value: null, option: null, values: null })
  emit('definition-change', null)
}

function onValueChange(value: string | number | null) {
  // Always convert to string since the entity expects string type
  const stringValue = value !== null && value !== undefined ? String(value) : null
  emit('update:formData', { value: stringValue })
}

function onBooleanChange(value: boolean) {
  emit('update:formData', { value: value ? 'true' : 'false' })
}

function onOptionChange(value: string | null) {
  emit('update:formData', { option: value })
}

function onValuesChange(values: string[]) {
  emit('update:formData', { values })
}

function onMeasureChange() {
  const measureData = JSON.stringify({
    value: measureValue.value,
    unit: measureUnit.value || selectedDefinition.value?.unit || ''
  })
  emit('update:formData', { value: measureData })
}

// Sync with external modelValue changes
watch(() => props.modelValue, (value) => {
  const iri = extractIri(value)
  if (iri !== attributeDefinitionValue.value) {
    attributeDefinitionValue.value = iri
    if (iri) {
      const definition = getDefinition(iri) || definitionsList.value.find((d: any) => d['@id'] === iri)
      if (definition) {
        selectedDefinition.value = definition
        emit('definition-change', {
          isLocalizable: definition.isLocalizable ?? false,
          isScopable: definition.isScopable ?? false
        })
        if (definition.type === 'measure') {
          parseMeasureFromValue()
        }
      }
    }
  }
})

// Sync value fields from formData
watch(() => props.formData?.value, (value) => {
  if (value !== valueData.value) {
    valueData.value = value ?? null
    booleanValue.value = value === 'true' || value === '1'
    if (selectedDefinition.value?.type === 'measure') {
      parseMeasureFromValue()
    }
  }
})

watch(() => props.formData?.option, (value) => {
  const iri = extractIri(value)
  if (iri !== optionValue.value) {
    optionValue.value = iri
  }
})

watch(() => props.formData?.values, (value) => {
  const iris = Array.isArray(value) ? value.map((v: any) => extractIri(v) || v) : []
  if (JSON.stringify(iris) !== JSON.stringify(valuesData.value)) {
    valuesData.value = iris
  }
})

onMounted(async () => {
  // Ensure both definitions and options are loaded
  await Promise.all([ensureDefinitionsLoaded(), ensureOptionsLoaded()])

  // If we have an attributeDefinition IRI, set the selectedDefinition from the shared cache
  if (attributeDefinitionValue.value) {
    const definition = getDefinition(attributeDefinitionValue.value) ||
      definitionsList.value.find((d: any) => d['@id'] === attributeDefinitionValue.value)
    if (definition) {
      selectedDefinition.value = definition
      // Emit definition properties for conditional field visibility
      emit('definition-change', {
        isLocalizable: definition.isLocalizable ?? false,
        isScopable: definition.isScopable ?? false
      })
      // Parse measure if type is measure
      if (definition.type === 'measure') {
        parseMeasureFromValue()
      }
    }
  }
})
</script>
