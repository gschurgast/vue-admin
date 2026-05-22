<template>
  <v-container fluid>
    <v-card v-if="item" class="pa-4">
      <div class="d-flex align-center mb-4">
        <h2 class="text-h5 font-weight-semibold flex-grow-1">{{ item.filename }}</h2>
        <v-btn
          prepend-icon="mdi-download-outline"
          variant="tonal"
          :href="downloadUrl"
          target="_blank"
        >
          {{ t('common.download', 'Download') }}
        </v-btn>
      </div>

      <div class="preview-area mb-4">
        <v-progress-circular v-if="previewLoading[item.id]" indeterminate />

        <img
          v-else-if="item.type === 'image' && previewUrls[item.id]"
          :src="previewUrls[item.id]"
          :alt="item.filename"
          class="preview-image"
        />

        <video
          v-else-if="item.type === 'video' && previewUrls[item.id]"
          :src="previewUrls[item.id]"
          controls
          class="preview-video"
        />

        <iframe
          v-else-if="item.type === 'pdf' && previewUrls[item.id]"
          :src="previewUrls[item.id]"
          class="preview-pdf"
        />

        <div v-else class="text-center pa-8">
          <v-icon size="96" color="grey-darken-1">{{ iconForType(item.type) }}</v-icon>
          <div class="mt-2 text-medium-emphasis">{{ t('asset.noPreview', 'No inline preview available') }}</div>
        </div>
      </div>

      <v-divider class="mb-4" />

      <div class="metadata">
        <div><strong>{{ t('asset.code') }}:</strong> {{ item.code }}</div>
        <div><strong>{{ t('asset.type') }}:</strong> {{ item.type }}</div>
        <div><strong>{{ t('asset.mimeType') }}:</strong> {{ item.mimeType }}</div>
        <div><strong>{{ t('asset.size') }}:</strong> {{ humanSize(item.size) }}</div>
        <div v-if="item.width"><strong>{{ t('asset.dimensions') }}:</strong> {{ item.width }} × {{ item.height }}</div>
        <div v-if="item.duration"><strong>{{ t('asset.duration') }}:</strong> {{ item.duration }}s</div>
        <div v-if="item.flags?.length">
          <strong>{{ t('asset.flags') }}:</strong>
          <v-chip
            v-for="f in item.flags"
            :key="f['@id'] ?? f.id"
            :color="f.color || 'primary'"
            size="small"
            class="ml-1"
          >
            {{ f.label }}
          </v-chip>
        </div>
        <div v-if="item.embeddingStatus">
          <strong>{{ t('asset.embeddingStatus', 'Embedding') }}:</strong>
          <v-chip
            size="x-small"
            :color="embeddingStatusColor(item.embeddingStatus)"
            class="ml-1"
          >
            {{ t('asset.status_' + item.embeddingStatus, item.embeddingStatus) }}
          </v-chip>
        </div>
      </div>

      <!-- Similar assets -->
      <template v-if="similar.results.length || similar.duplicateOfId">
        <v-divider class="my-4" />
        <h3 class="text-h6 mb-2">
          {{ t('asset.similarTitle', 'Visually similar') }}
        </h3>
        <v-alert
          v-if="similar.duplicateOfId"
          type="warning"
          variant="tonal"
          density="compact"
          class="mb-3"
        >
          {{ t('asset.duplicateOfWarning', { id: similar.duplicateOfId }) }}
        </v-alert>
        <div class="similar-grid">
          <v-card
            v-for="s in similar.results"
            :key="s.id"
            variant="outlined"
            class="similar-tile"
            @click="goTo(s.id)"
          >
            <div class="similar-tile__preview">
              <img
                v-if="similarUrls[s.id]"
                :src="similarUrls[s.id]"
                :alt="s.filename"
              />
              <v-progress-circular v-else indeterminate size="20" />
            </div>
            <div class="pa-1 text-caption text-truncate" :title="s.filename">
              {{ s.filename }}
            </div>
            <div class="pa-1 pt-0">
              <v-chip size="x-small" :color="scoreColor(s.similarity)">
                {{ Math.round(s.similarity * 100) }} %
              </v-chip>
            </div>
          </v-card>
        </div>
      </template>
    </v-card>
  </v-container>
</template>

<script setup lang="ts">
import { computed, reactive, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRouter } from 'vue-router'
import { useAssetUrl } from '../../../composables/useAssetUrl'
import apiPlatform from '../../../services/apiPlatform'

interface Props {
  item?: any
}
const props = defineProps<Props>()

const { t } = useI18n()
const router = useRouter()
const { urls: previewUrls, loading: previewLoading, load: loadPreview } = useAssetUrl()
const { urls: similarUrls, load: loadSimilarPreview } = useAssetUrl()

interface SimilarItem {
  id: number
  filename: string
  type: string
  similarity: number
}
const similar = reactive<{
  results: SimilarItem[]
  duplicateOfId: number | null
}>({
  results: [],
  duplicateOfId: null,
})

function embeddingStatusColor(status: string): string {
  return { ready: 'success', pending: 'info', failed: 'error', skipped: 'grey' }[status] ?? 'grey'
}
function scoreColor(score: number): string {
  if (score >= 0.95) return 'error'
  if (score >= 0.85) return 'warning'
  return 'primary'
}
function goTo(id: number) {
  router.push(`/show/Asset/${id}`)
}

async function loadSimilar(id: number) {
  similar.results = []
  similar.duplicateOfId = null
  try {
    const { data } = await apiPlatform.client.get(`/api/assets/${id}/similar`)
    if (data?.status === 'ready') {
      similar.results = data.results ?? []
      similar.duplicateOfId = data.duplicateOfId ?? null
      for (const s of similar.results) {
        if (s.type === 'image') loadSimilarPreview(s.id)
      }
    }
  } catch (e) {
    // Silent — similar is best-effort.
  }
}

const downloadUrl = computed(() =>
  props.item ? `/api/assets/${props.item.id}/content?download=1` : ''
)

function iconForType(type: string): string {
  switch (type) {
    case 'image': return 'mdi-image-outline'
    case 'pdf': return 'mdi-file-pdf-box'
    case 'video': return 'mdi-video-outline'
    case 'doc': return 'mdi-file-document-outline'
    default: return 'mdi-file-outline'
  }
}

function humanSize(bytes: number): string {
  if (!bytes) return '0 B'
  const units = ['B', 'KB', 'MB', 'GB']
  let n = bytes
  let u = 0
  while (n >= 1024 && u < units.length - 1) { n /= 1024; u++ }
  return `${n.toFixed(n < 10 && u > 0 ? 1 : 0)} ${units[u]}`
}

watch(
  () => props.item?.id,
  (id) => {
    if (!id) return
    if (props.item?.type === 'image' || props.item?.type === 'video' || props.item?.type === 'pdf') {
      loadPreview(id)
    }
    if (props.item?.type === 'image') {
      loadSimilar(id)
    }
  },
  { immediate: true },
)
</script>

<style scoped>
.preview-area {
  display: flex;
  align-items: center;
  justify-content: center;
  min-height: 320px;
  background: rgba(var(--v-theme-on-surface), 0.04);
  border-radius: 8px;
  overflow: hidden;
}
.preview-image {
  max-width: 100%;
  max-height: 70vh;
  object-fit: contain;
}
.preview-video,
.preview-pdf {
  width: 100%;
  height: 70vh;
  border: 0;
}
.metadata > div {
  margin-bottom: 6px;
}
.similar-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(140px, 1fr));
  gap: 12px;
}
.similar-tile {
  cursor: pointer;
  transition: transform 0.1s;
}
.similar-tile:hover {
  transform: translateY(-2px);
}
.similar-tile__preview {
  aspect-ratio: 1;
  display: flex;
  align-items: center;
  justify-content: center;
  background: rgba(var(--v-theme-on-surface), 0.04);
  overflow: hidden;
}
.similar-tile__preview img {
  width: 100%;
  height: 100%;
  object-fit: cover;
}
</style>
