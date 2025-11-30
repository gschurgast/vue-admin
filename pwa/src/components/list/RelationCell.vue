<template>
  <span>{{ displayValue }}</span>
</template>

<script setup>
import { computed } from 'vue'

const props = defineProps({
  item: {
    type: Object,
    required: true
  },
  value: {
    type: [Object, String, Array],
    default: null
  },
  relationData: {
    type: Object,
    default: () => ({})
  },
  relationsLoaded: {
    type: Boolean,
    default: false
  }
})

const displayValue = computed(() => {
  if (!props.value) return 'N/A'
  
  // If it's an array (OneToMany/ManyToMany relation)
  if (Array.isArray(props.value)) {
    if (props.value.length === 0) return 'None'
    return `${props.value.length} item${props.value.length !== 1 ? 's' : ''}`
  }
  
  // If it's an object with name or title, return it
  if (typeof props.value === 'object') {
    if (props.value.name) return props.value.name
    if (props.value.title) return props.value.title
  }
  
  // If it's an IRI string, look it up in relationData
  if (typeof props.value === 'string' && props.value.startsWith('/api/')) {
    if (!props.relationsLoaded) return 'Loading...'
    
    // Extract resource type and find in relationData
    for (const [resourceType, items] of Object.entries(props.relationData)) {
      const found = items.find(item => item['@id'] === props.value)
      if (found) {
        return found.name || found.title || 'N/A'
      }
    }
  }
  
  return 'N/A'
})
</script>
