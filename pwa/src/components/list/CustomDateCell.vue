<template>
  <span class="custom-date">
    <v-icon size="small" class="mr-1">mdi-calendar</v-icon>
    {{ formattedDate }}
  </span>
</template>

<script setup>
import { computed } from 'vue'

const props = defineProps({
  item: {
    type: Object,
    required: true
  },
  value: {
    type: [String, Date],
    default: null
  }
})

const formattedDate = computed(() => {
  if (!props.value) return 'Not published'
  
  const date = new Date(props.value)
  const options = { year: 'numeric', month: 'short', day: 'numeric' }
  return date.toLocaleDateString('en-US', options)
})
</script>

<style scoped>
.custom-date {
  color: #1976d2;
  font-weight: 500;
}
</style>
