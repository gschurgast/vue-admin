<template>
  <v-alert
    v-if="warnings && warnings.length > 0"
    type="warning"
    variant="tonal"
    class="mb-4"
    border="start"
    :title="t('asset_transformation.warnings.title')"
  >
    <ul class="warning-list">
      <li v-for="(w, idx) in warnings" :key="`${w.code}-${idx}`">
        {{ messageFor(w) }}
        <span v-if="typeof w.stepIndex === 'number'" class="text-caption">
          ({{ t('asset_transformation.warnings.step_label', { n: w.stepIndex + 1 }) }})
        </span>
      </li>
    </ul>
  </v-alert>
</template>

<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import type { TransformationWarning } from '@/composables/useTransformationWarnings'

interface Props {
  warnings?: TransformationWarning[]
}

withDefaults(defineProps<Props>(), {
  warnings: () => [],
})

const { t, te } = useI18n()

function messageFor(w: TransformationWarning): string {
  if (w.message) return w.message
  const key = `asset_transformation.warnings.${w.code}`
  // Fall back to the warning code itself if no i18n key is registered yet
  return te(key) ? t(key) : w.code
}
</script>

<style scoped>
.warning-list {
  margin: 0;
  padding-left: 1.2rem;
}
</style>
