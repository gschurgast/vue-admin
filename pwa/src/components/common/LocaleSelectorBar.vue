<template>
  <div class="locale-bar">
    <div class="locale-bar__label">
      <v-icon icon="mdi-translate" size="20" class="mr-2" />
      <span>{{ t('locale.editingIn') }}</span>
    </div>

    <v-menu offset="6">
      <template #activator="{ props: activatorProps }">
        <v-btn
          v-bind="activatorProps"
          variant="tonal"
          size="small"
          class="locale-bar__chip"
          :loading="loading"
          append-icon="mdi-chevron-down"
        >
          <span class="locale-bar__flag">{{ currentLocale?.flag ?? '🌐' }}</span>
          <span class="locale-bar__name">{{ currentLocale?.label ?? modelValue }}</span>
          <span class="locale-bar__code text-medium-emphasis">{{ modelValue }}</span>
        </v-btn>
      </template>

      <v-list density="compact" min-width="220">
        <v-list-item
          v-for="loc in locales"
          :key="loc.code"
          :active="loc.code === modelValue"
          @click="select(loc.code)"
        >
          <template #prepend>
            <span class="locale-bar__flag">{{ loc.flag }}</span>
          </template>
          <v-list-item-title>{{ loc.label }}</v-list-item-title>
          <v-list-item-subtitle class="text-caption">{{ loc.code }}</v-list-item-subtitle>
        </v-list-item>
      </v-list>
    </v-menu>

    <span v-if="modelValue !== uiLocale" class="locale-bar__hint text-caption text-medium-emphasis">
      <v-icon icon="mdi-information-outline" size="14" class="mr-1" />
      {{ t('locale.uiUnchanged') }}
    </span>
  </div>
</template>

<script setup lang="ts">
import { computed, onMounted } from 'vue'
import { useI18n } from 'vue-i18n'
import { useLocales } from '../../composables/useLocales'

interface Props {
  modelValue: string
}

const props = defineProps<Props>()
const emit = defineEmits<{ 'update:modelValue': [value: string] }>()

const { t, locale: uiLocale } = useI18n()
const { locales, loading, loadLocales } = useLocales()

const currentLocale = computed(() => locales.value.find(l => l.code === props.modelValue) ?? null)

function select(code: string) {
  if (code !== props.modelValue) emit('update:modelValue', code)
}

onMounted(loadLocales)
</script>

<style scoped>
.locale-bar {
  display: flex;
  align-items: center;
  gap: 12px;
  padding: 10px 14px;
  margin-bottom: 16px;
  border-radius: 10px;
  background-color: rgba(var(--v-theme-on-surface), 0.04);
  border: 1px solid rgba(var(--v-border-color), 0.12);
}

.locale-bar__label {
  display: inline-flex;
  align-items: center;
  font-size: 0.85rem;
  font-weight: 500;
  color: rgba(var(--v-theme-on-surface), 0.75);
}

.locale-bar__chip {
  letter-spacing: normal;
  text-transform: none;
}

.locale-bar__flag {
  font-size: 1.05rem;
  margin-right: 6px;
}

.locale-bar__name {
  font-weight: 500;
}

.locale-bar__code {
  margin-left: 6px;
  font-size: 0.72rem;
  letter-spacing: 0.04em;
}

.locale-bar__hint {
  display: inline-flex;
  align-items: center;
}
</style>
