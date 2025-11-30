<template>
  <v-icon :color="value ? 'success' : 'error'">
    {{ value ? 'mdi-check-circle' : 'mdi-close-circle' }}
  </v-icon>
</template>

<script setup>
defineProps({
  item: {
    type: Object,
    required: true
  },
  value: {
    type: Boolean,
    default: false
  }
})
</script>
