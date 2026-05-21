<template>
  <!-- TEXT -->
  <v-text-field
    v-if="attributeType === 'text'"
    v-model="localValue"
    :label="label"
    :hint="definitionHint"
    :persistent-hint="!!definitionHint"
    :rules="textRules"
    :required="definition?.isRequired"
    clearable
    @update:model-value="emitValue"
  />

  <!-- TEXTAREA -->
  <v-textarea
    v-else-if="attributeType === 'textarea'"
    v-model="localValue"
    :label="label"
    rows="3"
    auto-grow
    :hint="definitionHint"
    :persistent-hint="!!definitionHint"
    :rules="textRules"
    :required="definition?.isRequired"
    clearable
    @update:model-value="emitValue"
  />

  <!-- RICHTEXT -->
  <RichTextField
    v-else-if="attributeType === 'richtext'"
    v-model="localValue"
    :label="label || 'Value (Rich Text)'"
    @update:model-value="emitValue"
  />

  <!-- NUMBER / DECIMAL -->
  <v-text-field
    v-else-if="attributeType === 'number' || attributeType === 'decimal'"
    v-model.number="localValue"
    :label="label"
    type="number"
    step="any"
    :min="definition?.validationRules?.min"
    :max="definition?.validationRules?.max"
    :hint="definitionHint"
    :persistent-hint="!!definitionHint"
    :rules="numberRules"
    :required="definition?.isRequired"
    clearable
    @update:model-value="emitValue"
  />

  <!-- INTEGER -->
  <v-text-field
    v-else-if="attributeType === 'integer'"
    v-model.number="localValue"
    :label="label"
    type="number"
    step="1"
    :min="definition?.validationRules?.min"
    :max="definition?.validationRules?.max"
    :hint="definitionHint"
    :persistent-hint="!!definitionHint"
    :rules="numberRules"
    :required="definition?.isRequired"
    clearable
    @update:model-value="emitValue"
  />

  <!-- BOOLEAN -->
  <v-switch
    v-else-if="attributeType === 'boolean'"
    v-model="booleanValue"
    :label="label"
    color="primary"
    @update:model-value="emitBoolean"
  />

  <!-- ENUM -->
  <v-autocomplete
    v-else-if="attributeType === 'enum'"
    v-model="localOption"
    :label="label || 'Option'"
    :items="attributeOptions"
    item-title="code"
    item-value="@id"
    :loading="loadingOptions"
    clearable
    @update:model-value="emitOption"
  />

  <!-- MULTIENUM -->
  <v-autocomplete
    v-else-if="attributeType === 'multienum'"
    v-model="localValues"
    :label="label || 'Options'"
    :items="attributeOptions"
    item-title="code"
    item-value="@id"
    :loading="loadingOptions"
    multiple
    chips
    closable-chips
    clearable
    @update:model-value="emitValues"
  />

  <!-- MEDIA -->
  <v-text-field
    v-else-if="attributeType === 'media'"
    v-model="localValue"
    :label="label || 'Media URL'"
    hint="Enter media file path or URL"
    @update:model-value="emitValue"
  />

  <!-- JSON -->
  <JsonKeyValueField
    v-else-if="attributeType === 'json'"
    v-model="localValue"
    :label="label || 'JSON Value'"
    @update:model-value="emitValue"
  />

  <!-- MEASURE -->
  <MeasureInput
    v-else-if="attributeType === 'measure'"
    v-model="measureJson"
    :label="label"
    :unit="definition?.unit"
    :min="definition?.validationRules?.min"
    :max="definition?.validationRules?.max"
    :hint="definitionHint"
    :persistent-hint="!!definitionHint"
    :rules="numberRules"
    :required="definition?.isRequired"
    @update:model-value="emitMeasure"
  />

  <!-- RELATION -->
  <RelationSearchField
    v-else-if="attributeType === 'relation'"
    v-model="localValue"
    :endpoint="definition?.relationEndpoint"
    :label="label || 'Relation'"
    @update:model-value="emitValue"
  />

  <!-- Fallback -->
  <v-text-field
    v-else
    v-model="localValue"
    :label="label"
    clearable
    @update:model-value="emitValue"
  />
</template>

<script setup lang="ts">
import { ref, computed, watch } from 'vue'
import { useAttributeOptions } from '../../composables/useAttributeOptions'
import { useAttributeValidation } from '../../composables/useAttributeValidation'
import { extractIri } from '../../utils/resourceConfig'
import RichTextField from './RichTextField.vue'
import RelationSearchField from './RelationSearchField.vue'
import JsonKeyValueField from './JsonKeyValueField.vue'
import MeasureInput from './MeasureInput.vue'

interface Props {
  /** Attribute definition (drives the editor type and validation) */
  definition?: any
  /** Scalar value (text/number/boolean-string/JSON-stringified measure/...) */
  value?: string | null
  /** Single option (enum) — either an IRI string or an object with @id */
  option?: any
  /** Multi options (multienum) */
  values?: any[] | null
  /** Field label */
  label?: string
}

const props = withDefaults(defineProps<Props>(), {
  definition: null,
  value: null,
  option: null,
  values: () => [],
  label: 'Value',
})

const emit = defineEmits<{
  change: [data: { value?: string | null; option?: string | null; values?: string[] | null }]
}>()

const { loading: loadingOptions, getOptionsForDefinition, getOption } = useAttributeOptions()

const attributeType = computed(() => props.definition?.type || 'text')
const definitionRef = computed(() => props.definition)
const { helpText: definitionHint, textRules, numberRules } = useAttributeValidation(definitionRef)

// Local refs mirroring props (one per supported value kind)
const localValue = ref<string | number | null>(props.value ?? null)
const booleanValue = ref(props.value === 'true' || props.value === '1')
const localOption = ref<string | null>(extractIri(props.option))
const localValues = ref<string[]>(
  Array.isArray(props.values) ? props.values.map((v: any) => extractIri(v) || v) : []
)
const measureJson = ref<string | null>(
  attributeType.value === 'measure' ? (props.value ?? null) : null
)

// Whether the next emit should be skipped because we are currently syncing from props
const isSyncing = ref(false)

// Attribute options with fallback for entries that come from prop values but
// are not part of the definition's known options
const attributeOptions = computed(() => {
  if (!props.definition?.['@id']) return []
  const baseOptions = getOptionsForDefinition(props.definition['@id'])
  const knownIris = new Set(baseOptions.map((o: any) => o['@id']))
  const extras: any[] = []

  if (localOption.value && !knownIris.has(localOption.value)) {
    const opt = getOption(localOption.value)
    if (opt) extras.push(opt)
  }
  for (const iri of localValues.value) {
    if (!knownIris.has(iri)) {
      const opt = getOption(iri)
      if (opt) extras.push(opt)
    }
  }

  return extras.length > 0 ? [...baseOptions, ...extras] : baseOptions
})

// Sync local state when props change (external updates)
watch(
  () => [props.value, attributeType.value],
  ([value]) => {
    isSyncing.value = true
    if (attributeType.value === 'measure') {
      measureJson.value = (value as string | null) ?? null
    } else if (attributeType.value === 'boolean') {
      booleanValue.value = value === 'true' || value === '1'
    } else {
      localValue.value = (value as string | null) ?? null
    }
    isSyncing.value = false
  }
)

watch(() => props.option, (option) => {
  isSyncing.value = true
  localOption.value = extractIri(option)
  isSyncing.value = false
})

watch(() => props.values, (values) => {
  isSyncing.value = true
  localValues.value = Array.isArray(values) ? values.map((v: any) => extractIri(v) || v) : []
  isSyncing.value = false
})

function emitValue() {
  if (isSyncing.value) return
  const v = localValue.value
  const stringValue = v !== null && v !== undefined ? String(v) : null
  emit('change', { value: stringValue })
}

function emitBoolean(value: boolean) {
  if (isSyncing.value) return
  emit('change', { value: value ? 'true' : 'false' })
}

function emitOption() {
  if (isSyncing.value) return
  emit('change', { option: localOption.value })
}

function emitValues() {
  if (isSyncing.value) return
  emit('change', { values: [...localValues.value] })
}

function emitMeasure(jsonValue: string) {
  if (isSyncing.value) return
  measureJson.value = jsonValue
  emit('change', { value: jsonValue })
}
</script>
