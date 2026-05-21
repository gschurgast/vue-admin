<template>
  <v-navigation-drawer
    v-model="drawer"
    :rail="rail"
    permanent
    floating
    @click="rail = false"
    app
    class="floating-drawer"
  >
    <div class="brand d-flex align-center px-4 py-4" @click.stop="goHome">
      <v-avatar size="32" color="primary" class="brand-logo">
        <v-icon size="20" color="white">mdi-flash</v-icon>
      </v-avatar>
      <span v-if="!rail" class="brand-title ml-3">You-Pim</span>
    </div>

    <v-list nav class="px-2">
      <!-- Home -->
      <v-list-item
        prepend-icon="mdi-home"
        :title="t('navigation.home')"
        to="/"
        color="primary"
      />

      <template v-for="(group, groupName) in groupedResources" :key="groupName">
        <template v-if="group.length > 0">
          <v-list-subheader v-if="!rail" class="text-uppercase section-header">
            {{ groupName }}
          </v-list-subheader>
          <v-list-item
            v-for="resource in group"
            :key="resource.name"
            :title="resource.title"
            :to="`/resource/${resource.name}`"
            :prepend-icon="getResourceIcon(resource.name)"
            color="primary"
          />
        </template>
      </template>

      <!-- Resources without a group -->
      <v-list-item
        v-for="resource in ungroupedResources"
        :key="resource.name"
        :title="resource.title"
        :to="`/resource/${resource.name}`"
        prepend-icon="mdi-database"
        color="primary"
      />

      <v-progress-linear
        v-if="loading"
        indeterminate
        color="primary"
      />
    </v-list>
  </v-navigation-drawer>
</template>

<script setup lang="ts">
import { ref, computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRouter } from 'vue-router'
import { useResourcesStore } from '../../stores/resources'
import apiPlatform, { type Resource } from '../../services/apiPlatform'

const { t } = useI18n()
const router = useRouter()
const resourcesStore = useResourcesStore()

function goHome() {
  router.push('/')
}

const drawer = ref(true)
const rail = ref(true)

const loading = computed(() => resourcesStore.loading)

const visibleResources = computed(() => {
  return resourcesStore.resources.filter(resource => {
    return apiPlatform.hasCollectionOperation(resource.name, 'GET') &&
           !apiPlatform.isResourceHidden(resource.name)
  })
})

const groupedResources = computed(() => {
  const groups: Record<string, Resource[]> = {}

  visibleResources.value.forEach(resource => {
    const menuGroup = apiPlatform.getResourceMenuGroup(resource.name)
    if (menuGroup && menuGroup !== 'hidden') {
      if (!groups[menuGroup]) {
        groups[menuGroup] = []
      }
      groups[menuGroup].push(resource)
    }
  })

  return groups
})

const ungroupedResources = computed(() => {
  return visibleResources.value.filter(resource => {
    const menuGroup = apiPlatform.getResourceMenuGroup(resource.name)
    return !menuGroup || menuGroup === 'hidden'
  })
})

function getResourceIcon(resourceName: string): string {
  const icons: Record<string, string> = {
    Product: 'mdi-package-variant',
    ProductVariant: 'mdi-shape-outline',
    Collection: 'mdi-folder-multiple-outline',
    AttributeDefinition: 'mdi-format-list-bulleted-type',
    AttributeOption: 'mdi-tag-outline',
    ProductAttributeValue: 'mdi-tag-multiple-outline',
    User: 'mdi-account-outline',
    Author: 'mdi-account-edit-outline',
    Book: 'mdi-book-open-variant',
  }
  return icons[resourceName] || 'mdi-database'
}

function toggleRail() {
  rail.value = !rail.value
}

defineExpose({
  toggleRail,
  rail
})
</script>

<style scoped>
.brand {
  cursor: pointer;
}
.brand-title {
  font-size: 1.1rem;
  font-weight: 600;
  color: rgb(var(--v-theme-on-surface));
}
.section-header {
  font-size: 0.7rem !important;
  font-weight: 600 !important;
  letter-spacing: 0.06em;
  color: rgb(var(--v-theme-on-surface-variant)) !important;
  margin-top: 8px;
  min-height: 28px !important;
}
</style>

<!-- Floating drawer styles must be global because v-navigation-drawer
     renders outside this component's scoped DOM tree. -->
<style>
.v-navigation-drawer.floating-drawer {
  top: 12px !important;
  bottom: 12px !important;
  left: 12px !important;
  height: calc(100vh - 24px) !important;
  border-radius: 16px;
  border: 1px solid rgba(var(--v-theme-on-surface), 0.06) !important;
  box-shadow: 0 4px 16px rgba(0, 0, 0, 0.04);
}
</style>
