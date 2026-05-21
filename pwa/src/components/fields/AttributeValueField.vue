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
    <AttributeValueEditor
      v-if="selectedDefinition"
      :definition="selectedDefinition"
      :value="formValue"
      :option="formOption"
      :values="formValues"
      label="Value"
      @change="onEditorChange"
    />
  </div>
</template>

<script setup lang="ts">
import { ref, computed, watch, onMounted } from 'vue'
import { useAttributeDefinitions } from '../../composables/useAttributeDefinitions'
import { useAttributeOptions } from '../../composables/useAttributeOptions'
import { extractIri } from '../../utils/resourceConfig'
import AttributeValueEditor from './AttributeValueEditor.vue'

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
  excludeDefinitions: () => [],
})

const emit = defineEmits<{
  'update:modelValue': [value: string | null]
  'update:formData': [value: Record<string, any>]
  'definition-change': [definition: { isLocalizable: boolean; isScopable: boolean } | null]
}>()

const {
  definitionsList,
  loading: loadingDefinitions,
  getDefinition,
  ensureLoaded: ensureDefinitionsLoaded,
} = useAttributeDefinitions()
const { ensureLoaded: ensureOptionsLoaded } = useAttributeOptions()

const selectedDefinition = ref<any>(null)
const attributeDefinitionValue = ref<string | null>(extractIri(props.modelValue))

const filteredDefinitions = computed(() => {
  if (!props.excludeDefinitions || props.excludeDefinitions.length === 0) {
    return definitionsList.value
  }
  const excludeSet = new Set(props.excludeDefinitions)
  return definitionsList.value.filter((d: any) => !excludeSet.has(d['@id']))
})

// Sub-editor input passthrough — read directly from props.formData
const formValue = computed(() => props.formData?.value ?? null)
const formOption = computed(() => props.formData?.option ?? null)
const formValues = computed(() => props.formData?.values ?? [])

function findDefinition(iri: string | null) {
  if (!iri) return null
  return getDefinition(iri) || definitionsList.value.find((d: any) => d['@id'] === iri) || null
}

function applyDefaultValue(definition: any) {
  const dv = definition?.defaultValue
  const type = definition?.type

  if (dv === null || dv === undefined || dv === '') {
    emit('update:formData', { value: null, option: null, values: null })
    return
  }

  if (type === 'boolean') {
    emit('update:formData', { value: dv === 'true' || dv === '1' || dv === true ? 'true' : 'false' })
  } else if (type === 'enum') {
    emit('update:formData', { option: String(dv) })
  } else if (type === 'multienum') {
    try {
      const parsed = typeof dv === 'string' ? JSON.parse(dv) : dv
      emit('update:formData', { values: Array.isArray(parsed) ? parsed.map(String) : [] })
    } catch {
      emit('update:formData', { values: [] })
    }
  } else if (type === 'measure') {
    try {
      const parsed = typeof dv === 'string' ? JSON.parse(dv) : dv
      emit('update:formData', {
        value: JSON.stringify({ value: parsed?.value ?? null, unit: definition?.unit || '' }),
      })
    } catch {
      emit('update:formData', { value: null })
    }
  } else {
    emit('update:formData', { value: String(dv) })
  }
}

function selectDefinition(definition: any) {
  selectedDefinition.value = definition
  emit('definition-change', definition
    ? { isLocalizable: definition.isLocalizable ?? false, isScopable: definition.isScopable ?? false }
    : null)
}

function onDefinitionChange(value: string | null) {
  emit('update:modelValue', value)
  const definition = findDefinition(value)
  if (definition) {
    selectDefinition(definition)
    applyDefaultValue(definition)
  } else {
    selectDefinition(null)
    emit('update:formData', { value: null, option: null, values: null })
  }
}

function onEditorChange(data: { value?: string | null; option?: string | null; values?: string[] | null }) {
  emit('update:formData', data)
}

// Sync with external modelValue changes (e.g., parent resets the field)
watch(() => props.modelValue, (value) => {
  const iri = extractIri(value)
  if (iri !== attributeDefinitionValue.value) {
    attributeDefinitionValue.value = iri
    selectDefinition(findDefinition(iri))
  }
})

onMounted(async () => {
  await Promise.all([ensureDefinitionsLoaded(), ensureOptionsLoaded()])
  if (attributeDefinitionValue.value) {
    selectDefinition(findDefinition(attributeDefinitionValue.value))
  }
})
</script>
