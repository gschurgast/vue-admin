<template>
  <v-app-bar class="floating-app-bar" flat>
    <v-btn icon variant="text" class="ml-2" @click="emit('toggle-drawer')">
      <v-icon>mdi-menu</v-icon>
    </v-btn>

    <v-btn
      variant="text"
      class="ml-3 search-shortcut"
      @click="emit('open-search')"
    >
      <v-icon size="small" class="mr-2">mdi-magnify</v-icon>
      <span class="kbd">{{ isMac ? '⌘K' : 'Ctrl+K' }}</span>
      <v-tooltip activator="parent" location="bottom">
        {{ t('common.search') }}
      </v-tooltip>
    </v-btn>

    <template v-slot:append>
      <v-btn icon variant="text" class="mr-1" @click="toggleTheme">
        <v-icon>{{ isDark ? 'mdi-weather-sunny' : 'mdi-weather-night' }}</v-icon>
        <v-tooltip activator="parent" location="bottom">
          {{ isDark ? t('common.lightMode') : t('common.darkMode') }}
        </v-tooltip>
      </v-btn>

      <LanguageSwitcher />

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
.search-shortcut .kbd {
  font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
  font-size: 0.7rem;
  padding: 1px 6px;
  border-radius: 4px;
  background-color: rgb(var(--v-theme-surface-light));
  color: rgb(var(--v-theme-on-surface-variant));
  border: 1px solid rgba(var(--v-theme-on-surface), 0.12);
}
</style>

<!-- Floating app bar — global style because v-app-bar renders outside the
     component's scoped tree (Vuetify layout). -->
<style>
.v-app-bar.floating-app-bar {
  top: 12px !important;
  right: 12px !important;
  width: auto !important;
  border-radius: 16px;
  border: 1px solid rgba(var(--v-theme-on-surface), 0.06);
  box-shadow: 0 4px 16px rgba(0, 0, 0, 0.04);
  /* Vuetify positions left = drawer width; +12 compensates the drawer's own
     left offset and +12 more creates a visible gap between drawer and bar */
  margin-left: 24px;
}
</style>

<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import UserMenu from './UserMenu.vue'
import LanguageSwitcher from './LanguageSwitcher.vue'
import { useThemeMode } from '../../composables/useThemeMode'

const { t } = useI18n()
const { isDark, toggle: toggleTheme } = useThemeMode()

const emit = defineEmits<{
  'open-search': []
  'toggle-chat': []
  'toggle-help': []
  'toggle-drawer': []
}>()

const isMac = computed(() => navigator.platform.toUpperCase().indexOf('MAC') >= 0)
</script>
