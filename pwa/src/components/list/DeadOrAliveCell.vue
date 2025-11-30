<template>
  <v-icon :color="value ? 'success' : 'error'">
    {{ value ? 'mdi-emoticon-happy' : 'mdi-emoticon-dead' }}
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
