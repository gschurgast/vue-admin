<template>
  <ResourceAppBar :breadcrumbs="breadcrumbs" :loading="saving">
    <template #actions>
      <v-btn
        variant="text"
        size="small"
        class="mr-2"
        :disabled="saving"
        @click="handleCancel"
      >
        {{ t('common.cancel') }}
      </v-btn>
      <v-btn
        prepend-icon="mdi-content-save-outline"
        color="success"
        variant="flat"
        size="small"
        :loading="saving"
        @click="handleSave"
      >
        {{ t('common.save') }}
      </v-btn>
    </template>
  </ResourceAppBar>

  <v-container fluid>
    <!-- Loading state -->
    <v-card v-if="loading" class="text-center pa-10">
      <v-progress-circular indeterminate color="primary"/>
      <p class="mt-4">{{ t('common.loading') }}</p>
    </v-card>

    <!-- 403 Error for forbidden resource -->
    <ResourceForbidden
        v-else-if="isForbidden"
        :resource-name="resourceName"
    />

    <!-- Edit Form -->
    <v-card v-else>
      <v-card-text>
        <!-- Custom edit component -->
        <component
          v-if="EditComponent"
          :is="EditComponent"
          ref="editComponentRef"
          v-model:formData="formData"
          :fields="editableFields"
          :custom-components="customComponents"
          :relation-data="relationData"
          :loading-relations="loadingRelations"
          :field-errors="fieldErrors"
          :resource-name="resourceName"
        />
        
        <!-- Default ResourceForm -->
        <ResourceForm
          v-else
          v-model="formData"
          :fields="editableFields"
          :custom-components="customComponents"
          :relation-data="relationData"
          :loading-relations="loadingRelations"
          :field-errors="fieldErrors"
        />
      </v-card-text>
    </v-card>

    <v-snackbar v-model="snackbar.show" :color="snackbar.color">
      {{ snackbar.message }}
      <template v-slot:actions>
        <v-btn @click="snackbar.show = false">
          <v-icon icon="mdi-close"></v-icon>
        </v-btn>
      </template>
    </v-snackbar>
  </v-container>
</template>

<script setup lang="ts">
import { ref, computed, onMounted, shallowRef, watch } from 'vue'
import { useResource } from '../../../composables/useResource'
import { useAuthStore } from '../../../stores/auth'
import apiPlatform from '../../../services/apiPlatform'
import ResourceForm from '../../../components/resource/ResourceForm.vue'
import ResourceForbidden from '../../../components/common/ResourceForbidden.vue'
import ResourceAppBar from '../../../components/resource/ResourceAppBar.vue'

// Pre-load component modules using import.meta.glob for Vite compatibility
const fieldComponents = import.meta.glob('../../../components/fields/*.vue')
const configFiles = import.meta.glob('../../../config/*.json')
const resourceViewComponents = import.meta.glob('../../../components/*/*/**.vue')

const componentModules: Record<string, () => Promise<any>> = {
  ...Object.fromEntries(Object.entries(fieldComponents).map(([k, v]) => [`fields/${k.split('/').pop()?.replace('.vue', '')}`, v]))
}

const {
  resourceName,
  itemId,
  resource,
  resourceTitle,
  resourcePath,
  resourceConfig,
  customComponents,
  snackbar,
  showSnackbar,
  resourcesStore,
  t,
  locale,
  loadResourceConfigBase,
  loadResourceViewComponent,
  loadFieldComponents,
  loadResourceMessages,
  navigateToResource,
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
    const loader = configFiles[`../../../config/${name}.json`]
    if (loader) return loader()
    return Promise.reject(new Error(`Config not found: ${name}`))
  },
  importViewComponent: (folder, type, name) => {
    const path = `../../../components/${folder}/${type}/${name}.vue`
    const loader = resourceViewComponents[path]
    if (loader) return loader()
    return Promise.reject(new Error(`View component not found: ${path}`))
  }
})

const authStore = useAuthStore()

const isCreate = computed(() => itemId.value === 'new')

const loading = ref(true)
const saving = ref(false)
const formData = ref<Record<string, any>>({})
const fieldErrors = ref<Record<string, string[]>>({})
const relationData = ref({})
const loadingRelations = ref({})
const EditComponent = shallowRef(null)
const isForbidden = ref(false)
const editComponentRef = ref<any>(null)

const breadcrumbs = computed(() => [
  {
    title: t('common.home'),
    disabled: false,
    to: '/'
  },
  {
    title: resourceTitle.value,
    disabled: false,
    to: `/resource/${resourceName.value}`
  },
  {
    title: isCreate.value ? t('common.create') : t('common.edit'),
    disabled: true
  }
])

// Get field names that have custom components in config
function getConfiguredFieldNames(): Set<string> {
  const fieldNames = new Set<string>()
  if (resourceConfig.value?.edit) {
    const configFields = getConfigFields(resourceConfig.value.edit)
    for (const item of configFields) {
      const normalized = normalizeConfigItem(item)
      if (normalized?.value) {
        fieldNames.add(normalized.field)
      }
    }
  }
  return fieldNames
}

// Load resource config
async function loadResourceConfig() {
  await loadResourceConfigBase()
  EditComponent.value = null

  if (resourceConfig.value?.edit) {
    const customEdit = await loadResourceViewComponent('edit')
    if (customEdit) EditComponent.value = customEdit
    await loadFieldComponents('edit', 'fields')
  }
}

const editableFields = computed(() => {
  if (!resource.value) return []

  const customComponentFields = getConfiguredFieldNames()

  let fields = resource.value.properties
    .filter(prop => {
      if (!prop.writeable) return false
      if (prop.isRelation) {
        const maxCardinality = prop.property?.['owl:maxCardinality']
        // Allow collection relations if they have a custom component configured
        const fieldName = prop.property?.label || prop.title
        if (maxCardinality !== 1 && !customComponentFields.has(fieldName)) return false
      }
      return true
    })
    .map(prop => {
      const type = getFieldType(prop)

      // Determine the best display field for relation fields
      let itemTitle = 'name'
      if (prop.isRelation && prop.relatedResource) {
        const relatedRes = resourcesStore.getResourceByName(prop.relatedResource)
        if (relatedRes) {
          const relatedProps = relatedRes.properties.map(p => p.property?.label || p.title)
          const displayFieldPriority = ['name', 'title', 'label', 'code']
          for (const displayField of displayFieldPriority) {
            if (relatedProps.includes(displayField)) {
              itemTitle = displayField
              break
            }
          }
        }
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
        itemTitle,
        enumValues: prop.enumValues,
        customComponent: null
      }
    })

  if (resourceConfig.value?.edit) {
    const configFields = getConfigFields(resourceConfig.value.edit)
    const configuredFields = configFields
      .map(configItem => {
        const normalized = normalizeConfigItem(configItem)
        if (!normalized) return undefined
        const { field: fieldName, value: componentName } = normalized
        const customComponent = componentName || null
        const field = fields.find(f => f.name === fieldName)
        if (field) {
          // Create a copy and apply custom component if specified
          const fieldCopy = { ...field }
          if (customComponent) {
            fieldCopy.customComponent = customComponent
            // Override type based on component name for built-in field components
            if (customComponent === 'DateField') {
              fieldCopy.type = 'date'
            } else if (customComponent === 'DateTimeField') {
              fieldCopy.type = 'datetime'
            }
          }
          return fieldCopy
        }
        return undefined
      })
      .filter(field => field !== undefined)
    
    // Only use configured fields if we actually found some
    if (configuredFields.length > 0) {
      fields = configuredFields
    }
  }

  return fields
})

async function loadItem() {
  loading.value = true
  isForbidden.value = false

  if (isCreate.value) {
    formData.value = {}
    loading.value = false
    return
  }

  try {
    const item = await apiPlatform.getOne(resourcePath.value, itemId.value)
    formData.value = { ...item }

    // Convert relation objects to IRIs (only for single relations, not arrays)
    editableFields.value.forEach(field => {
      if (field.isRelation && formData.value[field.name]) {
        const value = formData.value[field.name]
        // Only convert single object relations to IRI, keep arrays as-is
        if (typeof value === 'object' && !Array.isArray(value) && value['@id']) {
          formData.value[field.name] = value['@id']
        }
      }
    })
  } catch (error: any) {
    console.error('Failed to load item:', error)
    if (error.response?.status === 403) {
      isForbidden.value = true
    } else {
      showSnackbar(t('messages.error'), 'error')
    }
  } finally {
    loading.value = false
  }
}

async function loadRelations() {
  const relationFields = resource.value?.properties.filter(prop => prop.isRelation) || []
  
  for (const relationField of relationFields) {
    const relatedResource = relationField.relatedResource
    if (!relatedResource) continue

    loadingRelations.value[relatedResource] = true
    try {
      const path = apiPlatform.getResourcePath(relatedResource)
      const result = await apiPlatform.getList(path, { itemsPerPage: 100 })
      relationData.value = {
        ...relationData.value,
        [relatedResource]: result.data
      }
    } catch (error) {
      console.error(`Failed to load ${relatedResource}:`, error)
    } finally {
      loadingRelations.value[relatedResource] = false
    }
  }
}

function prepareDataForSubmission() {
  const data: Record<string, any> = {}
  const editableFieldNames = new Set(editableFields.value.map(f => f.name))

  // Include editable fields with proper type conversion
  editableFields.value.forEach(field => {
    const value = formData.value[field.name]
    if (value !== undefined && value !== null && value !== '') {
      // Convert types based on field type
      if (field.type === 'integer') {
        data[field.name] = parseInt(value, 10)
      } else if (field.type === 'number') {
        data[field.name] = parseFloat(value)
      } else if (field.type === 'boolean') {
        data[field.name] = Boolean(value)
      } else {
        data[field.name] = value
      }
    } else if (value === '' && (field.type === 'integer' || field.type === 'number')) {
      // Don't send empty string for numeric fields
    } else if (value !== undefined) {
      data[field.name] = value
    }
  })

  // Include additional fields added by custom components (e.g., variant, option, value, values)
  Object.keys(formData.value).forEach(key => {
    if (!editableFieldNames.has(key) && !key.startsWith('@')) {
      let value = formData.value[key]
      if (value !== undefined && value !== null && value !== '') {
        // Convert objects with @id to IRI strings
        if (typeof value === 'object' && !Array.isArray(value) && value['@id']) {
          value = value['@id']
        }
        // Convert arrays of objects to arrays of IRIs
        if (Array.isArray(value)) {
          value = value.map(item =>
            typeof item === 'object' && item['@id'] ? item['@id'] : item
          )
        }
        data[key] = value
      }
    }
  })

  return data
}

async function handleSave() {
  try {
    saving.value = true
    fieldErrors.value = {}

    const dataToSubmit = prepareDataForSubmission()

    if (isCreate.value) {
      const created = await apiPlatform.create(resourcePath.value, dataToSubmit)
      showSnackbar(t('messages.createSuccess', { resource: resourceTitle.value }))

      // After creation, navigate to edit the created item
      if (created && created.id) {
        window.location.href = `/edit/${resourceName.value}/${created.id}`
      } else {
        navigateToResource()
      }
    } else {
      await apiPlatform.update(resourcePath.value, itemId.value, dataToSubmit)

      // Save custom component data (e.g., attribute values)
      if (editComponentRef.value?.saveAttributeValues) {
        await editComponentRef.value.saveAttributeValues()
      }

      showSnackbar(t('messages.updateSuccess', { resource: resourceTitle.value }))

      // Refresh auth store if editing current user
      if (resourceName.value === 'User' && authStore.user?.id === Number(itemId.value)) {
        await authStore.fetchProfile()
      }

      // Stay on the page - reload item data to refresh
      await loadItem()
    }
  } catch (error: any) {
    console.error('Failed to save item:', error)

    let errorMessage = t('messages.error')
    if (error.response?.data?.violations) {
      const violations = error.response.data.violations
      violations.forEach((violation: any) => {
        const fieldName = violation.propertyPath
        if (fieldName) {
          if (!fieldErrors.value[fieldName]) {
            fieldErrors.value[fieldName] = []
          }
          fieldErrors.value[fieldName].push(violation.message)
        }
      })
      errorMessage = violations.map((v: any) => v.message).join(', ')
    } else if (error.response?.data?.['hydra:description']) {
      errorMessage = error.response.data['hydra:description']
    }

    showSnackbar(errorMessage, 'error')
  } finally {
    saving.value = false
  }
}

function handleCancel() {
  navigateToResource()
}

onMounted(async () => {
  // Ensure resources are loaded first (needed for relation field detection)
  await resourcesStore.loadResources()
  await loadResourceMessages(resourceName.value, locale.value)
  await loadResourceConfig()
  await loadRelations()
  await loadItem()
})

// Reload data when navigating between items of the same resource
watch(itemId, async (newId, oldId) => {
  if (newId && newId !== oldId) {
    await loadItem()
  }
})

watch(resourceName, async (newName, oldName) => {
  if (newName && newName !== oldName) {
    await loadResourceMessages(newName, locale.value)
    await loadResourceConfig()
    await loadRelations()
    await loadItem()
  }
})
</script>
