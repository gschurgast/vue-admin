<script setup lang="ts">
import {ref, computed, onMounted} from 'vue'
import {useRoute} from 'vue-router'
import {useI18n} from 'vue-i18n'
import {useResourcesStore} from './stores/resources'
import apiPlatform from './services/apiPlatform'
import LanguageSwitcher from './components/LanguageSwitcher.vue'
import CommandPalette from './components/CommandPalette.vue'
import HelpDrawer from './components/HelpDrawer.vue'
import ChatBot from './components/ChatBot.vue'

const {t, locale} = useI18n()
const route = useRoute()
const drawer = ref(true)
const rail = ref(true)
const helpDrawer = ref(false)
const chatDrawer = ref(false)
const resourcesStore = useResourcesStore()
const commandPalette = ref<InstanceType<typeof CommandPalette> | null>(null)

const resources = computed(() => resourcesStore.resources)
const loading = computed(() => resourcesStore.loading)
const isMac = computed(() => navigator.platform.toUpperCase().indexOf('MAC') >= 0)
const currentResourceName = computed(() => {
  const resourceParam = route.params.resource
  return Array.isArray(resourceParam) ? resourceParam[0] : resourceParam
})

const visibleResources = computed(() => {
  return resourcesStore.resources.filter(resource => {
    // Only show resources that have a GET collection operation
    return apiPlatform.hasCollectionOperation(resource.name, 'GET')
  })
})

async function refreshResources() {
 await resourcesStore.loadResources()
}

onMounted(() => {
 refreshResources()
})
</script>

<template>
 <v-app>
  <!-- Command Palette -->
  <CommandPalette ref="commandPalette"/>

  <v-navigation-drawer
      v-model="drawer"
      :rail="rail"
      permanent
      @click="rail = false"
      app>
   <v-list>
    <v-list-item
        prepend-avatar="https://randomuser.me/api/portraits/men/85.jpg"
        title="John Leider"
    >
     <template v-slot:append>
      <v-btn
          icon="mdi-chevron-left"
          variant="text"
          @click.stop="rail = !rail"
      ></v-btn>
     </template>
    </v-list-item>
   </v-list>

   <v-divider></v-divider>

   <v-list density="compact" nav>
    <v-list-item
        prepend-icon="mdi-home"
        :title="t('navigation.home')"
        to="/"
    ></v-list-item>

    <v-divider class="my-2"></v-divider>

    <v-list-item
        v-for="resource in visibleResources"
        :key="resource.name"
        :title="resource.title"
        :to="`/resource/${resource.name}`"
        prepend-icon="mdi-database"
    ></v-list-item>

    <v-progress-linear
        v-if="loading"
        indeterminate
        color="primary"
    ></v-progress-linear>
   </v-list>


   <template v-slot:append>
    <v-list>
     <v-list-item>
      <template v-slot:prepend>
       <LanguageSwitcher :rail="rail"/>
      </template>
     </v-list-item>
    </v-list>
   </template>

  </v-navigation-drawer>

  <!-- App Bar -->
  <v-app-bar elevation="1" color="blue-grey-darken-3" density="compact">
   <v-btn icon density="compact" size="small" class="ml-2" @click="commandPalette?.open()">
    <v-icon>mdi-magnify</v-icon>
    <v-tooltip activator="parent" location="bottom">Search ({{ isMac ? '⌘K' : 'Ctrl+K' }})</v-tooltip>
   </v-btn>
   <v-hotkey keys="cmd+k" variant="tonal" platform="auto" @click="commandPalette?.open()" />

   <template v-slot:append>

    <!-- Chat Bot Button -->
    <v-btn icon density="compact" size="small" class="mr-2" @click="chatDrawer = !chatDrawer">
     <v-icon>mdi-robot</v-icon>
     <v-tooltip activator="parent" location="bottom">
      AI Assistant
     </v-tooltip>
    </v-btn>

    <!-- Notification Bell -->
    <v-btn icon density="compact" size="small" class="mr-2">
     <v-badge
         color="error"
         content="0"
         :model-value="false"
     >
      <v-icon>mdi-bell-outline</v-icon>
     </v-badge>
     <v-tooltip activator="parent" location="bottom">
      Notifications
     </v-tooltip>
    </v-btn>

    <!-- Help Button -->
    <v-btn icon density="compact" size="small" class="mr-2" @click="helpDrawer = !helpDrawer">
     <v-icon>mdi-help</v-icon>
     <v-tooltip activator="parent" location="bottom">
      Help
     </v-tooltip>
    </v-btn>
   </template>
  </v-app-bar>

  <!-- Help Drawer -->
  <HelpDrawer 
    v-model="helpDrawer" 
    :resource-name="currentResourceName"
  />
  
  <!-- Chat Drawer -->
  <ChatBot v-model="chatDrawer" />

  <v-main>
   <router-view/>
  </v-main>
 </v-app>
</template>


