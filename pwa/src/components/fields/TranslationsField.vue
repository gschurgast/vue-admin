<template>
  <v-card variant="outlined" class="mb-4">
    <v-card-title class="text-subtitle-1">
      {{ label || t('common.translations') }}
    </v-card-title>
    <v-card-text>
      <v-row v-for="locale in locales" :key="locale.code" align="center" class="mb-2">
        <v-col cols="2" class="d-flex align-center">
          <span class="text-body-2 font-weight-medium">{{ locale.label }}</span>
        </v-col>
        <v-col cols="10">
          <v-text-field
            v-model="translationValues[locale.code]"
            :label="locale.label"
            :placeholder="t('common.translationPlaceholder', { locale: locale.label })"
            density="compact"
            hide-details="auto"
            :loading="translatingFromLocale === locale.code"
            :append-inner-icon="translationValues[locale.code]?.trim() && hasEmptyLocales(locale.code) ? 'mdi-translate' : undefined"
            @click:append-inner="translateFromLocale(locale.code)"
            @update:model-value="updateTranslations"
          />
        </v-col>
      </v-row>
    </v-card-text>
  </v-card>
</template>

<script setup lang="ts">
import { ref, watch, onMounted } from 'vue'
import { useI18n } from 'vue-i18n'
import { useLocales } from '../../composables/useLocales'
import apiPlatform from '../../services/apiPlatform'

interface Translation {
  '@id'?: string
  id?: number
  locale: string
  label: string
}

interface Props {
  modelValue: Translation[] | null | undefined
  label?: string
  errorMessages?: string[]
}

const props = withDefaults(defineProps<Props>(), {
  label: '',
  errorMessages: () => []
})

const emit = defineEmits<{
  'update:modelValue': [value: Translation[]]
}>()

const { t } = useI18n()
const { locales, loadLocales } = useLocales()

const translationValues = ref<Record<string, string>>({})
const translatingFromLocale = ref<string | null>(null)

// Check if there are empty locales (other than the given one)
function hasEmptyLocales(excludeLocale: string): boolean {
  return locales.value.some(
    locale => locale.code !== excludeLocale && !translationValues.value[locale.code]?.trim()
  )
}

// Get empty locales (other than the given one)
function getEmptyLocales(excludeLocale: string): string[] {
  return locales.value
    .filter(locale => locale.code !== excludeLocale && !translationValues.value[locale.code]?.trim())
    .map(locale => locale.code)
}

function initializeValues() {
  const values: Record<string, string> = {}
  locales.value.forEach(locale => {
    values[locale.code] = ''
  })

  if (props.modelValue && Array.isArray(props.modelValue)) {
    props.modelValue.forEach(translation => {
      if (typeof translation === 'object' && translation.locale && translation.label) {
        values[translation.locale] = translation.label
      }
    })
  }

  translationValues.value = values
}

function updateTranslations() {
  const translations: Translation[] = []

  locales.value.forEach(locale => {
    const label = translationValues.value[locale.code]
    if (label && label.trim()) {
      const existing = props.modelValue?.find(t => t.locale === locale.code)
      if (existing && existing['@id']) {
        translations.push({
          '@id': existing['@id'],
          locale: locale.code,
          label: label.trim()
        })
      } else {
        translations.push({
          locale: locale.code,
          label: label.trim()
        })
      }
    }
  })

  emit('update:modelValue', translations)
}

async function translateToLocale(sourceLocale: string, sourceText: string, targetLocale: string): Promise<void> {
  try {
    const response = await apiPlatform.client.post('/api/translate', {
      text: sourceText,
      sourceLocale,
      targetLocale
    }, {
      headers: {
        'Content-Type': 'application/ld+json',
        'Accept': 'application/ld+json'
      }
    })

    const translation = response.data.translation
    if (translation && typeof translation === 'string') {
      translationValues.value[targetLocale] = translation
    }
  } catch (error) {
    console.error(`Translation error for ${targetLocale}:`, error)
  }
}

async function translateFromLocale(sourceLocale: string) {
  const sourceText = translationValues.value[sourceLocale]?.trim()
  if (!sourceText) return

  const emptyLocales = getEmptyLocales(sourceLocale)
  if (emptyLocales.length === 0) return

  translatingFromLocale.value = sourceLocale

  try {
    // Translate to all empty locales in parallel
    const promises = emptyLocales.map(targetLocale =>
      translateToLocale(sourceLocale, sourceText, targetLocale)
    )
    await Promise.all(promises)
    updateTranslations()
  } finally {
    translatingFromLocale.value = null
  }
}

watch(() => props.modelValue, () => {
  initializeValues()
}, { deep: true })

watch(locales, () => {
  initializeValues()
})

onMounted(async () => {
  await loadLocales()
  initializeValues()
})
</script>
