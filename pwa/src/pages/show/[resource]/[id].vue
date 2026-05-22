<template>
  <ResourceAppBar :breadcrumbs="breadcrumbs">
    <template #actions>
      <v-btn icon density="compact" size="small" class="mr-2" @click="handleBack">
        <v-icon>mdi-arrow-left</v-icon>
        <v-tooltip activator="parent" location="bottom">{{ t('common.back') }}</v-tooltip>
      </v-btn>
      <v-btn icon density="compact" size="small" class="mr-2" @click="handleEdit">
        <v-icon>mdi-pencil</v-icon>
        <v-tooltip activator="parent" location="bottom">{{ t('common.edit') }}</v-tooltip>
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

    <!-- Show View -->
    <v-card v-else>
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
import apiPlatform from '../../../services/apiPlatform'
import ResourceShow from '../../../components/resource/ResourceShow.vue'
import ResourceAppBar from '../../../components/resource/ResourceAppBar.vue'
import ResourceForbidden from '../../../components/common/ResourceForbidden.vue'

// Pre-load component modules using import.meta.glob for Vite compatibility
const showComponents = import.meta.glob('../../../components/show/*.vue')
const configFiles = import.meta.glob('../../../config/*.json')
const resourceViewComponents = import.meta.glob('../../../components/*/*/**.vue')

const componentModules: Record<string, () => Promise<any>> = {
  ...Object.fromEntries(Object.entries(showComponents).map(([k, v]) => [`show/${k.split('/').pop()?.replace('.vue', '')}`, v]))
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
  t,
  locale,
  loadResourceConfigBase,
  loadResourceViewComponent,
  loadFieldComponents,
  loadResourceMessages,
  navigateToResource,
  navigateToEdit,
  getConfigFields
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

const loading = ref(true)
const item = ref<any>({})
const ShowComponent = shallowRef(null)
const isForbidden = ref(false)

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

// Helper to normalize config item for show view (returns { name, component })
function normalizeShowConfigItem(item: any): { name: string; component: string | null } | null {
  if (typeof item === 'string') return { name: item, component: null }
  if (typeof item === 'object' && item !== null) {
    const keys = Object.keys(item)
    if (keys.length === 1) {
      return { name: keys[0], component: item[keys[0]] || null }
    }
  }
  return null
}

// Load resource config
async function loadResourceConfig() {
  await loadResourceConfigBase()
  ShowComponent.value = null

  if (resourceConfig.value?.show) {
    const customShow = await loadResourceViewComponent('show')
    if (customShow) ShowComponent.value = customShow
    await loadFieldComponents('show', 'show')
  }
}

// Get displayable fields
const displayFields = computed(() => {
  if (!resource.value) return []

  // If config has show section with fields, use those
  if (resourceConfig.value?.show) {
    const configFields = getConfigFields(resourceConfig.value.show)
    if (configFields.length > 0) {
      return configFields
        .map((item: any) => normalizeShowConfigItem(item))
        .filter((normalized): normalized is { name: string; component: string | null } => normalized !== null)
        .map(normalized => {
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
        })
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
  isForbidden.value = false
  try {
    await loadResourceMessages(String(resourceName.value), locale.value)
    await loadResourceConfig()

    const data = await apiPlatform.getOne(resourcePath.value, itemId.value)
    item.value = data
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

function handleBack() {
  navigateToResource()
}

function handleEdit() {
  navigateToEdit()
}

onMounted(() => {
  loadItem()
})

// Reload when the user navigates to a different show page (same route, new params).
// Without this watcher, clicking a similar asset would change the URL but keep the
// previously-loaded item visible.
watch(itemId, (newId, oldId) => {
  if (newId && newId !== oldId) {
    loadItem()
  }
})

// Watch for locale changes to reload resource-specific translations
watch(locale, async (newLocale) => {
  if (resourceName.value) {
    await loadResourceMessages(String(resourceName.value), newLocale)
  }
})
</script>
