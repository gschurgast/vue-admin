<template>
  <div>
    <v-btn icon size="small" @click="$emit('edit', item)" class="mr-2">
      <v-icon>mdi-pencil</v-icon>
    </v-btn>
    <v-btn icon size="small" color="error" @click="$emit('delete', item)">
      <v-icon>mdi-delete</v-icon>
    </v-btn>
  </div>
</template>

<script setup>
defineProps({
  item: {
    type: Object,
    required: true
  }
})

defineEmits(['edit', 'delete'])
</script>
