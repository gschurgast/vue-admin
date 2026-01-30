import { ref, computed, markRaw } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { useResourcesStore } from '../stores/resources'
import apiPlatform from '../services/apiPlatform'
import { loadResourceMessages } from '../plugins/i18n'
import { getConfigFields, normalizeConfigItem, getFieldType } from '../utils/resourceConfig'

// Dynamic import functions that can be overridden per-page
type ImportFunction = (path: string) => Promise<any>

export function useResource(options?: {
  importComponent?: ImportFunction
  importConfig?: ImportFunction
  importViewComponent?: (resourceFolder: string, viewType: string, componentName: string) => Promise<any>
}) {
  const route = useRoute()
  const router = useRouter()
  const resourcesStore = useResourcesStore()
  const { t, locale } = useI18n()

  // Common refs
  const customComponents = ref<Record<string, any>>({})
  const resourceConfig = ref<any>(null)
  const snackbar = ref({
    show: false,
    message: '',
    color: 'success'
  })

  // Common computed properties
  const resourceName = computed(() => {
    const name = route.params.resource
    return Array.isArray(name) ? name[0] : name
  })

  const itemId = computed(() => {
    const id = route.params.id
    return Array.isArray(id) ? id[0] : id
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
    if (!resourceName.value) return ''
    return apiPlatform.getResourcePath(resourceName.value)
  })

  // Common functions
  function showSnackbar(message: string, color = 'success') {
    snackbar.value = { show: true, message, color }
  }

  async function loadCustomComponent(componentName: string, type: string) {
    const cacheKey = `${type}/${componentName}`
    if (customComponents.value[cacheKey]) {
      return customComponents.value[cacheKey]
    }
    try {
      let component
      if (options?.importComponent) {
        component = await options.importComponent(`${type}/${componentName}`)
      } else {
        throw new Error('importComponent not provided')
      }
      customComponents.value[cacheKey] = markRaw(component.default || component)
      return customComponents.value[cacheKey]
    } catch (error) {
      console.warn(`Failed to load custom component: ${componentName}`, error)
      return null
    }
  }

  async function loadResourceConfigBase() {
    if (!resourceName.value) return null
    try {
      let config
      if (options?.importConfig) {
        config = await options.importConfig(resourceName.value)
      } else {
        throw new Error('importConfig not provided')
      }
      resourceConfig.value = config.default || config
      return resourceConfig.value
    } catch {
      resourceConfig.value = null
      return null
    }
  }

  async function loadResourceViewComponent(
    viewType: 'list' | 'edit' | 'show' | 'filter'
  ): Promise<any> {
    if (!resourceConfig.value?.[viewType]?.component) return null

    const componentName = resourceConfig.value[viewType].component
    const resourceFolder = String(resourceName.value).toLowerCase()

    try {
      let component
      if (options?.importViewComponent) {
        component = await options.importViewComponent(resourceFolder, viewType, componentName)
      } else {
        throw new Error('importViewComponent not provided')
      }
      return markRaw(component.default || component)
    } catch (e) {
      console.warn(
        `Failed to load custom ${viewType} component: ${resourceFolder}/${viewType}/${componentName}`,
        e
      )
      return null
    }
  }

  async function loadFieldComponents(configSection: string, componentType: string) {
    const fields = getConfigFields(resourceConfig.value?.[configSection])
    const promises = fields
      .map((item: any) => normalizeConfigItem(item))
      .filter((normalized: any) => normalized?.value && /^[A-Z]/.test(normalized.value))
      .map((normalized: any) => loadCustomComponent(normalized.value, componentType))

    await Promise.all(promises)
  }

  function navigateToResource() {
    router.push(`/resource/${resourceName.value}`)
  }

  function navigateToEdit(id?: string) {
    const targetId = id || itemId.value
    router.push(`/edit/${resourceName.value}/${targetId}`)
  }

  function navigateToShow(id?: string) {
    const targetId = id || itemId.value
    router.push(`/show/${resourceName.value}/${targetId}`)
  }

  function navigateToCreate() {
    router.push(`/edit/${resourceName.value}/new`)
  }

  // Build editable fields from resource properties
  function buildEditableFields(options: {
    includeCollectionsWithCustomComponents?: boolean
    customComponentFields?: Set<string>
  } = {}) {
    if (!resource.value) return []

    const { includeCollectionsWithCustomComponents = false, customComponentFields = new Set() } = options

    let fields = resource.value.properties
      .filter((prop: any) => {
        if (!prop.writeable) return false
        if (prop.isRelation) {
          const maxCardinality = prop.property?.['owl:maxCardinality']
          const fieldName = prop.property?.label || prop.title
          if (maxCardinality !== 1) {
            if (!includeCollectionsWithCustomComponents || !customComponentFields.has(fieldName)) {
              return false
            }
          }
        }
        return true
      })
      .map((prop: any) => {
        const type = getFieldType(prop)

        // Determine the best display field for relation fields
        let itemTitle = 'name'
        if (prop.isRelation && prop.relatedResource) {
          const relatedRes = resourcesStore.getResourceByName(prop.relatedResource)
          if (relatedRes) {
            const relatedProps = relatedRes.properties.map((p: any) => p.property?.label || p.title)
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
          customComponent: null as string | null
        }
      })

    // Apply config-based field ordering and custom components
    if (resourceConfig.value?.edit) {
      const configFields = getConfigFields(resourceConfig.value.edit)
      const configuredFields = configFields
        .map((configItem: any) => {
          const normalized = normalizeConfigItem(configItem)
          if (!normalized) return undefined
          const { field: fieldName, value: componentName } = normalized
          const field = fields.find((f: any) => f.name === fieldName)
          if (field) {
            return { ...field, customComponent: componentName || null }
          }
          return undefined
        })
        .filter((field: any) => field !== undefined)

      if (configuredFields.length > 0) {
        fields = configuredFields
      }
    }

    return fields
  }

  return {
    // Refs
    customComponents,
    resourceConfig,
    snackbar,

    // Computed
    resourceName,
    itemId,
    resource,
    resourceTitle,
    resourcePath,

    // Stores
    resourcesStore,

    // i18n
    t,
    locale,

    // Router
    router,

    // Functions
    showSnackbar,
    loadCustomComponent,
    loadResourceConfigBase,
    loadResourceViewComponent,
    loadFieldComponents,
    loadResourceMessages,
    navigateToResource,
    navigateToEdit,
    navigateToShow,
    navigateToCreate,
    buildEditableFields,

    // Re-exports from utils
    getConfigFields,
    normalizeConfigItem,
    getFieldType
  }
}
