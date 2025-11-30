<template>
  <v-form ref="formRef">
    <div v-for="field in fields" :key="field.name">
      <!-- Custom field component -->
      <component
        v-if="field.customComponent && customComponents[`fields/${field.customComponent}`]"
        :is="customComponents[`fields/${field.customComponent}`]"
        :field="field"
        v-model="localFormData[field.name]"
        :label="field.label"
        :error-messages="fieldErrors[field.name]"
      />
      
      <!-- Relation field -->
      <RelationField
        v-else-if="field.isRelation"
        :field="field"
        v-model="localFormData[field.name]"
        :items="relationData[field.relatedResource] || []"
        :label="field.label"
        :loading="loadingRelations[field.relatedResource]"
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
