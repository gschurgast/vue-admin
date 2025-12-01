<template>
  <v-container fluid>
    <!-- Breadcrumb Navigation -->
    <v-breadcrumbs :items="breadcrumbs" class="px-0">
      <template v-slot:divider>
        <v-icon>mdi-chevron-right</v-icon>
      </template>
    </v-breadcrumbs>

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
      <v-card-title>
        {{ isCreate ? t('resource.create', { resource: resourceTitle }) : t('resource.edit', { resource: resourceTitle }) }}
      </v-card-title>
      
      <v-card-text>
        <!-- Custom edit component -->
        <component
          v-if="EditComponent"
          :is="EditComponent"
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

      <v-card-actions>
        <v-spacer></v-spacer>
        <v-btn variant="outlined" @click="handleCancel">{{ t('common.cancel') }}</v-btn>
        <v-btn variant="outlined" color="primary" @click="handleSave" :loading="saving">{{ t('common.save') }}</v-btn>
      </v-card-actions>
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
import { ref, computed, onMounted, markRaw, shallowRef } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import apiPlatform from '../../../services/apiPlatform'
import { loadResourceMessages } from '../../../plugins/i18n'
import { useResourcesStore } from '../../../stores/resources'
import { useAuthStore } from '../../../stores/auth'
import ResourceForm from '../../../components/resource/ResourceForm.vue'
import ResourceForbidden from '../../../components/common/ResourceForbidden.vue'

const route = useRoute()
const router = useRouter()
const resourcesStore = useResourcesStore()
const authStore = useAuthStore()
const { t, locale } = useI18n()

const resourceName = computed(() => {
  const name = route.params.resource
  return Array.isArray(name) ? name[0] : name
})

const itemId = computed(() => {
  const id = route.params.id
  return Array.isArray(id) ? id[0] : id
})

const isCreate = computed(() => itemId.value === 'new')

const loading = ref(true)
const saving = ref(false)
const formData = ref({})
const fieldErrors = ref<Record<string, string[]>>({})
const relationData = ref({})
const loadingRelations = ref({})
const EditComponent = shallowRef(null)
const customComponents = ref({})
const resourceConfig = ref<any>(null)
const isForbidden = ref(false)

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
  const translationKey = `resources.${String(resourceName.value).toLowerCase()}.name`
  return t(translationKey, resource.value?.title || resourceName.value)
})

const resourcePath = computed(() => {
  return apiPlatform.getResourcePath(resourceName.value)
})

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

// Helper to get fields from config
function getConfigFields(config: any) {
  if (config && typeof config === 'object' && Array.isArray(config.fields)) return config.fields
  return []
}

// Helper to normalize config item
function normalizeConfigItem(item: any) {
  if (typeof item !== 'object' || item === null) return null
  const keys = Object.keys(item)
  if (keys.length !== 1) return null
  const field = keys[0]
  const value = item[field]
  return { field, value }
}

// Load custom component
async function loadCustomComponent(componentName, type = 'fields') {
  const cacheKey = `${type}/${componentName}`
  if (customComponents.value[cacheKey]) {
    return customComponents.value[cacheKey]
  }
  try {
    const component = await import(`../../../components/${type}/${componentName}.vue`)
    customComponents.value[cacheKey] = markRaw(component.default || component)
    return customComponents.value[cacheKey]
  } catch (error) {
    console.warn(`Failed to load custom component: ${componentName}`, error)
    return null
  }
}

// Load resource config
async function loadResourceConfig() {
  if (!resourceName.value) return
  try {
    const config = await import(`../../../config/${resourceName.value}.json`)
    resourceConfig.value = config.default || config

    // Reset to default (no custom edit component)
    EditComponent.value = null

    const componentsToLoad = []
    if (resourceConfig.value?.edit) {
      // Check for custom Edit component
      if (resourceConfig.value.edit.component) {
        const componentName = resourceConfig.value.edit.component
        const resourceFolder = String(resourceName.value).toLowerCase()
        try {
          // Try loading from resource-specific folder: components/[resource]/edit/[Name].vue
          const component = await import(`../../../components/${resourceFolder}/edit/${componentName}.vue`)
          EditComponent.value = markRaw(component.default || component)
        } catch (e) {
          console.warn(`Failed to load custom edit component: ${resourceFolder}/edit/${componentName}`, e)
        }
      }

      const fields = getConfigFields(resourceConfig.value.edit)
      fields.forEach((item: any) => {
        const normalized = normalizeConfigItem(item)
        if (normalized) {
          const { value } = normalized
          if (value && /^[A-Z]/.test(value)) {
            componentsToLoad.push(loadCustomComponent(value, 'fields'))
          }
        }
      })
    }
    await Promise.all(componentsToLoad)
  } catch (error) {
    resourceConfig.value = null
  }
}

const editableFields = computed(() => {
  if (!resource.value) return []

  let fields = resource.value.properties
    .filter(prop => {
      if (!prop.writeable) return false
      if (prop.isRelation) {
        const maxCardinality = prop.property?.['owl:maxCardinality']
        if (maxCardinality !== 1) return false
      }
      return true
    })
    .map(prop => {
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
  if (isCreate.value) {
    formData.value = {}
    loading.value = false
    return
  }

  try {
    const item = await apiPlatform.getOne(resourcePath.value, itemId.value)
    formData.value = { ...item }

    // Convert relation objects to IRIs
    editableFields.value.forEach(field => {
      if (field.isRelation && formData.value[field.name]) {
        if (typeof formData.value[field.name] === 'object') {
          formData.value[field.name] = formData.value[field.name]['@id']
        }
      }
    })
  } catch (error: any) {
    if (error.response?.status === 403) {
      isForbidden.value = true
    } else {
      showSnackbar(t('messages.loadingError'), 'error')
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

async function handleSave() {
  try {
    saving.value = true
    fieldErrors.value = {}

    if (isCreate.value) {
      await apiPlatform.create(resourcePath.value, formData.value)
      showSnackbar(t('messages.createSuccess', { resource: resourceTitle.value }))
    } else {
      await apiPlatform.update(resourcePath.value, itemId.value, formData.value)
      showSnackbar(t('messages.updateSuccess', { resource: resourceTitle.value }))

      // Refresh auth store if editing current user
      if (resourceName.value === 'User' && authStore.user?.id === Number(itemId.value)) {
        await authStore.fetchProfile()
      }
    }

    router.push(`/resource/${resourceName.value}`)
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
  router.push(`/resource/${resourceName.value}`)
}

function showSnackbar(message, color = 'success') {
  snackbar.value = { show: true, message, color }
}

onMounted(async () => {
  await loadResourceMessages(resourceName.value, locale.value)
  await loadResourceConfig()
  await loadRelations()
  await loadItem()
})
</script>
