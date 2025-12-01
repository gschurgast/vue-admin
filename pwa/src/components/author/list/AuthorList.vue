<template>
  <div>
    <v-alert type="info" class="mb-4" variant="tonal">
      <template v-slot:prepend>
        <v-icon>mdi-check-circle</v-icon>
      </template>
      Custom Author List
    </v-alert>

    <v-row>
      <v-col cols="8">
        <ResourceList
      :items="items"
      :headers="headers"
      :loading="loading"
      :items-per-page="itemsPerPage"
      :custom-components="customComponents"
      :relation-data="relationData"
      :relations-loaded="relationsLoaded"
      :resource-name="resourceName"
      @view="handleView"
      @edit="handleEdit"
      @delete="handleDelete"
    />
      </v-col>
      <v-col cols="4">
        Infos complémentaires
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
  resourceName?: string
}

withDefaults(defineProps<Props>(), {
  customComponents: () => ({}),
  relationData: () => ({}),
  relationsLoaded: false,
  resourceName: ''
})

const emit = defineEmits<{
  'view': [item: any]
  'edit': [item: any]
  'delete': [item: any]
}>()

function handleView(item: any) {
  emit('view', item)
}

function handleEdit(item: any) {
  emit('edit', item)
}

function handleDelete(item: any) {
  emit('delete', item)
}
</script>
