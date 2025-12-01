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

    <!-- Show View -->
    <v-card v-else>
      <v-card-title>
        {{ t('resource.show', { resource: resourceTitle }) }}
      </v-card-title>
      
      <v-card-text>
        <!-- Custom show component -->
        <component
          v-if="ShowComponent"
          :is="ShowComponent"
          :item="item"
          :fields="displayFields"
          :custom-components="customComponents"
          :resource-name="resourceName"
        />
        
        <!-- Default display -->
        <ResourceShow
          v-else
          :item="item"
          :fields="displayFields"
          :custom-components="customComponents"
          :resource-name="resourceName"
        />
      </v-card-text>

      <v-card-actions>
        <v-spacer></v-spacer>
        <v-btn variant="outlined" @click="handleBack">{{ t('common.back') }}</v-btn>
        <v-btn variant="outlined" color="primary" @click="handleEdit">{{ t('common.edit') }}</v-btn>
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
import { ref, computed, onMounted, markRaw, shallowRef, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import apiPlatform from '../../../services/apiPlatform'
import { loadResourceMessages } from '../../../plugins/i18n'
import { useResourcesStore } from '../../../stores/resources'
import ResourceDelete from '../../../components/resource/ResourceDelete.vue'
import ResourceShow from '../../../components/resource/ResourceShow.vue'

const route = useRoute()
const router = useRouter()
const resourcesStore = useResourcesStore()
const { t, locale } = useI18n()

const resourceName = computed(() => {
  const name = route.params.resource
  return Array.isArray(name) ? name[0] : name
})

const itemId = computed(() => {
  const id = route.params.id
  return Array.isArray(id) ? id[0] : id
})

const loading = ref(true)
const item = ref<any>({})
const ShowComponent = shallowRef(null)
const customComponents = ref({})
const resourceConfig = ref<any>(null)

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
  if (!resourceName.value) return ''
  return apiPlatform.getResourcePath(resourceName.value)
})

const breadcrumbs = computed(() => [
  {
    title: t('navigation.home'),
    disabled: false,
    href: '/'
  },
  {
    title: resourceTitle.value,
    disabled: false,
    href: `/resource/${resourceName.value}`
  },
  {
    title: t('resource.show', { resource: '' }),
    disabled: true
  }
])

// Helper to get fields from config (supports object with fields property)
function getConfigFields(config: any) {
  if (config && typeof config === 'object' && Array.isArray(config.fields)) return config.fields
  return []
}

// Helper to normalize config item (shorthand { field: value } -> { field, value })
function normalizeConfigItem(item: any) {
  if (typeof item === 'string') return { name: item, component: null }
  if (typeof item === 'object') {
    const keys = Object.keys(item)
    if (keys.length === 1) {
      const field = keys[0]
      const value = item[field]
      return { name: field, component: value || null }
    }
  }
  return null
}

// Load custom component dynamically
async function loadCustomComponent(componentName: any, folder: string) {
  try {
    const component = await import(`../../../components/${folder}/${componentName}.vue`)
    customComponents.value[`${folder}/${componentName}`] = markRaw(component.default || component)
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

    // Reset to default (no custom show component)
    ShowComponent.value = null

    const componentsToLoad: Promise<any>[] = []
    if (resourceConfig.value?.show) {
      // Check for custom Show component
      if (resourceConfig.value.show.component) {
        const componentName = resourceConfig.value.show.component
        const resourceFolder = String(resourceName.value).toLowerCase()
        try {
          // Try loading from resource-specific folder: components/[resource]/show/[Name].vue
          const component = await import(`../../../components/${resourceFolder}/show/${componentName}.vue`)
          ShowComponent.value = markRaw(component.default || component)
        } catch (e) {
          console.warn(`Failed to load custom show component: ${resourceFolder}/show/${componentName}`, e)
        }
      }

      const fields = getConfigFields(resourceConfig.value.show)
      fields.forEach((item: any) => {
        const normalized = normalizeConfigItem(item)
        if (normalized) {
          const { component } = normalized
          if (component && /^[A-Z]/.test(component)) {
            componentsToLoad.push(loadCustomComponent(component, 'show'))
          }
        }
      })
    }
    await Promise.all(componentsToLoad)
  } catch (error) {
    resourceConfig.value = null
  }
}

// Get displayable fields
const displayFields = computed(() => {
  if (!resource.value) return []

  // If config has show section with fields, use those
  if (resourceConfig.value?.show) {
    const configFields = getConfigFields(resourceConfig.value.show)
    if (configFields.length > 0) {
      return configFields.map((item: any) => {
        const normalized = normalizeConfigItem(item)
        if (normalized) {
          const prop = resource.value.properties?.find((p: any) => {
             const pName = p.property?.label || p.title
             return pName === normalized.name
          })
          
          const translationKey = `resources.${String(resourceName.value).toLowerCase()}.fields.${normalized.name}`
          
          return {
            name: normalized.name,
            label: t(translationKey, prop?.title || normalized.name),
            component: normalized.component,
            type: prop?.type
          }
        }
        return null
      }).filter(Boolean)
    }
  }

  // Default: show all readable properties
  return resource.value.properties?.map((prop: any) => {
    const fieldName = prop.property?.label || prop.title
    const translationKey = `resources.${String(resourceName.value).toLowerCase()}.fields.${fieldName}`
    
    return {
      name: fieldName,
      label: t(translationKey, prop.title),
      component: null,
      type: prop.type
    }
  }) || []
})



async function loadItem() {
  loading.value = true
  try {
    await loadResourceMessages(String(resourceName.value), locale.value)
    await loadResourceConfig()
    
    const data = await apiPlatform.getOne(resourcePath.value, itemId.value)
    item.value = data
  } catch (error: any) {
    console.error('Failed to load item:', error)
    snackbar.value = {
      show: true,
      message: t('messages.error'),
      color: 'error'
    }
  } finally {
    loading.value = false
  }
}

function handleBack() {
  router.push(`/resource/${resourceName.value}`)
}

function handleEdit() {
  router.push(`/edit/${resourceName.value}/${itemId.value}`)
}

onMounted(() => {
  loadItem()
})

// Watch for locale changes to reload resource-specific translations
watch(locale, async (newLocale) => {
  if (resourceName.value) {
    await loadResourceMessages(String(resourceName.value), newLocale)
  }
})
</script>
