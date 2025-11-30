<template>
  <v-card-text>
    <v-data-table
      :headers="headers"
      :items="items"
      :loading="loading"
      :items-per-page="itemsPerPage"
      class="elevation-1"
    >
      <!-- Custom cell rendering for each column -->
      <template v-for="header in headers" :key="header.key" #[`item.${header.key}`]="{ item, value }">
        <!-- Actions column -->
        <template v-if="header.key === 'actions'">
          <v-btn
            v-if="canEdit"
            icon="mdi-pencil"
            variant="text"
            size="small"
            @click="handleEdit(item)"
          />
          <v-btn
            v-if="canDelete"
            icon="mdi-delete"
            variant="text"
            size="small"
            color="error"
            @click="handleDelete(item)"
          />
        </template>
        
        <!-- Custom component -->
        <component
          v-else-if="header.customComponent && customComponents[`list/${header.customComponent}`]"
          :is="customComponents[`list/${header.customComponent}`]"
          :value="value"
          :item="item"
          :header="header"
          :relation-data="relationData"
          :relations-loaded="relationsLoaded"
        />
        
        <!-- Date/DateTime cell -->
        <DateTimeCell
          v-else-if="header.cellType === 'date' || header.cellType === 'datetime'"
          :value="value"
          :item="item"
        />
        
        <!-- Boolean cell -->
        <BooleanCell
          v-else-if="header.cellType === 'boolean'"
          :value="value"
          :item="item"
        />
        
        <!-- Relation cell -->
        <RelationCell
          v-else-if="header.cellType === 'relation'"
          :value="value"
          :item="item"
          :relation-data="relationData"
          :relations-loaded="relationsLoaded"
        />
        
        <!-- Default text rendering -->
        <span v-else>{{ value }}</span>
      </template>
    </v-data-table>
  </v-card-text>
</template>

<script setup lang="ts">
import { computed } from 'vue'
import apiPlatform from '../../services/apiPlatform'
import DateTimeCell from '../list/DateTimeCell.vue'
import BooleanCell from '../list/BooleanCell.vue'
import RelationCell from '../list/RelationCell.vue'

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

const props = withDefaults(defineProps<Props>(), {
  customComponents: () => ({}),
  relationData: () => ({}),
  relationsLoaded: false,
  resourceName: ''
})

const emit = defineEmits<{
  'edit': [item: any]
  'delete': [item: any]
}>()

const canEdit = computed(() => {
  if (!props.resourceName) return true
  return apiPlatform.hasItemOperation(props.resourceName, 'PUT') || apiPlatform.hasItemOperation(props.resourceName, 'PATCH')
})

const canDelete = computed(() => {
  if (!props.resourceName) return true
  return apiPlatform.hasItemOperation(props.resourceName, 'DELETE')
})

function handleEdit(item: any) {
  emit('edit', item)
}

function handleDelete(item: any) {
  emit('delete', item)
}
</script>
