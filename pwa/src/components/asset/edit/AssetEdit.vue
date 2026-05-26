<template>
  <!-- Rendered inside the parent's <v-card-text>, exactly like ProductEdit / UserEdit. -->
  <div v-if="formData">
    <!-- Duplicate warning (placed first so it can't be missed) -->
      <v-alert
        v-if="duplicateOfId"
        type="warning"
        variant="tonal"
        density="compact"
        class="mb-3"
      >
        <div class="d-flex align-center">
          <div class="flex-grow-1">
            {{ t('asset.duplicateOfWarning', { id: duplicateOfId }) }}
          </div>
          <v-btn
            size="small"
            variant="text"
            @click="goTo(duplicateOfId!)"
          >
            {{ t('asset.viewOriginal', 'View original') }}
          </v-btn>
        </div>
      </v-alert>

      <!-- Filename: read-only by default, pencil toggles editing -->
      <div class="filename-row mb-4">
        <v-text-field
          v-if="filenameEditing"
          v-model="formData.filename"
          :label="t('asset.filename', 'Filename')"
          variant="outlined"
          density="compact"
          hide-details
          autofocus
          :error="!!fieldErrors?.filename?.length"
          :error-messages="fieldErrors?.filename"
          @keyup.enter="filenameEditing = false"
          @blur="filenameEditing = false"
        />
        <h2
          v-else
          class="text-h5 font-weight-semibold text-truncate filename-title"
          :title="formData.filename"
        >
          {{ formData.filename }}
        </h2>
        <v-btn
          v-if="!duplicateOfId"
          icon
          :variant="filenameEditing ? 'flat' : 'text'"
          :color="filenameEditing ? 'primary' : undefined"
          size="small"
          density="comfortable"
          @click="filenameEditing = !filenameEditing"
        >
          <v-icon>{{ filenameEditing ? 'mdi-check' : 'mdi-pencil-outline' }}</v-icon>
          <v-tooltip activator="parent" location="bottom">
            {{ filenameEditing ? t('common.done', 'Done') : t('asset.renameTooltip', 'Rename') }}
          </v-tooltip>
        </v-btn>
      </div>

      <v-tabs v-model="activeTab" class="mb-3">
        <v-tab value="details">
          <v-icon start>mdi-information-outline</v-icon>
          {{ t('asset.tabDetails', 'Details') }}
        </v-tab>
        <v-tab value="similar" :disabled="formData.type !== 'image'">
          <v-icon start>mdi-image-multiple-outline</v-icon>
          {{ t('asset.tabSimilar', 'Similar') }}
          <v-chip
            v-if="similar.results.length"
            class="ml-2"
            size="x-small"
            variant="tonal"
          >
            {{ similar.results.length }}
          </v-chip>
        </v-tab>
      </v-tabs>

      <v-window v-model="activeTab">
        <!-- Tab: Details -->
        <v-window-item value="details">
          <div class="details-layout">
            <!-- Left: small preview -->
            <div class="details-preview">
              <v-progress-circular v-if="previewLoading[formData.id]" indeterminate />
              <img
                v-else-if="formData.type === 'image' && previewUrls[formData.id]"
                :src="previewUrls[formData.id]"
                :alt="formData.filename"
                class="preview-image"
              />
              <video
                v-else-if="formData.type === 'video' && previewUrls[formData.id]"
                :src="previewUrls[formData.id]"
                controls
                class="preview-video"
              />
              <iframe
                v-else-if="formData.type === 'pdf' && previewUrls[formData.id]"
                :src="previewUrls[formData.id]"
                class="preview-pdf"
              />
              <div v-else class="text-center pa-6">
                <v-icon size="64" color="grey-darken-1">{{ iconForType(formData.type) }}</v-icon>
                <div class="mt-2 text-caption text-medium-emphasis">
                  {{ t('asset.noPreview', 'No inline preview available') }}
                </div>
              </div>
            </div>

            <!-- Right: metadata + flags -->
            <div class="details-meta">
              <div class="metadata">
                <div><strong>{{ t('asset.type') }}:</strong> {{ formData.type }}</div>
                <div><strong>{{ t('asset.mimeType') }}:</strong> {{ formData.mimeType }}</div>
                <div><strong>{{ t('asset.size') }}:</strong> {{ humanSize(formData.size) }}</div>
                <div v-if="formData.width">
                  <strong>{{ t('asset.dimensions') }}:</strong>
                  {{ formData.width }} × {{ formData.height }}
                </div>
                <div v-if="formData.duration">
                  <strong>{{ t('asset.duration') }}:</strong> {{ formData.duration }}s
                </div>
                <div v-if="formData.embeddingStatus">
                  <strong>{{ t('asset.embeddingStatus', 'Embedding') }}:</strong>
                  <v-chip
                    size="x-small"
                    :color="embeddingStatusColor(formData.embeddingStatus)"
                    class="ml-1"
                  >
                    {{ t('asset.status_' + formData.embeddingStatus, formData.embeddingStatus) }}
                  </v-chip>
                </div>
              </div>

              <v-divider class="my-4" />

              <!-- Flags editor -->
              <div class="flags-section">
                <div class="d-flex align-center mb-2">
                  <strong>{{ t('asset.flags') }}</strong>
                </div>
                <v-autocomplete
                  v-model="selectedFlags"
                  :items="availableFlags"
                  item-title="label"
                  return-object
                  multiple
                  chips
                  closable-chips
                  variant="outlined"
                  density="compact"
                  :hide-details="!duplicateOfId"
                  :hint="duplicateOfId ? t('asset.flagsDisabledHint', 'Flags are managed on the original asset.') : ''"
                  :persistent-hint="!!duplicateOfId"
                  :disabled="!!duplicateOfId"
                  :loading="flagsLoading"
                  :placeholder="t('asset.flagsPlaceholder', 'Add flags…')"
                  @update:model-value="onFlagsChanged"
                >
                  <template #chip="{ props: chipProps, item: chipItem }">
                    <v-chip
                      v-bind="chipProps"
                      :color="((chipItem as any)?.raw ?? chipItem)?.color || 'primary'"
                      size="small"
                    >
                      {{ ((chipItem as any)?.raw ?? chipItem)?.label }}
                    </v-chip>
                  </template>
                </v-autocomplete>
                <v-alert
                  v-if="props.fieldErrors?.flags?.length"
                  type="error"
                  variant="tonal"
                  density="compact"
                  class="mt-2"
                >
                  {{ props.fieldErrors!.flags!.join(', ') }}
                </v-alert>
              </div>
            </div>
          </div>
        </v-window-item>

        <!-- Tab: Similar -->
        <v-window-item value="similar">
          <div v-if="similarLoading" class="text-center pa-6">
            <v-progress-circular indeterminate />
          </div>
          <div
            v-else-if="!similar.results.length"
            class="text-center pa-6 text-medium-emphasis"
          >
            {{ t('asset.noSimilar', 'No visually similar assets found.') }}
          </div>
          <div v-else class="similar-grid">
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
        </v-window-item>
      </v-window>

      <!-- Duplicate-delete confirmation -->
      <v-dialog v-model="showDeleteDialog" max-width="420">
        <v-card>
          <v-card-title>{{ t('asset.deleteDuplicateTitle', 'Delete this duplicate?') }}</v-card-title>
          <v-card-text>
            {{ t('asset.deleteDuplicateBody', { id: duplicateOfId }) }}
          </v-card-text>
          <v-card-actions>
            <v-spacer />
            <v-btn variant="text" @click="showDeleteDialog = false">{{ t('common.cancel') }}</v-btn>
            <v-btn color="error" variant="flat" :loading="deleting" @click="deleteDuplicate">
              {{ t('common.delete') }}
            </v-btn>
          </v-card-actions>
        </v-card>
      </v-dialog>
  </div>

  <!-- Custom footer: Cancel + Download + (Save | Delete duplicate).
       Teleported to the parent edit page anchor so the bar sits at the same
       DOM level as the default footer used by other resources (Product, User…). -->
  <Teleport v-if="formData" to="#edit-actions-anchor">
    <PageActionsFooter>
      <PageActionBtn
        kind="ghost"
        :disabled="props.saving || deleting"
        @click="props.onCancel?.()"
      >
        {{ t('common.cancel') }}
      </PageActionBtn>
      <PageActionBtn
        kind="secondary"
        prepend-icon="mdi-download-outline"
        :disabled="!formData.id"
        @click="triggerDownload"
      >
        {{ t('common.download', 'Download') }}
      </PageActionBtn>
      <PageActionBtn
        v-if="duplicateOfId"
        kind="danger"
        prepend-icon="mdi-delete-outline"
        :loading="deleting"
        @click="confirmDeleteDuplicate"
      >
        {{ t('asset.deleteDuplicate', 'Delete duplicate') }}
      </PageActionBtn>
      <PageActionBtn
        v-else
        kind="success"
        prepend-icon="mdi-content-save-outline"
        :loading="props.saving"
        @click="props.onSave?.()"
      >
        {{ t('common.save') }}
      </PageActionBtn>
    </PageActionsFooter>
  </Teleport>
</template>

<script setup lang="ts">
import { reactive, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRouter } from 'vue-router'
import { useAssetUrl } from '../../../composables/useAssetUrl'
import apiPlatform from '../../../services/apiPlatform'
import PageActionBtn from '../../../components/common/PageActionBtn.vue'
import PageActionsFooter from '../../../components/common/PageActionsFooter.vue'

/**
 * Custom edit component for Asset.
 *
 * Parent (`pages/edit/[resource]/[id].vue`) drives the form lifecycle:
 *   - loads the asset into `formData`
 *   - persists `formData` via PATCH when the user clicks Save in the footer
 * This component renders fields bound to `formData`, plus side-info (preview,
 * similar tab, duplicate banner). It owns its own action footer (Cancel /
 * Download / Save|DeleteDuplicate) because the layout differs from the default
 * — the parent skips its default footer thanks to edit.customActions in the
 * resource JSON config.
 */
const formData = defineModel<any>('formData', { required: true })

interface FieldErrors { [k: string]: string[] }
const props = defineProps<{
  fieldErrors?: FieldErrors
  saving?: boolean
  onSave?: () => void | Promise<void>
  onCancel?: () => void
}>()

const filenameEditing = ref(false)

const { t } = useI18n()
const router = useRouter()
const { urls: previewUrls, loading: previewLoading, load: loadPreview } = useAssetUrl()
const { urls: similarUrls, load: loadSimilarPreview } = useAssetUrl()

const activeTab = ref<'details' | 'similar'>('details')

interface SimilarItem {
  id: number
  filename: string
  type: string
  similarity: number
}
const similar = reactive<{ results: SimilarItem[] }>({ results: [] })
const similarLoading = ref(false)
const duplicateOfId = ref<number | null>(null)

interface FlagOption {
  '@id': string
  id: number
  code: string
  label: string
  color?: string | null
}
const availableFlags = ref<FlagOption[]>([])
const selectedFlags = ref<FlagOption[]>([])
const flagsLoading = ref(false)

const showDeleteDialog = ref(false)
const deleting = ref(false)

function embeddingStatusColor(status: string): string {
  return { ready: 'success', pending: 'info', failed: 'error', skipped: 'grey' }[status] ?? 'grey'
}
function scoreColor(score: number): string {
  if (score >= 0.95) return 'error'
  if (score >= 0.85) return 'warning'
  return 'primary'
}
function goTo(id: number) {
  router.push(`/edit/Asset/${id}`)
}
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

/**
 * Streaming download.
 *
 * Plain `window.open(...)` would fail with 401 because the new tab doesn't
 * carry the JWT — auth is on the axios Authorization header, not cookies.
 * Instead, fetch the bytes via the authenticated axios client, build a blob
 * URL and trigger a temporary <a download> click. The blob URL is revoked
 * right after to free memory.
 */
async function triggerDownload() {
  if (!formData.value?.id) return
  try {
    const response = await apiPlatform.client.get(
      `/api/assets/${formData.value.id}/content?download=1`,
      { responseType: 'blob' },
    )
    const blobUrl = URL.createObjectURL(response.data as Blob)
    const a = document.createElement('a')
    a.href = blobUrl
    a.download = formData.value.filename || `asset-${formData.value.id}`
    document.body.appendChild(a)
    a.click()
    document.body.removeChild(a)
    URL.revokeObjectURL(blobUrl)
  } catch (e) {
    console.error('Download failed', e)
  }
}

async function loadSimilar(id: number) {
  similar.results = []
  duplicateOfId.value = null
  similarLoading.value = true
  try {
    const { data } = await apiPlatform.client.get(`/api/assets/${id}/similar`)
    if (data?.status === 'ready') {
      similar.results = data.results ?? []
      duplicateOfId.value = data.duplicateOfId ?? null
      for (const s of similar.results) {
        if (s.type === 'image') loadSimilarPreview(s.id)
      }
    }
  } catch (e) {
    // best-effort
  } finally {
    similarLoading.value = false
  }
}

async function loadFlagsCatalog() {
  if (availableFlags.value.length > 0) return
  flagsLoading.value = true
  try {
    const result = await apiPlatform.getList('/api/asset_flags', { itemsPerPage: 100 })
    availableFlags.value = result.data as FlagOption[]
  } finally {
    flagsLoading.value = false
  }
}

/**
 * Sync formData.flags <-> selectedFlags. The form persists `flags` as an
 * array of IRIs (API Platform expects relations as IRIs in JSON-LD payloads);
 * the autocomplete works on objects so labels render correctly.
 */
function syncSelectedFromForm() {
  const current = (formData.value?.flags ?? []) as Array<string | { ['@id']?: string; id?: number }>
  selectedFlags.value = current
    .map((f) => {
      if (typeof f === 'string') {
        return availableFlags.value.find((af) => af['@id'] === f) ?? null
      }
      if (f && typeof f === 'object') {
        const iri = (f as any)['@id']
        if (iri) return availableFlags.value.find((af) => af['@id'] === iri) ?? null
        if ((f as any).id !== undefined) {
          return availableFlags.value.find((af) => af.id === (f as any).id) ?? null
        }
      }
      return null
    })
    .filter((v): v is FlagOption => v !== null)
}

function onFlagsChanged() {
  if (!formData.value) return
  formData.value.flags = selectedFlags.value.map((f) => f['@id'])
}

function confirmDeleteDuplicate() {
  showDeleteDialog.value = true
}
async function deleteDuplicate() {
  if (!formData.value?.id) return
  deleting.value = true
  try {
    await apiPlatform.client.delete(`/api/assets/${formData.value.id}`)
    showDeleteDialog.value = false
    if (duplicateOfId.value) {
      router.replace(`/edit/Asset/${duplicateOfId.value}`)
    } else {
      router.replace('/resource/Asset')
    }
  } catch (e: any) {
    showDeleteDialog.value = false
    console.error('Failed to delete duplicate', e)
  } finally {
    deleting.value = false
  }
}

watch(
  () => formData.value?.id,
  async (id) => {
    if (!id) return
    const type = formData.value?.type
    if (type === 'image' || type === 'video' || type === 'pdf') {
      loadPreview(id)
    }
    if (type === 'image') {
      loadSimilar(id)
    } else {
      similar.results = []
      duplicateOfId.value = null
    }
    await loadFlagsCatalog()
    syncSelectedFromForm()
  },
  { immediate: true },
)
</script>

<style scoped>
.filename-row {
  display: flex;
  align-items: center;
  gap: 8px;
  /* min-width 0 lets text-truncate work inside a flex container */
  min-width: 0;
}
.filename-row .filename-title {
  /* Allow truncation but don't grow — the pencil icon stays next to the text. */
  max-width: 100%;
  min-width: 0;
}
.filename-row .v-input {
  flex: 1 1 100%;
}
.details-layout {
  display: grid;
  grid-template-columns: minmax(220px, 280px) 1fr;
  gap: 24px;
  align-items: start;
}
@media (max-width: 720px) {
  .details-layout {
    grid-template-columns: 1fr;
  }
}
.details-preview {
  display: flex;
  align-items: center;
  justify-content: center;
  background: rgba(var(--v-theme-on-surface), 0.04);
  border-radius: 8px;
  overflow: hidden;
  min-height: 200px;
  max-height: 280px;
}
.preview-image {
  max-width: 100%;
  max-height: 280px;
  object-fit: contain;
}
.preview-video {
  width: 100%;
  max-height: 280px;
  border: 0;
}
.preview-pdf {
  width: 100%;
  height: 280px;
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
