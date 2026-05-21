<template>
  <v-app-bar border="bottom">
    <v-btn icon variant="text" class="ml-2" @click="emit('toggle-drawer')">
      <v-icon>mdi-menu</v-icon>
    </v-btn>

    <div
      class="search-trigger d-flex align-center px-3 ml-3"
      role="button"
      tabindex="0"
      @click="emit('open-search')"
      @keydown.enter="emit('open-search')"
    >
      <v-icon size="small" class="mr-2 text-medium-emphasis">mdi-magnify</v-icon>
      <span class="text-body-2 text-medium-emphasis">
        {{ t('common.search') }}
      </span>
      <span class="kbd ml-3">{{ isMac ? '⌘K' : 'Ctrl+K' }}</span>
    </div>

    <template v-slot:append>
      <v-btn icon variant="text" class="mr-1" @click="emit('toggle-chat')">
        <v-icon>mdi-robot</v-icon>
        <v-tooltip activator="parent" location="bottom">
          {{ t('ai.title') }}
        </v-tooltip>
      </v-btn>

      <v-btn icon variant="text" class="mr-1">
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

      <v-btn icon variant="text" class="mr-2" @click="emit('toggle-help')">
        <v-icon>mdi-help</v-icon>
        <v-tooltip activator="parent" location="bottom">
          Help
        </v-tooltip>
      </v-btn>

      <UserMenu />
    </template>
  </v-app-bar>
</template>

<style scoped>
.search-trigger {
  height: 36px;
  min-width: 280px;
  border-radius: 8px;
  background-color: rgb(var(--v-theme-surface-light));
  cursor: pointer;
  user-select: none;
  transition: background-color 0.15s ease;
}
.search-trigger:hover {
  background-color: rgb(var(--v-theme-surface-variant));
}
.kbd {
  font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
  font-size: 0.7rem;
  padding: 1px 6px;
  border-radius: 4px;
  background-color: rgb(var(--v-theme-surface));
  color: rgb(var(--v-theme-on-surface-variant));
  border: 1px solid rgba(var(--v-theme-on-surface), 0.12);
}
</style>

<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import UserMenu from './UserMenu.vue'

const { t } = useI18n()

const emit = defineEmits<{
  'open-search': []
  'toggle-chat': []
  'toggle-help': []
  'toggle-drawer': []
}>()

const isMac = computed(() => navigator.platform.toUpperCase().indexOf('MAC') >= 0)
</script>
