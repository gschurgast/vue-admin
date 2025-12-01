<template>
  <v-list>
    <v-list-item
      v-for="field in fields"
      :key="field.name"
      class="mb-2"
    >
      <v-list-item-title class="text-caption text-grey">
        {{ field.label || field.name }}
      </v-list-item-title>
      <v-list-item-subtitle class="text-body-1 mt-1">
        <!-- Custom component -->
        <component
          v-if="field.component && customComponents[`show/${field.component}`]"
          :is="customComponents[`show/${field.component}`]"
          :value="getFieldValue(item, field.name)"
          :item="item"
          :field="field"
        />
        
        <!-- Default display -->
        <span v-else>{{ formatValue(getFieldValue(item, field.name), field) }}</span>
      </v-list-item-subtitle>
    </v-list-item>
  </v-list>
</template>

<script setup lang="ts">
import { useI18n } from 'vue-i18n'

const props = withDefaults(defineProps<{
  item: any
  fields: any[]
  customComponents?: Record<string, any>
  resourceName?: string
}>(), {
  customComponents: () => ({}),
  resourceName: ''
})

const { t } = useI18n()

function getFieldValue(item: any, fieldName: string) {
  return item[fieldName]
}

function formatValue(value: any, field: any) {
  if (value === null || value === undefined) return '-'
  
  // Handle dates
  if (field.type === 'date' || field.type === 'datetime') {
    return new Date(value).toLocaleDateString()
  }
  
  // Handle booleans
  if (typeof value === 'boolean') {
    return value ? t('common.yes') : t('common.no')
  }
  
  // Handle arrays
  if (Array.isArray(value)) {
    return value.join(', ')
  }
  
  // Handle objects (relations)
  if (typeof value === 'object') {
    return value.name || value.title || value.id || JSON.stringify(value)
  }
  
  return String(value)
}
</script>
