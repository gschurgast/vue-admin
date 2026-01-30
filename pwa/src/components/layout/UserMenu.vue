<template>
  <v-menu>
    <template v-slot:activator="{ props }">
      <v-btn icon density="compact" size="small" class="mr-2" v-bind="props">
        <v-avatar size="18">
          <v-img
            v-if="authStore.pictureUrl"
            :src="authStore.pictureUrl"
            cover
          />
          <v-icon v-else size="28" color="grey-lighten-1">mdi-account-circle</v-icon>
        </v-avatar>
      </v-btn>
    </template>

    <v-card min-width="250">
      <v-list>
        <!-- User Info Header -->
        <v-list-item
          :to="authStore.user?.id ? `/edit/User/${authStore.user.id}` : '/'"
          class="py-3"
        >
          <template v-slot:prepend>
            <v-avatar size="40" class="mr-3">
              <v-img
                v-if="authStore.pictureUrl"
                :src="authStore.pictureUrl"
                cover
              />
              <v-icon v-else size="40" color="grey-lighten-1">mdi-account-circle</v-icon>
            </v-avatar>
          </template>
          <v-list-item-title class="font-weight-medium">
            {{ authStore.fullName }}
          </v-list-item-title>
          <v-list-item-subtitle>
            {{ authStore.user?.email }}
          </v-list-item-subtitle>
        </v-list-item>

        <v-divider />

        <!-- My Account -->
        <v-list-item
          :to="authStore.user?.id ? `/edit/User/${authStore.user.id}` : '/'"
          prepend-icon="mdi-account"
        >
          <v-list-item-title>{{ t('account.title') }}</v-list-item-title>
        </v-list-item>

        <v-divider />

        <!-- Language Selection -->
        <LanguageSwitcher />

        <v-divider class="my-2" />

        <!-- Logout -->
        <v-list-item
          prepend-icon="mdi-logout"
          @click="handleLogout"
        >
          <v-list-item-title>{{ t('auth.logout') }}</v-list-item-title>
        </v-list-item>
      </v-list>
    </v-card>
  </v-menu>
</template>

<script setup lang="ts">
import { useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { useAuthStore } from '../../stores/auth'
import { useResourcesStore } from '../../stores/resources'
import LanguageSwitcher from './LanguageSwitcher.vue'

const { t } = useI18n()
const router = useRouter()
const authStore = useAuthStore()
const resourcesStore = useResourcesStore()

function handleLogout() {
  resourcesStore.clearResources()
  authStore.logout()
  router.push({ name: 'login' })
}
</script>
