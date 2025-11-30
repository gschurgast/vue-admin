<template>
  <div>
    <v-alert type="success" class="mb-4" variant="tonal">
      <template v-slot:prepend>
        <v-icon>mdi-check-circle</v-icon>
      </template>
      Custom Author List Loaded from <strong>components/author/list/MyCustomList.vue</strong>
    </v-alert>

    <v-row>
      <v-col cols="6">
        <ResourceList
      :items="items"
      :headers="headers"
      :loading="loading"
      :items-per-page="itemsPerPage"
      :custom-components="customComponents"
      :relation-data="relationData"
      :relations-loaded="relationsLoaded"
      @edit="handleEdit"
      @delete="handleDelete"
    />
      </v-col>
      <v-col cols="6">
        dsds
      </v-col>
    </v-row>
    
    
  </div>
</template>

<script setup lang="ts">
import ResourceList from '../../resource/ResourceList.vue'

interface Props {
  items: Array<any>
  headers: Array<any>
  loading: boolean
  itemsPerPage: number
  customComponents?: Record<string, any>
  relationData?: Record<string, any>
  relationsLoaded?: boolean
}

withDefaults(defineProps<Props>(), {
  customComponents: () => ({}),
  relationData: () => ({}),
  relationsLoaded: false
})

const emit = defineEmits<{
  'edit': [item: any]
  'delete': [item: any]
}>()

function handleEdit(item: any) {
  emit('edit', item)
}

function handleDelete(item: any) {
  emit('delete', item)
}
</script>
