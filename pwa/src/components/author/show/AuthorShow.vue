<template>
  <div>
   <v-alert type="info" class="mb-4" variant="tonal">
    <template v-slot:prepend>
     <v-icon>mdi-check-circle</v-icon>
    </template>
    Custom Author Show Loaded
   </v-alert>

    <ResourceShow
      :item="item"
      :fields="fields"
      :custom-components="customComponents"
      :resource-name="resourceName"
    />
  </div>
</template>

<script setup lang="ts">
import ResourceShow from '../../resource/ResourceShow.vue'

defineProps<{
  item: any
  fields: any[]
  customComponents?: Record<string, any>
  resourceName?: string
}>()
</script>
