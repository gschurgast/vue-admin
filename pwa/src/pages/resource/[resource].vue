<template>
 <v-app-bar density="compact" color="blue-grey-darken-1">
  <v-app-bar-title>{{ resourceTitle }}</v-app-bar-title>
  <template
      v-if="resource"
      v-slot:append>

   <!-- Filter -->
   <v-btn icon density="compact" size="small" class="mr-2" @click="showSearchForm = !showSearchForm">
    <v-icon>mdi-filter</v-icon>
    <v-tooltip activator="parent" location="bottom">{{ showSearchForm ? t('common.hideFilters') : t('common.showFilters') }}</v-tooltip>
   </v-btn>

   <!-- Create New -->
   <v-btn icon density="compact" size="small" class="mr-2" @click="createItem">
    <v-icon>mdi-plus</v-icon>
    <v-tooltip activator="parent" location="bottom">
     Create New
    </v-tooltip>
   </v-btn>
  </template>


 </v-app-bar>

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

   <!-- Main content - only show if resource exists -->
   <template v-if="resource">
    <!-- Search Form -->
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
        @view="showItem"
        @edit="editItem"
        @delete="confirmDelete"
    />
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
import {useRoute, useRouter} from 'vue-router'
import {useI18n} from 'vue-i18n'
import apiPlatform from '../../services/apiPlatform'
import {loadResourceMessages} from '../../plugins/i18n'
import {useResourcesStore} from '../../stores/resources'

// Resource components
import ResourceFilter from '../../components/resource/ResourceFilter.vue'
import ResourceList from '../../components/resource/ResourceList.vue'
import ResourceDelete from '../../components/resource/ResourceDelete.vue'
import ResourceNotFound from '../../components/common/ResourceNotFound.vue'

const route = useRoute()
const router = useRouter()
const resourcesStore = useResourcesStore()
const {t, locale} = useI18n()

// Custom component cache
const customComponents = ref<Record<string, any>>({})

// Helper to get fields from config (supports object with fields property)
function getConfigFields(config: any) {
  if (config && typeof config === 'object' && Array.isArray(config.fields)) return config.fields
  return []
}

// Helper to normalize config item (shorthand { field: value } -> { field, value })
function normalizeConfigItem(item: any) {
  if (typeof item !== 'object' || item === null) return null
  
  const keys = Object.keys(item)
  if (keys.length !== 1) return null
  
  const field = keys[0]
  const value = item[field]
  
  return { field, value }
}

// Function to load custom component dynamically
async function loadCustomComponent(componentName: string, type = 'list') {
 const cacheKey = `${type}/${componentName}`

 if (customComponents.value[cacheKey]) {
  return customComponents.value[cacheKey]
 }

 try {
  const component = await import(`../../components/${type}/${componentName}.vue`)
  customComponents.value[cacheKey] = markRaw(component.default || component)
  return customComponents.value[cacheKey]
 } catch (error) {
  console.warn(`Failed to load custom component: ${componentName}`, error)
  return null
 }
}


const resourceName = computed(() => {
 const name = route.params.resource
 return Array.isArray(name) ? name[0] : name
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
const resourceConfig = ref<any>(null)
const resourceMessagesLoaded = ref(false)

// Dynamic components for List and Filter
const ListComponent = shallowRef(ResourceList)
const FilterComponent = shallowRef(ResourceFilter)

// Reset state synchronously when resource changes to prevent stale data/warnings
watch(resourceName, () => {
 if (initialLoadDone.value) {
  resourceMessagesLoaded.value = false
  resourceConfig.value = null
 }
}, {flush: 'sync'})

// Load resource-specific configuration if it exists
async function loadResourceConfig() {
 if (!resourceName.value) return

 try {
  // Dynamically import the config file for this resource
  const config = await import(`../../config/${resourceName.value}.json`)
  resourceConfig.value = config.default || config

  // Pre-load any custom components specified in the config
  const componentsToLoad: Promise<any>[] = []
  
  // Reset to default components
  ListComponent.value = ResourceList
  FilterComponent.value = ResourceFilter

  if (resourceConfig.value) {
    // Check for custom List component
    if (resourceConfig.value.list && resourceConfig.value.list.component) {
      const componentName = resourceConfig.value.list.component
      const resourceFolder = String(resourceName.value).toLowerCase()
      try {
        // Try loading from resource-specific folder: components/[resource]/list/[Name].vue
        const component = await import(`../../components/${resourceFolder}/list/${componentName}.vue`)
        ListComponent.value = markRaw(component.default || component)
      } catch (e) {
        // Fallback to generic location if needed, or just log warning
        console.warn(`Failed to load custom list component: ${resourceFolder}/list/${componentName}`, e)
      }
    }

    // Check for custom Filter component
    if (resourceConfig.value.filters && resourceConfig.value.filters.component) {
      const componentName = resourceConfig.value.filters.component
      const resourceFolder = String(resourceName.value).toLowerCase()
      try {
        // Try loading from resource-specific folder: components/[resource]/filter/[Name].vue
        const component = await import(`../../components/${resourceFolder}/filter/${componentName}.vue`)
        FilterComponent.value = markRaw(component.default || component)
      } catch (e) {
        console.warn(`Failed to load custom filter component: ${resourceFolder}/filter/${componentName}`, e)
      }
    }
    
    const sections = [
      { name: 'list', type: 'list' },
      { name: 'edit', type: 'fields' },
      { name: 'filters', type: 'filters' }
    ]

    sections.forEach(section => {
      const fields = getConfigFields(resourceConfig.value[section.name])
      if (fields.length > 0) {
        fields.forEach((item: any) => {
          const normalized = normalizeConfigItem(item)
          if (normalized) {
            const { value } = normalized
            // If value starts with uppercase (Component) or is not empty (for filters), load it
            if (value && /^[A-Z]/.test(value)) {
              componentsToLoad.push(loadCustomComponent(value, section.type))
            }
          }
        })
      }
    })
  }

  // Load all custom components in parallel
  await Promise.all(componentsToLoad)

 } catch (error) {
  // Config file doesn't exist - use default behavior
  resourceConfig.value = null
 }
}

const snackbar = ref({
 show: false,
 message: '',
 color: 'success'
})

const resource = computed(() => {
 return resourcesStore.getResourceByName(resourceName.value)
})

const resourceTitle = computed(() => {
 if (!resourceName.value) return ''
 // Prevent translation attempts while loading new resource messages
 if (!resourceMessagesLoaded.value && initialLoadDone.value) return ''

 const translationKey = `resources.${String(resourceName.value).toLowerCase()}.name`
 return t(translationKey, resource.value?.title || resourceName.value)
})

const resourcePath = computed(() => {
 return apiPlatform.getResourcePath(resourceName.value)
})

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

       const col = cols.find((c: any) => c.key === fieldName)
       if (col && customComponent) {
        col.customComponent = customComponent
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
      const range = prop.property?.range
      let type = 'string'

      if (prop.isRelation) {
       type = 'relation'
      } else if (range?.includes('boolean')) {
       type = 'boolean'
      } else if (range?.includes('text')) {
       type = 'textarea'
      } else if (range?.includes('dateTime')) {
       type = 'datetime'
      } else if (range?.includes('date')) {
       type = 'date'
      }

      const fieldName = prop.property?.label || prop.title
      const translationKey = `resources.${String(resourceName.value).toLowerCase()}.fields.${fieldName}`
      return {
       name: fieldName,
       label: t(translationKey, prop.title),
       type,
       required: prop.required || false,
       isRelation: prop.isRelation,
       relatedResource: prop.relatedResource,
       customComponent: null  // Will be set if config specifies
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

 // Get relation fields
 const relationFields = resource.value.properties
     .filter(prop => prop.isRelation)

 // For each relation field, extract unique IDs from current items
 for (const relationField of relationFields) {
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

 loading.value = true
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
 } catch (error) {
  showSnackbar('Failed to load data', 'error')
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
  router.push(`/edit/${resourceName.value}/new`)
}

function showItem(item) {
  router.push(`/show/${resourceName.value}/${item.id}`)
}

function editItem(item) {
  router.push(`/edit/${resourceName.value}/${item.id}`)
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

function formatDate(date) {
 if (!date) return ''
 return new Date(date).toLocaleDateString()
}

function showSnackbar(message, color = 'success') {
 snackbar.value = {show: true, message, color}
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
 await loadRelations()
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

 // Load initial data
 await loadData()

 // Load relations
 await loadRelations()

 // Mark as loaded so watch can take over for subsequent changes
 initialLoadDone.value = true
})
</script>
