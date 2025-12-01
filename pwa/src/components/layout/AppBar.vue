<template>
  <v-app-bar elevation="1" color="blue-grey-darken-3" density="compact">
    <v-btn icon density="compact" size="small" class="ml-2" @click="emit('open-search')">
      <v-icon>mdi-magnify</v-icon>
      <v-tooltip activator="parent" location="bottom">
        Search ({{ isMac ? '⌘K' : 'Ctrl+K' }})
      </v-tooltip>
    </v-btn>
   <v-hotkey keys="cmd+k" variant="tonal" platform="auto" @click="commandPalette?.open()" />

    <template v-slot:append>
      <v-btn icon density="compact" size="small" class="mr-2" @click="emit('toggle-chat')">
        <v-icon>mdi-robot</v-icon>
        <v-tooltip activator="parent" location="bottom">
          {{ t('ai.title') }}
        </v-tooltip>
      </v-btn>

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

      <v-btn icon density="compact" size="small" class="mr-2" @click="emit('toggle-help')">
        <v-icon>mdi-help</v-icon>
        <v-tooltip activator="parent" location="bottom">
          Help
        </v-tooltip>
      </v-btn>
    </template>
  </v-app-bar>
</template>

<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()

const emit = defineEmits<{
  'open-search': []
  'toggle-chat': []
  'toggle-help': []
}>()

const isMac = computed(() => navigator.platform.toUpperCase().indexOf('MAC') >= 0)
</script>
