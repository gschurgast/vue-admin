<template>
  <v-form ref="formRef">
    <div v-for="field in fields" :key="field.name" v-show="isFieldVisible(field)">
      <!-- Custom field component -->
      <component
        v-if="field.customComponent && customComponents[`fields/${field.customComponent}`]"
        :is="customComponents[`fields/${field.customComponent}`]"
        :field="field"
        v-model="localFormData[field.name]"
        :form-data="localFormData"
        :label="field.label"
        :error-messages="fieldErrors[field.name]"
        @update:form-data="onFormDataUpdate"
        @definition-change="onDefinitionChange"
      />
      
      <!-- Relation field -->
      <RelationField
        v-else-if="field.isRelation"
        :field="field"
        v-model="localFormData[field.name]"
        :items="relationData[field.relatedResource] || []"
        :item-title="field.itemTitle || 'name'"
        :label="field.label"
        :loading="loadingRelations[field.relatedResource]"
        :error-messages="fieldErrors[field.name]"
      />

      <!-- Enum field -->
      <EnumField
        v-else-if="field.enumValues && field.enumValues.length > 0"
        v-model="localFormData[field.name]"
        :label="field.label"
        :required="field.required"
        :enum-values="field.enumValues"
        :error-messages="fieldErrors[field.name]"
      />

      <!-- Code field -->
      <CodeField
        v-else-if="field.type === 'code'"
        v-model="localFormData[field.name]"
        :label="field.label"
        :required="field.required"
        :error-messages="fieldErrors[field.name]"
      />

      <!-- Text field -->
      <TextField
        v-else-if="field.type === 'string' || field.type === 'text'"
        :field="field"
        v-model="localFormData[field.name]"
        :label="field.label"
        :required="field.required"
        :error-messages="fieldErrors[field.name]"
      />
      
      <!-- Textarea field -->
      <TextareaField
        v-else-if="field.type === 'textarea'"
        :field="field"
        v-model="localFormData[field.name]"
        :label="field.label"
        :required="field.required"
        :error-messages="fieldErrors[field.name]"
      />
      
      <!-- Date field -->
      <DateField
        v-else-if="field.type === 'date'"
        :field="field"
        v-model="localFormData[field.name]"
        :label="field.label"
        :required="field.required"
        :error-messages="fieldErrors[field.name]"
      />
      
      <!-- DateTime field -->
      <DateTimeField
        v-else-if="field.type === 'datetime'"
        :field="field"
        v-model="localFormData[field.name]"
        :label="field.label"
        :required="field.required"
        :error-messages="fieldErrors[field.name]"
      />
      
      <!-- Boolean field -->
      <BooleanField
        v-else-if="field.type === 'boolean'"
        :field="field"
        v-model="localFormData[field.name]"
        :label="field.label"
        :error-messages="fieldErrors[field.name]"
      />

      <!-- Number/Integer field -->
      <NumberField
        v-else-if="field.type === 'integer' || field.type === 'number'"
        v-model="localFormData[field.name]"
        :label="field.label"
        :required="field.required"
        :error-messages="fieldErrors[field.name]"
        :step="field.type === 'number' ? 'any' : 1"
      />

      <!-- JSON field -->
      <JsonKeyValueField
        v-else-if="field.type === 'json' || field.type === 'array'"
        v-model="localFormData[field.name]"
        :label="field.label"
        :required="field.required"
        :error-messages="fieldErrors[field.name]"
      />
    </div>
  </v-form>
</template>

<script setup lang="ts">
import { ref, watch } from 'vue'
import RelationField from '../fields/RelationField.vue'
import TextField from '../fields/TextField.vue'
import TextareaField from '../fields/TextareaField.vue'
import DateField from '../fields/DateField.vue'
import DateTimeField from '../fields/DateTimeField.vue'
import BooleanField from '../fields/BooleanField.vue'
import EnumField from '../fields/EnumField.vue'
import CodeField from '../fields/CodeField.vue'
import NumberField from '../fields/NumberField.vue'
import JsonKeyValueField from '../fields/JsonKeyValueField.vue'

interface Props {
  modelValue: Record<string, any>
  fields: Array<any>
  customComponents?: Record<string, any>
  relationData?: Record<string, any>
  loadingRelations?: Record<string, boolean>
  fieldErrors?: Record<string, string[]>
}

const props = withDefaults(defineProps<Props>(), {
  customComponents: () => ({}),
  relationData: () => ({}),
  loadingRelations: () => ({}),
  fieldErrors: () => ({})
})

const emit = defineEmits<{
  'update:modelValue': [value: Record<string, any>]
}>()

const formRef = ref()
const localFormData = ref({ ...props.modelValue })

// Track attribute definition flags for conditional field visibility
const isLocalizable = ref(false)
const isScopable = ref(false)

// Determine if a field should be visible
function isFieldVisible(field: any): boolean {
  // locale field is only visible when attribute is localizable
  if (field.name === 'locale') {
    return isLocalizable.value
  }
  // market field is only visible when attribute is scopable
  if (field.name === 'market') {
    return isScopable.value
  }
  // unit field is only visible when attribute type is measure
  if (field.name === 'unit') {
    return localFormData.value.type === 'measure'
  }
  return true
}

// Handle definition change from AttributeValueField
function onDefinitionChange(definition: { isLocalizable: boolean, isScopable: boolean } | null) {
  if (definition) {
    isLocalizable.value = definition.isLocalizable
    isScopable.value = definition.isScopable
  } else {
    isLocalizable.value = false
    isScopable.value = false
  }

  // Clear hidden field values
  if (!isLocalizable.value && localFormData.value.locale) {
    localFormData.value.locale = null
  }
  if (!isScopable.value && localFormData.value.market) {
    localFormData.value.market = null
  }
}

// Handle form data updates from custom components
function onFormDataUpdate(updates: Record<string, any>) {
  localFormData.value = { ...localFormData.value, ...updates }
  emit('update:modelValue', { ...localFormData.value })
}

// Watch for external changes
watch(() => props.modelValue, (newValue) => {
  if (JSON.stringify(newValue) !== JSON.stringify(localFormData.value)) {
    localFormData.value = { ...newValue }
  }
}, { deep: true })

// Emit changes
watch(localFormData, (newValue) => {
  if (JSON.stringify(newValue) !== JSON.stringify(props.modelValue)) {
    emit('update:modelValue', { ...newValue })
  }
}, { deep: true })
</script>
