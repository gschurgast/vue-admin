<template>
  <v-avatar :size="size">
    <v-img
      v-if="pictureUrl"
      :src="pictureUrl"
      cover
    />
    <v-icon v-else :size="size" color="grey-lighten-1">{{ fallbackIcon }}</v-icon>
  </v-avatar>
</template>

<script setup>
import { computed } from 'vue'

const props = defineProps({
  item: {
    type: Object,
    required: true
  },
  value: {
    type: String,
    default: null
  },
  size: {
    type: [Number, String],
    default: 40
  },
  fallbackIcon: {
    type: String,
    default: 'mdi-image'
  }
})

const pictureUrl = computed(() => {
  if (!props.value) return null
  // If value is already a full URL, return as is
  if (props.value.startsWith('http://') || props.value.startsWith('https://')) {
    return props.value
  }
  // Otherwise, prepend the API base URL
  const baseUrl = import.meta.env.VITE_API_URL || 'http://localhost:8080'
  return `${baseUrl}${props.value}`
})
</script>
