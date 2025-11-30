<template>
  <div>
    <span>{{ formattedDate }}</span>
  </div>
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
  if (!props.value) return ''
  return new Date(props.value).toLocaleDateString()
})
</script>
