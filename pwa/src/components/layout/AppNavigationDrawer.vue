<template>
  <v-navigation-drawer
    v-model="drawer"
    :rail="rail"
    permanent
    @click="rail = false"
    app
  >
    <v-list density="compact" nav class="mt-2">
      <v-list-item
        prepend-icon="mdi-home"
        :title="t('navigation.home')"
        to="/"
      />

      <v-divider class="my-2" />

      <template v-for="(group, groupName) in groupedResources" :key="groupName">
        <v-list-group v-if="group.length > 0" :value="groupName">
          <template v-slot:activator="{ props }">
            <v-list-item
              v-bind="props"
              :prepend-icon="getGroupIcon(groupName)"
              :title="groupName"
            />
          </template>
          <v-list-item
            v-for="resource in group"
            :key="resource.name"
            :title="resource.title"
            :to="`/resource/${resource.name}`"
            class="pl-8"
          />
        </v-list-group>
      </template>

      <!-- Resources without a group -->
      <v-list-item
        v-for="resource in ungroupedResources"
        :key="resource.name"
        :title="resource.title"
        :to="`/resource/${resource.name}`"
        prepend-icon="mdi-database"
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
import { useResourcesStore } from '../../stores/resources'
import apiPlatform, { type Resource } from '../../services/apiPlatform'

const { t } = useI18n()
const resourcesStore = useResourcesStore()

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

function getGroupIcon(groupName: string): string {
  const icons: Record<string, string> = {
    'Product': 'mdi-package-variant',
    'Content': 'mdi-file-document-multiple',
    'Settings': 'mdi-cog'
  }
  return icons[groupName] || 'mdi-folder'
}

function toggleRail() {
  rail.value = !rail.value
}

defineExpose({
  toggleRail,
  rail
})
</script>
