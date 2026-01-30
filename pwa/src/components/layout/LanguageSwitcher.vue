<template>
  <div>
    <v-list-item
      prepend-icon="mdi-translate"
      @click.stop="expanded = !expanded"
    >
      <v-list-item-title>{{ t('common.language') }}</v-list-item-title>
      <template v-slot:append>
        <v-icon>{{ expanded ? 'mdi-chevron-up' : 'mdi-chevron-down' }}</v-icon>
      </template>
    </v-list-item>

    <v-expand-transition>
      <div v-show="expanded" class="px-4 pb-2">
        <div class="d-flex flex-column ga-1">
          <div
            v-for="(row, rowIndex) in localeRows"
            :key="rowIndex"
            class="d-flex ga-1"
          >
            <v-btn
              v-for="lang in row"
              :key="lang.code"
              icon
              :variant="locale === lang.code ? 'tonal' : 'text'"
              :color="locale === lang.code ? 'primary' : undefined"
              @click.stop="changeLocale(lang.code)"
            >
              {{ lang.flag }}
            </v-btn>
          </div>
        </div>
      </div>
    </v-expand-transition>
  </div>
</template>

<script setup lang="ts">
import { ref, computed, onMounted } from 'vue'
import { useI18n } from 'vue-i18n'
import { loadLocaleMessages } from '../../plugins/i18n'
import { useLocales } from '../../composables/useLocales'

const { t, locale } = useI18n()
const { locales, loadLocales } = useLocales()

const expanded = ref(false)

const localeRows = computed(() => {
  const rows: typeof locales.value[] = []
  for (let i = 0; i < locales.value.length; i += 4) {
    rows.push(locales.value.slice(i, i + 4))
  }
  return rows
})

async function changeLocale(newLocale: string) {
  await loadLocaleMessages(newLocale)
}

onMounted(() => {
  loadLocales()
})
</script>
