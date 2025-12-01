<script setup lang="ts">
import { ref, computed, onMounted, watch } from 'vue'
import { useRoute } from 'vue-router'
import { useResourcesStore } from './stores/resources'
import { useAuthStore } from './stores/auth'
import AppNavigationDrawer from './components/layout/AppNavigationDrawer.vue'
import AppBar from './components/layout/AppBar.vue'
import CommandPalette from './components/CommandPalette.vue'
import HelpDrawer from './components/HelpDrawer.vue'
import ChatBot from './components/ChatBot.vue'

const route = useRoute()
const resourcesStore = useResourcesStore()
const authStore = useAuthStore()

const helpDrawer = ref(false)
const chatDrawer = ref(false)
const commandPalette = ref<InstanceType<typeof CommandPalette> | null>(null)

const currentResourceName = computed(() => {
  const resourceParam = route.params.resource
  return Array.isArray(resourceParam) ? resourceParam[0] : resourceParam
})

const isLoginPage = computed(() => route.name === 'login')

async function refreshResources() {
  if (authStore.isAuthenticated) {
    await resourcesStore.loadResources()
  }
}

onMounted(() => {
  authStore.checkAuth()
})

// Load resources when authenticated and not on login page (fallback for page refresh)
watch(
  () => authStore.isAuthenticated,
  (isAuthenticated) => {
    if (isAuthenticated && resourcesStore.resources.length === 0) {
      refreshResources()
    }
  },
  { immediate: true }
)
</script>

<template>
  <v-app v-if="isLoginPage">
    <router-view />
  </v-app>

  <v-app v-else>
    <CommandPalette ref="commandPalette" />

    <AppNavigationDrawer />

    <AppBar
      @open-search="commandPalette?.open()"
      @toggle-chat="chatDrawer = !chatDrawer"
      @toggle-help="helpDrawer = !helpDrawer"
    />
    <v-hotkey keys="cmd+k" variant="tonal" platform="auto" @click="commandPalette?.open()" />

    <HelpDrawer v-model="helpDrawer" :resource-name="currentResourceName" />
    <ChatBot v-model="chatDrawer" />

    <v-main>
      <router-view />
    </v-main>
  </v-app>
</template>
