<template>
  <v-card class="resource-list-card">
    <div v-if="title || $slots.actions" class="list-header d-flex align-center pa-4">
      <h2 v-if="title" class="text-h6 font-weight-semibold">{{ title }}</h2>
      <v-spacer />
      <slot name="actions" />
    </div>
    <slot name="filters" />
    <v-divider v-if="title || $slots.actions || $slots.filters" />
    <v-data-table
      :headers="headers"
      :items="items"
      :loading="loading"
      :items-per-page="itemsPerPage"
      :items-per-page-text="t('resource.itemsPerPage')"
      :page-text="pageText"
      :hover="true"
      class="resource-list-table"
    >
      <!-- Custom cell rendering for each column -->
      <template v-for="header in headers" :key="header.key" #[`item.${header.key}`]="{ item, value }">
        <!-- Actions column -->
        <template v-if="header.key === 'actions'">
          <v-btn
            v-if="canView"
            icon="mdi-eye"
            variant="text"
            size="small"
            @click="handleView(item)"
          />
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
  </v-card>
</template>

<style scoped>
.resource-list-card {
  overflow: hidden;
}
:deep(.resource-list-table) {
  background-color: transparent;
}
:deep(.resource-list-table .v-data-table__thead),
:deep(.resource-list-table thead) {
  background-color: rgb(var(--v-theme-surface-light));
}
:deep(.resource-list-table thead th) {
  font-size: 0.75rem !important;
  font-weight: 600 !important;
  text-transform: uppercase;
  letter-spacing: 0.04em;
  color: rgb(var(--v-theme-on-surface-variant)) !important;
  border-bottom: none !important;
  padding-top: 12px !important;
  padding-bottom: 12px !important;
}
:deep(.resource-list-table tbody td) {
  border-bottom: none !important;
  padding-top: 10px !important;
  padding-bottom: 10px !important;
}
:deep(.resource-list-table tbody tr:not(:last-child) td) {
  box-shadow: inset 0 -1px 0 rgba(var(--v-theme-on-surface), 0.04);
}
:deep(.resource-list-table tbody tr:hover) {
  background-color: rgb(var(--v-theme-surface-light));
}
:deep(.resource-list-table .v-data-table-footer) {
  border-top: none !important;
  padding: 8px 16px;
}
</style>

<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import apiPlatform from '../../services/apiPlatform'
import DateTimeCell from '../list/DateTimeCell.vue'
import BooleanCell from '../list/BooleanCell.vue'
import RelationCell from '../list/RelationCell.vue'

const { t } = useI18n()

interface Props {
  items: Array<any>
  headers: Array<any>
  loading: boolean
  itemsPerPage: number
  customComponents?: Record<string, any>
  relationData?: Record<string, any>
  relationsLoaded?: boolean
  resourceName?: string
  title?: string
}

const props = withDefaults(defineProps<Props>(), {
  customComponents: () => ({}),
  relationData: () => ({}),
  relationsLoaded: false,
  resourceName: '',
  title: ''
})

const pageText = computed(() => {
  return `{0}-{1} ${t('resource.pageOf')} {2}`
})

const emit = defineEmits<{
  'view': [item: any]
  'edit': [item: any]
  'delete': [item: any]
}>()

const canView = computed(() => {
  if (!props.resourceName) return true
  return apiPlatform.hasItemOperation(props.resourceName, 'GET')
})

const canEdit = computed(() => {
  if (!props.resourceName) return true
  return apiPlatform.hasItemOperation(props.resourceName, 'PUT') || apiPlatform.hasItemOperation(props.resourceName, 'PATCH')
})

const canDelete = computed(() => {
  if (!props.resourceName) return true
  return apiPlatform.hasItemOperation(props.resourceName, 'DELETE')
})

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
