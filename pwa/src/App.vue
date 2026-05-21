<script setup lang="ts">
import { ref, computed, onMounted, onUnmounted, watch } from 'vue'
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
const navigationDrawer = ref<InstanceType<typeof AppNavigationDrawer> | null>(null)

function toggleChat() {
  if (chatDrawer.value) {
    chatDrawer.value = false
  } else {
    helpDrawer.value = false
    chatDrawer.value = true
  }
}

function toggleHelp() {
  if (helpDrawer.value) {
    helpDrawer.value = false
  } else {
    chatDrawer.value = false
    helpDrawer.value = true
  }
}

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

function onSearchHotkey(event: KeyboardEvent) {
  if ((event.metaKey || event.ctrlKey) && event.key.toLowerCase() === 'k') {
    event.preventDefault()
    commandPalette.value?.open()
  }
}

onMounted(() => {
  authStore.checkAuth()
  window.addEventListener('keydown', onSearchHotkey)
})

onUnmounted(() => {
  window.removeEventListener('keydown', onSearchHotkey)
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

    <AppNavigationDrawer ref="navigationDrawer" />

    <AppBar
      @open-search="commandPalette?.open()"
      @toggle-chat="toggleChat"
      @toggle-help="toggleHelp"
      @toggle-drawer="navigationDrawer?.toggleRail()"
    />

    <HelpDrawer v-model="helpDrawer" :resource-name="currentResourceName" />
    <ChatBot v-model="chatDrawer" />

    <v-main>
      <router-view />
    </v-main>
  </v-app>
</template>

<!-- Global layout adjustments for the floating drawer + app bar.
     Vuetify's v-main already pads by drawer-width (rail-aware). We add extra
     padding on the direct child so the content sits clear of the floating
     drawer's right edge and the floating app bar above. -->
<style>
html,
body,
.v-application,
.v-application__wrap {
  font-family: 'Plus Jakarta Sans', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
}
.v-main.you-pim-main > * {
  padding-left: 24px;
  padding-right: 12px;
  padding-top: 12px;
}

/* Soft drop shadow on solid-fill buttons (matches button.png reference) */
.v-btn.v-btn--variant-flat,
.v-btn.v-btn--variant-elevated {
  box-shadow: 0 4px 12px rgba(20, 30, 60, 0.14) !important;
}
.v-btn.v-btn--variant-flat:hover,
.v-btn.v-btn--variant-elevated:hover {
  box-shadow: 0 6px 16px rgba(20, 30, 60, 0.2) !important;
}
.v-btn.v-btn--variant-tonal {
  box-shadow: 0 2px 6px rgba(20, 30, 60, 0.06) !important;
}
.v-btn.v-btn--variant-tonal:hover {
  box-shadow: 0 4px 10px rgba(20, 30, 60, 0.1) !important;
}
</style>
