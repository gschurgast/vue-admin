<template>
  <v-card variant="outlined" class="pa-4 mt-4">
    <div class="d-flex align-center justify-space-between mb-3">
      <h3 class="text-subtitle-1 font-weight-medium">{{ t('preview.title') }}</h3>
      <div class="d-flex ga-2">
        <PageActionBtn kind="secondary" @click="dialogOpen = true">
          {{ selectedAssetId ? t('preview.change_asset') : t('preview.choose_asset') }}
          <template v-if="selectedAssetId">
            &nbsp;(#{{ selectedAssetId }})
          </template>
        </PageActionBtn>
        <PageActionBtn
          kind="primary"
          :disabled="!canPreview || isLoading"
          @click="runPreview"
        >
          <v-progress-circular v-if="isLoading" indeterminate size="18" width="2" class="mr-2" />
          {{ t('preview.button') }}
        </PageActionBtn>
      </div>
    </div>

    <v-alert
      v-if="error?.status === 429"
      type="warning"
      variant="tonal"
      density="comfortable"
      class="mb-3"
    >
      {{ t('preview.rate_limited', { seconds: error.retryAfter ?? '?' }) }}
    </v-alert>

    <v-alert
      v-else-if="error?.status === 404"
      type="info"
      variant="tonal"
      density="comfortable"
      class="mb-3"
    >
      {{ t('preview.asset_not_public') }}
    </v-alert>

    <v-alert
      v-else-if="error && error.status !== 0"
      type="error"
      variant="tonal"
      density="comfortable"
      class="mb-3"
    >
      {{ t('preview.error_generic') }} ({{ error.status }})
    </v-alert>

    <div v-if="!selectedAssetId" class="text-medium-emphasis text-body-2">
      {{ t('preview.no_asset_selected') }}
    </div>

    <div v-if="url" class="preview-image-wrap">
      <img :src="url" :alt="t('preview.title')" class="preview-image" />
    </div>

    <AssetPickerDialog
      v-model="dialogOpen"
      :transformation-id="transformationId"
      @select="onAssetSelected"
    />
  </v-card>
</template>

<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useLocalStorage } from '@vueuse/core'
import { usePreviewUrl, type PreviewStep } from '../../../composables/usePreviewUrl'
import AssetPickerDialog from './AssetPickerDialog.vue'
import PageActionBtn from '../../common/PageActionBtn.vue'

interface Props {
  steps: PreviewStep[]
  outputExt?: string
  transformationId: number | string | null
}

const props = withDefaults(defineProps<Props>(), {
  outputExt: 'png',
})

const { t } = useI18n()

const dialogOpen = ref(false)

const selectedAssetId = useLocalStorage<number | null>(
  () => `preview_asset_${props.transformationId ?? 'unknown'}`,
  null,
)

const { url, error, isLoading, refresh, clear } = usePreviewUrl()

const canPreview = computed(() => {
  return !!selectedAssetId.value && Array.isArray(props.steps) && props.steps.length > 0
})

function onAssetSelected(assetId: number) {
  selectedAssetId.value = assetId
  clear()
}

async function runPreview() {
  if (!selectedAssetId.value) return
  await refresh({
    assetId: selectedAssetId.value,
    ext: props.outputExt ?? 'png',
    steps: props.steps,
  })
}

// Invalider la preview affichée quand les steps changent (sinon l'utilisateur
// croit voir le résultat de sa dernière édition alors qu'elle n'a pas été
// re-soumise).
watch(
  () => JSON.stringify(props.steps),
  () => clear(),
)
</script>

<style scoped>
.preview-image-wrap {
  display: flex;
  justify-content: center;
  align-items: center;
  background: var(--v-theme-surface-variant, #f5f5f5);
  border-radius: 8px;
  padding: 16px;
  min-height: 200px;
  max-height: 500px;
  overflow: auto;
}
.preview-image {
  max-width: 100%;
  max-height: 460px;
  object-fit: contain;
}
</style>