<template>
  <v-navigation-drawer
    v-model="drawer"
    :rail="rail"
    permanent
    @click="rail = false"
    app
  >
    <v-list>
      <v-list-item
        :to="authStore.user?.id ? `/edit/User/${authStore.user.id}` : '/'"
        class="user-profile-item"
      >
        <template v-slot:prepend>
          <v-avatar size="24" class="mr-3">
            <v-img
              v-if="authStore.pictureUrl"
              :src="authStore.pictureUrl"
              cover
            />
            <v-icon v-else size="24" color="grey-lighten-1">mdi-account</v-icon>
          </v-avatar>
        </template>

        <v-list-item-title class="font-weight-medium">
          {{ authStore.fullName }}
        </v-list-item-title>
        <v-list-item-subtitle class="text-caption">
          {{ authStore.user?.email }}
        </v-list-item-subtitle>

        <template v-slot:append>
          <v-btn
            icon="mdi-chevron-left"
            variant="text"
            size="small"
            @click.stop.prevent="rail = !rail"
          />
        </template>
      </v-list-item>
    </v-list>

    <v-divider />

    <v-list density="compact" nav>
      <v-list-item
        prepend-icon="mdi-home"
        :title="t('navigation.home')"
        to="/"
      />

      <v-divider class="my-2" />

      <v-list-item
        v-for="resource in visibleResources"
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

    <template v-slot:append>
      <v-list>
        <v-list-item>
          <template v-slot:prepend>
            <LanguageSwitcher :rail="rail" />
          </template>
        </v-list-item>
        <v-list-item
          prepend-icon="mdi-logout"
          :title="t('auth.logout')"
          @click="handleLogout"
        />
      </v-list>
    </template>
  </v-navigation-drawer>
</template>

<script setup lang="ts">
import { ref, computed } from 'vue'
import { useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { useResourcesStore } from '../../stores/resources'
import { useAuthStore } from '../../stores/auth'
import apiPlatform from '../../services/apiPlatform'
import LanguageSwitcher from '../LanguageSwitcher.vue'

const { t } = useI18n()
const router = useRouter()
const resourcesStore = useResourcesStore()
const authStore = useAuthStore()

const drawer = ref(true)
const rail = ref(true)

const loading = computed(() => resourcesStore.loading)

const visibleResources = computed(() => {
  return resourcesStore.resources.filter(resource => {
    return apiPlatform.hasCollectionOperation(resource.name, 'GET')
  })
})

function handleLogout() {
  resourcesStore.clearResources()
  authStore.logout()
  router.push({ name: 'login' })
}
</script>
