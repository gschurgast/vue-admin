<template>
  <div>
    <v-alert type="info" class="mb-4" variant="tonal">
      <template v-slot:prepend>
        <v-icon>mdi-pencil</v-icon>
      </template>
      Custom Author Edit
    </v-alert>

    <ResourceForm
      v-model="localFormData"
      :fields="fields"
      :custom-components="customComponents"
      :relation-data="relationData"
      :loading-relations="loadingRelations"
      :field-errors="fieldErrors"
    />
  </div>
</template>

<script setup lang="ts">
import { ref, watch } from 'vue'
import ResourceForm from '../../resource/ResourceForm.vue'

interface Props {
  formData: Record<string, any>
  fields: Array<any>
  customComponents?: Record<string, any>
  relationData?: Record<string, any>
  loadingRelations?: Record<string, boolean>
  fieldErrors?: Record<string, string[]>
  resourceName?: string
}

const props = withDefaults(defineProps<Props>(), {
  customComponents: () => ({}),
  relationData: () => ({}),
  loadingRelations: () => ({}),
  fieldErrors: () => ({}),
  resourceName: ''
})

const emit = defineEmits<{
  'update:formData': [value: Record<string, any>]
}>()

const localFormData = ref({ ...props.formData })

// Watch for external changes
watch(() => props.formData, (newValue) => {
  if (JSON.stringify(newValue) !== JSON.stringify(localFormData.value)) {
    localFormData.value = { ...newValue }
  }
}, { deep: true })

// Emit changes
watch(localFormData, (newValue) => {
  if (JSON.stringify(newValue) !== JSON.stringify(props.formData)) {
    emit('update:formData', { ...newValue })
  }
}, { deep: true })
</script>
