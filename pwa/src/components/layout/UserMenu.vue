<template>
  <v-menu v-model="open" offset="8" location="bottom end" :close-on-content-click="false">
    <template v-slot:activator="{ props }">
      <v-btn icon class="ml-1 mr-2" variant="text" v-bind="props">
        <v-avatar size="36">
          <v-img
            v-if="authStore.pictureUrl"
            :src="authStore.pictureUrl"
            cover
          />
          <v-icon v-else size="36" color="grey-lighten-1">mdi-account-circle</v-icon>
        </v-avatar>
      </v-btn>
    </template>

    <v-card min-width="340" max-width="380" class="pa-5 profile-menu">
      <!-- Header -->
      <div class="d-flex align-center justify-space-between mb-4">
        <h3 class="text-h6 font-weight-semibold">{{ t('profile.title') }}</h3>
        <v-btn icon variant="text" size="small" @click="open = false">
          <v-icon>mdi-close</v-icon>
        </v-btn>
      </div>

      <!-- User Info -->
      <div class="d-flex align-center mb-4">
        <v-avatar size="72" class="mr-4">
          <v-img
            v-if="authStore.pictureUrl"
            :src="authStore.pictureUrl"
            cover
          />
          <v-icon v-else size="72" color="grey-lighten-1">mdi-account-circle</v-icon>
        </v-avatar>
        <div>
          <div class="text-h6 font-weight-semibold line-height-tight">
            {{ authStore.fullName }}
          </div>
          <div v-if="userRole" class="text-body-2 text-medium-emphasis">
            {{ userRole }}
          </div>
          <div class="d-flex align-center text-body-2 text-medium-emphasis mt-1">
            <v-icon size="14" class="mr-1">mdi-email-outline</v-icon>
            {{ authStore.user?.email }}
          </div>
        </div>
      </div>

      <v-divider class="mb-2" />

      <!-- Menu items -->
      <v-list nav density="comfortable" class="px-0">
        <v-list-item
          :to="authStore.user?.id ? `/edit/User/${authStore.user.id}` : '/'"
          @click="open = false"
        >
          <template v-slot:prepend>
            <div class="icon-tile bg-info-soft">
              <v-icon color="info">mdi-account-circle-outline</v-icon>
            </div>
          </template>
          <v-list-item-title class="font-weight-semibold">
            {{ t('profile.myProfile') }}
          </v-list-item-title>
          <v-list-item-subtitle>{{ t('profile.accountSettings') }}</v-list-item-subtitle>
        </v-list-item>

        <v-list-item @click="toggleTheme">
          <template v-slot:prepend>
            <div class="icon-tile bg-success-soft">
              <v-icon color="success">{{ isDark ? 'mdi-weather-sunny' : 'mdi-weather-night' }}</v-icon>
            </div>
          </template>
          <v-list-item-title class="font-weight-semibold">
            {{ isDark ? t('common.lightMode') : t('common.darkMode') }}
          </v-list-item-title>
          <v-list-item-subtitle>{{ t('profile.appPreferences') }}</v-list-item-subtitle>
        </v-list-item>
      </v-list>

      <v-btn
        block
        color="primary"
        variant="flat"
        size="large"
        class="mt-4"
        @click="handleLogout"
      >
        {{ t('auth.logout') }}
      </v-btn>
    </v-card>
  </v-menu>
</template>

<script setup lang="ts">
import { ref, computed } from 'vue'
import { useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { useAuthStore } from '../../stores/auth'
import { useResourcesStore } from '../../stores/resources'
import { useThemeMode } from '../../composables/useThemeMode'

const { t } = useI18n()
const router = useRouter()
const authStore = useAuthStore()
const resourcesStore = useResourcesStore()
const { isDark, toggle: toggleTheme } = useThemeMode()

const open = ref(false)

const userRole = computed(() => {
  const roles = authStore.user?.roles || []
  if (roles.includes('ROLE_ADMIN')) return 'Admin'
  if (roles.includes('ROLE_USER')) return 'User'
  return null
})

function handleLogout() {
  open.value = false
  resourcesStore.clearResources()
  authStore.logout()
  router.push({ name: 'login' })
}
</script>

<style scoped>
.profile-menu {
  border-radius: 16px;
}
.line-height-tight {
  line-height: 1.2;
}
.icon-tile {
  width: 40px;
  height: 40px;
  border-radius: 10px;
  display: flex;
  align-items: center;
  justify-content: center;
  margin-right: 12px;
}
.bg-info-soft {
  background-color: rgba(var(--v-theme-info), 0.12);
}
.bg-success-soft {
  background-color: rgba(var(--v-theme-success), 0.12);
}
</style>
