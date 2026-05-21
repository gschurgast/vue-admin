<template>
  <v-menu offset="8" location="bottom end">
    <template v-slot:activator="{ props }">
      <v-btn
        icon
        variant="text"
        class="mr-1"
        v-bind="props"
        @mouseenter="loadLocales"
      >
        <span class="language-flag">{{ activeFlag }}</span>
        <v-tooltip activator="parent" location="bottom">
          {{ t('common.language') }}
        </v-tooltip>
      </v-btn>
    </template>

    <v-card min-width="220" class="pa-1">
      <v-list density="compact" nav>
        <v-list-item
          v-for="lang in locales"
          :key="lang.code"
          :active="locale === lang.code"
          color="primary"
          @click="changeLocale(lang.code)"
        >
          <template v-slot:prepend>
            <span class="language-flag mr-2">{{ lang.flag }}</span>
          </template>
          <v-list-item-title>{{ lang.label }}</v-list-item-title>
        </v-list-item>
      </v-list>
    </v-card>
  </v-menu>
</template>

<script setup lang="ts">
import { computed, onMounted } from 'vue'
import { useI18n } from 'vue-i18n'
import { loadLocaleMessages } from '../../plugins/i18n'
import { useLocales } from '../../composables/useLocales'

const { t, locale } = useI18n()
const { locales, loadLocales } = useLocales()

const activeFlag = computed(() => {
  const match = locales.value.find(l => l.code === locale.value)
  return match?.flag || '🌐'
})

async function changeLocale(newLocale: string) {
  await loadLocaleMessages(newLocale)
}

onMounted(() => {
  loadLocales()
})
</script>

<style scoped>
.language-flag {
  font-size: 1.25rem;
  line-height: 1;
}
</style>
