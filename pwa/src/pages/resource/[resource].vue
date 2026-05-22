<template>
  <ResourceAppBar :breadcrumbs="breadcrumbs">
    <template v-if="resource && !isForbidden && resourceMessagesLoaded" #actions>
      <PageActionBtn
        kind="secondary"
        :prepend-icon="showSearchForm ? 'mdi-filter-off-outline' : 'mdi-filter-outline'"
        @click="showSearchForm = !showSearchForm"
      >
        {{ showSearchForm ? t('common.hideFilters') : t('common.showFilters') }}
      </PageActionBtn>
      <PageActionBtn kind="primary" prepend-icon="mdi-plus" @click="createItem">
        {{ t('common.create') }}
      </PageActionBtn>
    </template>
  </ResourceAppBar>

 <v-container fluid>
  <!-- Loading state until resource messages are loaded -->
  <v-card v-if="!resourceMessagesLoaded" class="text-center pa-10">
   <v-progress-circular indeterminate color="primary"/>
   <p class="mt-4">{{ t('common.loading') }}</p>
  </v-card>

  <template v-else>
   <!-- 404 Error for non-existent resource -->
   <ResourceNotFound
       v-if="!resource && !resourcesStore.loading"
       :resource-name="resourceName"
   />

   <!-- 403 Error for forbidden resource -->
   <ResourceForbidden
       v-else-if="isForbidden"
       :resource-name="resourceName"
   />

   <!-- Main content - only show if resource exists and not forbidden -->
   <template v-if="resource && !isForbidden">
    <component
        :is="ListComponent"
        :items="items"
        :headers="headersWithCellType"
        :loading="loading"
        :items-per-page="itemsPerPage"
        :custom-components="customComponents"
        :relation-data="relationData"
        :relations-loaded="relationsLoaded"
        :resource-name="resourceName"
        :title="resourceTitle"
        @view="showItem"
        @edit="editItem"
        @delete="confirmDelete"
    >
      <template #filters>
        <component
            :is="FilterComponent"
            v-model="showSearchForm"
            :filter-fields="filterFields"
            :filters="searchFilters"
            :custom-components="customComponents"
            :resource-name="resourceName"
            @update:filters="searchFilters = $event"
            @search="performSearch"
            @clear="clearSearch"
        />
      </template>
    </component>
   </template>

   <!-- Delete Confirmation Dialog -->
   <ResourceDelete
       v-model="showDeleteDialog"
       :resource-title="resourceTitle"
       @confirm="deleteItem"
       @cancel="showDeleteDialog = false"
   />

   <v-snackbar v-model="snackbar.show" :color="snackbar.color">
    {{ snackbar.message }}
    <template v-slot:actions>
     <v-btn @click="snackbar.show = false">
      <v-icon icon="mdi-close"></v-icon>
     </v-btn>
    </template>
   </v-snackbar>
  </template>
 </v-container>
</template>

<script setup lang="ts">
import {ref, computed, onMounted, watch, shallowRef, markRaw} from 'vue'
import {useResource} from '../../composables/useResource'
import apiPlatform from '../../services/apiPlatform'

// Resource components
import ResourceFilter from '../../components/resource/ResourceFilter.vue'
import ResourceList from '../../components/resource/ResourceList.vue'
import ResourceDelete from '../../components/resource/ResourceDelete.vue'
import ResourceNotFound from '../../components/common/ResourceNotFound.vue'
import ResourceForbidden from '../../components/common/ResourceForbidden.vue'
import ResourceAppBar from '../../components/resource/ResourceAppBar.vue'
import PageActionBtn from '../../components/common/PageActionBtn.vue'

// Pre-load component modules using import.meta.glob for Vite compatibility
const fieldComponents = import.meta.glob('../../components/fields/*.vue')
const listComponents = import.meta.glob('../../components/list/*.vue')
const filterComponents = import.meta.glob('../../components/filters/*.vue')
const configFiles = import.meta.glob('../../config/*.json')
const resourceViewComponents = import.meta.glob('../../components/*/*/**.vue')

const componentModules: Record<string, () => Promise<any>> = {
 ...Object.fromEntries(Object.entries(fieldComponents).map(([k, v]) => [`fields/${k.split('/').pop()?.replace('.vue', '')}`, v])),
 ...Object.fromEntries(Object.entries(listComponents).map(([k, v]) => [`list/${k.split('/').pop()?.replace('.vue', '')}`, v])),
 ...Object.fromEntries(Object.entries(filterComponents).map(([k, v]) => [`filters/${k.split('/').pop()?.replace('.vue', '')}`, v]))
}

const {
 resourceName,
 resource,
 resourcePath,
 resourceConfig,
 customComponents,
 snackbar,
 showSnackbar,
 resourcesStore,
 t,
 locale,
 loadCustomComponent,
 loadResourceConfigBase,
 loadResourceViewComponent,
 loadFieldComponents,
 loadResourceMessages,
 navigateToCreate,
 navigateToEdit,
 navigateToShow,
 getConfigFields,
 normalizeConfigItem,
 getFieldType
} = useResource({
 importComponent: (path) => {
  const loader = componentModules[path]
  if (loader) return loader()
  return Promise.reject(new Error(`Component not found: ${path}`))
 },
 importConfig: (name) => {
  const loader = configFiles[`../../config/${name}.json`]
  if (loader) return loader()
  return Promise.reject(new Error(`Config not found: ${name}`))
 },
 importViewComponent: (folder, type, name) => {
  const path = `../../components/${folder}/${type}/${name}.vue`
  const loader = resourceViewComponents[path]
  if (loader) return loader()
  return Promise.reject(new Error(`View component not found: ${path}`))
 }
})

const items = ref([])
const loading = ref(false)
const itemsPerPage = ref(10)
const showDeleteDialog = ref(false)
const itemToDelete = ref(null)
const showSearchForm = ref(false)
const searchFilters = ref<Record<string, any>>({})
const relationData = ref<Record<string, any>>({})
const loadingRelations = ref<Record<string, boolean>>({})
const relationsLoaded = ref(false)
const initialLoadDone = ref(false)
const resourceMessagesLoaded = ref(false)
const isForbidden = ref(false)

const resourceTitle = computed(() => {
 if (!resourceName.value) return ''
 if (!resourceMessagesLoaded.value && initialLoadDone.value) return ''
 const translationKey = `resources.${String(resourceName.value).toLowerCase()}.name`
 return t(translationKey, resource.value?.title || resourceName.value)
})

const breadcrumbs = computed(() => [
 {
  title: t('navigation.home'),
  disabled: false,
  to: '/'
 },
 {
  title: resourceTitle.value,
  disabled: true
 }
])

// Dynamic components for List and Filter
const ListComponent = shallowRef(ResourceList)
const FilterComponent = shallowRef(ResourceFilter)

// Reset state synchronously when resource changes to prevent stale data/warnings
watch(resourceName, () => {
 if (initialLoadDone.value) {
  resourceMessagesLoaded.value = false
  resourceConfig.value = null
  isForbidden.value = false
 }
}, {flush: 'sync'})

// Load resource-specific configuration if it exists
async function loadResourceConfig() {
 ListComponent.value = ResourceList
 FilterComponent.value = ResourceFilter

 await loadResourceConfigBase()

 if (resourceConfig.value) {
  // Load custom List component
  const customList = await loadResourceViewComponent('list')
  if (customList) ListComponent.value = customList

  // Load custom Filter component
  const customFilter = await loadResourceViewComponent('filter')
  if (customFilter) FilterComponent.value = customFilter

  // Load field components for all sections
  await Promise.all([
   loadFieldComponents('list', 'list'),
   loadFieldComponents('edit', 'fields'),
   loadFieldComponents('filters', 'filters')
  ])
 }
}

const searchableFields = computed(() => {
 if (!resource.value) return []
 if (!resourceMessagesLoaded.value && initialLoadDone.value) return []

 let fields = resource.value.properties
     .filter(prop => {
      // Only include readable, non-relation fields
      if (prop.isRelation) return false
      if (!prop.readable) return false

      // Only string and text types are searchable
      const range = prop.property?.range
      return range?.includes('string') || range?.includes('text')
     })
     .map(prop => prop.property?.label || prop.title)

 // If config exists, only show fields in config and in that order
 if (resourceConfig.value?.search) {
  const configFields = resourceConfig.value.search
  fields = configFields.filter(fieldName => fields.includes(fieldName))
 }

 return fields
})

// Filter fields - supports custom filter config with type and label
const filterFields = computed(() => {
 if (!resourceMessagesLoaded.value && initialLoadDone.value) return []

 if (!resourceConfig.value?.filters) {
  // Fallback to searchable fields if no filter config
  return searchableFields.value.map(field => ({
   field,
   type: 'text',
   label: null,
   customComponent: null
  }))
 }

  // Use filters from config
  const configFilters = getConfigFields(resourceConfig.value.filters)
  return configFilters.map((filterItem: any) => {
   const normalized = normalizeConfigItem(filterItem)
   if (!normalized) return null

   const { field, value } = normalized

   let type = 'text'
   let component = null

   // If value starts with uppercase, assume component, else type
   if (value && /^[A-Z]/.test(value)) {
     component = value
   } else if (value) {
     type = value
   }

   return {
    field,
    type,
    label: null,
    customComponent: component
   }
  }).filter(Boolean)
})

const headers = computed(() => {
 if (!resource.value) return []
 if (!resourceMessagesLoaded.value && initialLoadDone.value) return []

 let cols = resource.value.properties
     .map((prop: any) => {
      const fieldName = prop.property?.label || prop.title?.toLowerCase()
      const translationKey = `resources.${String(resourceName.value).toLowerCase()}.fields.${fieldName}`
      return {
       title: t(translationKey, prop.title),
       key: fieldName,
       sortable: true,
       customComponent: null  // Will be set if config specifies
      }
     })

 // If config exists, only show columns in config and in that order
 if (resourceConfig.value?.list) {
  const configFields = getConfigFields(resourceConfig.value.list)
  cols = configFields
      .map((configItem: any) => {
       const normalized = normalizeConfigItem(configItem)
       if (!normalized) return undefined

       const { field: fieldName, value: componentName } = normalized
       const customComponent = componentName || null

       let col = cols.find((c: any) => c.key === fieldName)
       if (col) {
        if (customComponent) {
         col.customComponent = customComponent
        }
       } else {
        // Create column for fields not in resource properties (e.g., custom virtual fields)
        const translationKey = `resources.${String(resourceName.value).toLowerCase()}.fields.${fieldName}`
        col = {
         title: t(translationKey, fieldName),
         key: fieldName,
         sortable: false,
         customComponent
        }
       }
       return col
      })
      .filter((col: any) => col !== undefined)
 }

 cols.push({title: t('common.actions'), key: 'actions', sortable: false})
 return cols
})

// Add cell type information to headers for ResourceList component
const headersWithCellType = computed(() => {
 return headers.value.map((header: any) => {
  let cellType = 'text' // default

  if (header.key === 'actions') {
   cellType = 'actions'
  } else {
   // Check if this field is a date or datetime
   const dateField = editableFields.value.find((f: any) => f.name === header.key && (f.type === 'date' || f.type === 'datetime'))
   if (dateField) {
    cellType = dateField.type === 'datetime' ? 'datetime' : 'date'
   }

   // Check if this field is a boolean
   const booleanField = editableFields.value.find((f: any) => f.name === header.key && f.type === 'boolean')
   if (booleanField) {
    cellType = 'boolean'
   }

   // Check if this field is a relation
   if (relationFields.value.includes(header.key)) {
    cellType = 'relation'
   }
  }

  return {
   ...header,
   cellType
  }
 })
})

const editableFields = computed(() => {
 if (!resource.value) return []
 if (!resourceMessagesLoaded.value && initialLoadDone.value) return []

 let fields = resource.value.properties
     .filter(prop => {
      if (!prop.writeable) return false

      // Skip collection relations (OneToMany/ManyToMany) - they need special UI
      // Collection relations don't have owl:maxCardinality set to 1
      if (prop.isRelation) {
       const maxCardinality = prop.property?.['owl:maxCardinality']
       if (maxCardinality !== 1) {
        return false // Skip collections for now
       }
      }

      return true
     })
     .map((prop: any) => {
      const fieldName = prop.property?.label || prop.title
      const translationKey = `resources.${String(resourceName.value).toLowerCase()}.fields.${fieldName}`
      return {
       name: fieldName,
       label: t(translationKey, prop.title),
       type: getFieldType(prop),
       required: prop.required || false,
       isRelation: prop.isRelation,
       relatedResource: prop.relatedResource,
       customComponent: null
      }
     })

 // If config exists, only show fields in config and in that order
 if (resourceConfig.value?.edit) {
  const configFields = getConfigFields(resourceConfig.value.edit)
  fields = configFields
      .map((configItem: any) => {
       const normalized = normalizeConfigItem(configItem)
       if (!normalized) return undefined

       const { field: fieldName, value: componentName } = normalized
       const customComponent = componentName || null

       const field = fields.find(f => f.name === fieldName)
       if (field && customComponent) {
        field.customComponent = customComponent
        // Override type based on component name for built-in field components
        if (customComponent === 'DateField') {
         field.type = 'date'
        } else if (customComponent === 'DateTimeField') {
         field.type = 'datetime'
        }
       }
       return field
      })
      .filter(field => field !== undefined)
 }

 return fields
})

const relationFields = computed(() => {
 if (!resource.value) return []
 return resource.value.properties
     .filter((prop: any) => prop.isRelation)
     .map((prop: any) => prop.property?.label || prop.title)
})


// Automatically load related resources based on current items
async function loadRelations() {
 if (!resource.value || items.value.length === 0) return

 relationsLoaded.value = false

 // Get relation fields from resource properties
 const relations = resource.value.properties
     .filter(prop => prop.isRelation)

 // For each relation field, extract unique IDs from current items
 for (const relationField of relations) {
  const relatedResource = relationField.relatedResource
  if (!relatedResource) continue

  // Extract IRIs from current items for this relation field
  const fieldName = relationField.property?.label || relationField.title?.toLowerCase()
  const iris = new Set<string>()

  items.value.forEach(item => {
   const value = item[fieldName]
   if (value) {
    if (typeof value === 'string' && value.startsWith('/api/')) {
     iris.add(value)
    } else if (Array.isArray(value)) {
     value.forEach(v => {
      if (typeof v === 'string' && v.startsWith('/api/')) {
       iris.add(v)
      }
     })
    }
   }
  })

  // Skip if no IDs found
  if (iris.size === 0) continue

  // Extract numeric IDs from IRIs (e.g., "/api/authors/1" -> "1")
  const ids = Array.from(iris).map(iri => {
   const parts = iri.split('/')
   return parts[parts.length - 1]
  }).filter(Boolean)

  // Skip if already loaded these specific items
  const existingIds = new Set(
      (relationData.value[relatedResource] || []).map((item: any) => {
       const iri = item['@id']
       const parts = iri.split('/')
       return parts[parts.length - 1]
      })
  )

  const newIds = ids.filter(id => !existingIds.has(id))
  if (newIds.length === 0) continue

  loadingRelations.value[relatedResource] = true
  try {
   const path = apiPlatform.getResourcePath(relatedResource)

   // Fetch only the specific IDs we need using API Platform's id[] filter
   const result = await apiPlatform.getList(path, {
    'id[]': newIds,
    itemsPerPage: newIds.length
   })

   // Merge with existing data
   relationData.value = {
    ...relationData.value,
    [relatedResource]: [
     ...(relationData.value[relatedResource] || []),
     ...result.data
    ]
   }
  } catch (error) {
   console.error(`Failed to load ${relatedResource}:`, error)
  } finally {
   loadingRelations.value[relatedResource] = false
  }
 }

 relationsLoaded.value = true
}

async function loadData(searchFilters = {}) {
 if (!resource.value) return

 // Skip the default paginated fetch when a custom list component handles its own data
 if (resourceConfig.value?.list?.standalone) {
  items.value = []
  loading.value = false
  return
 }

 loading.value = true
 isForbidden.value = false
 try {
  const params = {
   page: 1,
   itemsPerPage: itemsPerPage.value,
   ...searchFilters
  }
  const result = await apiPlatform.getList(resourcePath.value, params)
  items.value = result.data

  // Load relations after items are loaded
  await loadRelations()
 } catch (error: any) {
  if (error.response?.status === 403) {
   isForbidden.value = true
  } else {
   showSnackbar('Failed to load data', 'error')
  }
 } finally {
  loading.value = false
 }
}

function performSearch() {
 // Build search filters only from fields that have values
 const filters = {}
 Object.keys(searchFilters.value).forEach(key => {
  const value = searchFilters.value[key]

  // Handle date range objects (from DateRangeFilter)
  if (value && typeof value === 'object' && (value.after || value.before)) {
   if (value.after) {
    filters[`${key}[after]`] = value.after
   }
   if (value.before) {
    filters[`${key}[before]`] = value.before
   }
  }
  // Handle single date values (from DateFilter) - create a range for the entire day
  else if (value && typeof value === 'string' && value.trim() !== '') {
   // Check if this is a date filter
   const filterField = filterFields.value.find(f => f.field === key)
   if (filterField && filterField.type === 'date') {
    // Single date filter: create a range for the entire day
    // [after] = start of day (2025-11-18T00:00:00)
    // [before] = end of day (2025-11-18T23:59:59)
    const startOfDay = `${value}T00:00:00`
    const endOfDay = `${value}T23:59:59`
    filters[`${key}[after]`] = startOfDay
    filters[`${key}[before]`] = endOfDay
   } else {
    // Regular text filter
    filters[key] = value
   }
  }
 })

 loadData(filters)
}

function clearSearch() {
 searchFilters.value = {}
 loadData()
}

function createItem() {
 navigateToCreate()
}

function showItem(item) {
 navigateToShow(item.id)
}

function editItem(item) {
 navigateToEdit(item.id)
}

function confirmDelete(item) {
 itemToDelete.value = item
 showDeleteDialog.value = true
}

async function deleteItem() {
 try {
  await apiPlatform.delete(resourcePath.value, itemToDelete.value.id)
  showSnackbar('Item deleted successfully')
  showDeleteDialog.value = false
  loadData()
 } catch (error) {
  showSnackbar('Failed to delete item', 'error')
 }
}

// Watch the resource object, not just the name
watch(resource, async (newResource, oldResource) => {
 // Skip if initial load hasn't completed yet
 if (!initialLoadDone.value) return

 // Skip if resource name hasn't actually changed
 if (oldResource?.name === newResource?.name) return

 if (!newResource) return

 // Reset and reload when resource changes
 resourceMessagesLoaded.value = false // Start loading state
 relationData.value = {}
 relationsLoaded.value = false

 // Save to localStorage for preloading on refresh
 localStorage.setItem('last_resource', newResource.name)

 await loadResourceMessages(newResource.name, locale.value)
 resourceMessagesLoaded.value = true // End loading state

 await loadResourceConfig()
 await loadData()
})

// Watch for locale changes to reload resource messages
watch(locale, async (newLocale) => {
 if (resourceName.value && typeof resourceName.value === 'string') {
  // Set loading state while translations load
  resourceMessagesLoaded.value = false
  await loadResourceMessages(resourceName.value, newLocale)
  resourceMessagesLoaded.value = true
 }
})

onMounted(async () => {
 // Ensure resources are loaded in the store first
 await resourcesStore.loadResources()

 // Load translations for this resource
 if (resourceName.value && typeof resourceName.value === 'string') {
  // Save to localStorage for preloading on refresh
  localStorage.setItem('last_resource', resourceName.value)
  await loadResourceMessages(resourceName.value, locale.value)
 }

 // Mark resource messages as loaded
 resourceMessagesLoaded.value = true

 // Load resource config
 await loadResourceConfig()

 // Load initial data (also loads relations)
 await loadData()

 // Mark as loaded so watch can take over for subsequent changes
 initialLoadDone.value = true
})
</script>
