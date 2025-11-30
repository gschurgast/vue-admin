<template>
  <v-dialog v-model="localOpen" max-width="600px">
    <v-card>
      <v-card-title>
        {{ isEdit ? t('resource.edit', { resource: resourceTitle }) : t('resource.create', { resource: resourceTitle }) }}
      </v-card-title>
      <v-card-text>
        <ResourceForm
          v-model="localFormData"
          :fields="fields"
          :custom-components="customComponents"
          :relation-data="relationData"
          :loading-relations="loadingRelations"
          :field-errors="fieldErrors"
        />
      </v-card-text>
      <v-card-actions>
        <v-spacer></v-spacer>
        <v-btn variant="outlined" @click="handleClose">{{ t('common.cancel') }}</v-btn>
        <v-btn variant="outlined" color="primary" @click="handleSave">{{ t('common.save') }}</v-btn>
      </v-card-actions>
    </v-card>
  </v-dialog>
</template>

<script setup lang="ts">
import { ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import ResourceForm from './ResourceForm.vue'

interface Props {
  modelValue: boolean
  formData: Record<string, any>
  fields: Array<any>
  resourceTitle: string
  isEdit?: boolean
  customComponents?: Record<string, any>
  relationData?: Record<string, any>
  loadingRelations?: Record<string, boolean>
  fieldErrors?: Record<string, string[]>
}

const props = withDefaults(defineProps<Props>(), {
  isEdit: false,
  customComponents: () => ({}),
  relationData: () => ({}),
  loadingRelations: () => ({}),
  fieldErrors: () => ({})
})

const emit = defineEmits<{
  'update:modelValue': [value: boolean]
  'update:formData': [value: Record<string, any>]
  'save': [formData: Record<string, any>]
  'close': []
}>()

const { t } = useI18n()
const localOpen = ref(props.modelValue)
const localFormData = ref({ ...props.formData })

// Sync dialog state
watch(() => props.modelValue, (newValue) => {
  localOpen.value = newValue
})

watch(localOpen, (newValue) => {
  emit('update:modelValue', newValue)
})

// Sync form data
watch(() => props.formData, (newValue) => {
  if (JSON.stringify(newValue) !== JSON.stringify(localFormData.value)) {
    localFormData.value = { ...newValue }
  }
}, { deep: true })

watch(localFormData, (newValue) => {
  if (JSON.stringify(newValue) !== JSON.stringify(props.formData)) {
    emit('update:formData', { ...newValue })
  }
}, { deep: true })

function handleSave() {
  emit('save', { ...localFormData.value })
}

function handleClose() {
  emit('close')
  localOpen.value = false
}
</script>
