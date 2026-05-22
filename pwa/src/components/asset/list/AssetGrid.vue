<template>
  <v-card class="asset-grid-card">
    <!-- Header -->
    <div class="d-flex align-center pa-4">
      <h2 class="text-h6 font-weight-semibold">{{ title }}</h2>
      <v-chip v-if="!loading" size="small" variant="tonal" class="ml-3">
        {{ totalItems }}
      </v-chip>
      <v-spacer />
      <v-btn
        prepend-icon="mdi-refresh"
        size="small"
        variant="tonal"
        :disabled="loading"
        @click="refresh"
      >
        {{ t('common.refresh', 'Refresh') }}
      </v-btn>
    </div>
    <v-divider />

    <!-- Filters slot (from [resource].vue) -->
    <slot name="filters" />

    <!-- Drag & drop zone -->
    <div
      class="upload-zone pa-6 ma-4"
      :class="{ 'upload-zone--active': isDragging }"
      @dragenter.prevent="onDragEnter"
      @dragover.prevent="onDragEnter"
      @dragleave.prevent="onDragLeave"
      @drop.prevent="onDrop"
      @click="triggerFileInput"
    >
      <input
        ref="fileInput"
        type="file"
        multiple
        class="d-none"
        @change="onFilePick"
      />
      <div class="text-center">
        <v-icon size="48" color="primary" class="mb-2">mdi-cloud-upload-outline</v-icon>
        <div class="text-h6 mb-1">
          {{ isDragging ? t('asset.dropHere', 'Drop files here') : t('asset.dragHint', 'Drag & drop files here or click to browse') }}
        </div>
        <div class="text-caption text-medium-emphasis">
          {{ t('asset.supportedTypes', 'Images, PDF, video, documents') }}
        </div>
      </div>
    </div>

    <!-- Upload progress -->
    <div v-if="uploads.length" class="px-4 pb-2">
      <div
        v-for="up in uploads"
        :key="up.id"
        class="d-flex align-center mb-2"
      >
        <v-icon
          :color="up.status === 'error' ? 'error' : up.status === 'duplicate' ? 'warning' : up.status === 'done' ? 'success' : 'primary'"
          class="mr-2"
        >
          {{
            up.status === 'error' ? 'mdi-alert-circle-outline'
            : up.status === 'duplicate' ? 'mdi-content-duplicate'
            : up.status === 'done' ? 'mdi-check-circle-outline'
            : 'mdi-progress-upload'
          }}
        </v-icon>
        <div class="flex-grow-1">
          <div class="text-body-2">{{ up.filename }}</div>
          <v-progress-linear
            v-if="up.status === 'uploading'"
            :model-value="up.progress"
            height="4"
            color="primary"
          />
          <div v-else-if="up.status === 'error'" class="text-caption text-error">
            {{ up.error }}
          </div>
          <div v-else-if="up.status === 'duplicate'" class="text-caption text-warning">
            {{ t('asset.duplicate', 'Already in the library — existing asset reused.') }}
          </div>
        </div>
        <v-btn
          v-if="up.status !== 'uploading'"
          icon="mdi-close"
          size="x-small"
          variant="text"
          @click="dismissUpload(up.id)"
        />
      </div>
    </div>

    <!-- Grid -->
    <div v-if="loading" class="pa-8 text-center">
      <v-progress-circular indeterminate color="primary" />
    </div>

    <div v-else-if="items.length === 0" class="pa-8 text-center text-medium-emphasis">
      {{ t('asset.empty', 'No assets yet — drop files above to start.') }}
    </div>

    <div v-else class="asset-grid pa-4">
      <v-card
        v-for="item in items"
        :key="item.id"
        class="asset-tile"
        variant="outlined"
        @click="$emit('view', item)"
      >
        <div class="asset-tile__preview">
          <img
            v-if="item.type === 'image' && previewUrls[item.id]"
            :src="previewUrls[item.id]"
            :alt="item.filename"
          />
          <v-progress-circular
            v-else-if="item.type === 'image' && previewLoading[item.id]"
            indeterminate
            size="24"
          />
          <v-icon v-else size="48" color="grey-darken-1">
            {{ iconForType(item.type) }}
          </v-icon>
          <v-chip
            v-if="item.duplicateOf"
            class="tile-badge"
            size="x-small"
            color="warning"
            prepend-icon="mdi-content-duplicate"
          >
            {{ t('asset.duplicateBadge', 'duplicate') }}
          </v-chip>
          <v-chip
            v-else-if="item.type === 'image' && item.embeddingStatus === 'pending'"
            class="tile-badge"
            size="x-small"
            color="info"
            prepend-icon="mdi-progress-clock"
          >
            {{ t('asset.status_pending', 'pending') }}
          </v-chip>
        </div>
        <div class="pa-2">
          <div class="text-body-2 text-truncate" :title="item.filename">
            {{ item.filename }}
          </div>
          <div class="text-caption text-medium-emphasis d-flex align-center">
            <span>{{ humanSize(item.size) }}</span>
            <v-spacer />
            <v-btn
              icon="mdi-pencil-outline"
              size="x-small"
              variant="text"
              @click.stop="$emit('edit', item)"
            />
            <v-btn
              icon="mdi-delete-outline"
              size="x-small"
              variant="text"
              color="error"
              @click.stop="$emit('delete', item)"
            />
          </div>
        </div>
      </v-card>
    </div>

    <!-- Pagination -->
    <v-divider v-if="totalItems > itemsPerPage" />
    <div v-if="totalItems > itemsPerPage" class="pa-2 d-flex justify-end align-center">
      <v-pagination
        v-model="currentPage"
        :length="Math.ceil(totalItems / itemsPerPage)"
        :total-visible="5"
        density="comfortable"
        @update:model-value="loadData()"
      />
    </div>
  </v-card>
</template>

<script setup lang="ts">
import { ref, watch, onMounted } from 'vue'
import { useI18n } from 'vue-i18n'
import apiPlatform from '../../../services/apiPlatform'
import { useAssetUrl } from '../../../composables/useAssetUrl'

interface Props {
  title?: string
  resourceName?: string
}

withDefaults(defineProps<Props>(), {
  title: '',
  resourceName: 'Asset',
})

defineEmits<{
  view: [item: any]
  edit: [item: any]
  delete: [item: any]
}>()

const { t } = useI18n()

const items = ref<any[]>([])
const totalItems = ref(0)
const loading = ref(false)
const currentPage = ref(1)
const itemsPerPage = ref(24)

const isDragging = ref(false)
let dragCounter = 0
const fileInput = ref<HTMLInputElement | null>(null)

interface Upload {
  id: number
  filename: string
  progress: number
  status: 'uploading' | 'done' | 'duplicate' | 'error'
  error?: string
}
const uploads = ref<Upload[]>([])
let nextUploadId = 1

const { urls: previewUrls, loading: previewLoading, load: loadPreview, invalidate: invalidatePreview } = useAssetUrl()

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

async function loadData() {
  loading.value = true
  try {
    const result = await apiPlatform.getList('/api/assets', {
      page: currentPage.value,
      itemsPerPage: itemsPerPage.value,
      'order[createdAt]': 'desc',
    })
    items.value = result.data
    totalItems.value = result.total ?? result.data.length
    // Trigger thumbnail loads for images
    for (const item of items.value) {
      if (item.type === 'image') loadPreview(item.id)
    }
  } finally {
    loading.value = false
  }
}

function refresh() {
  loadData()
}

defineExpose({ refresh })

// Drag & drop handlers
function onDragEnter() {
  dragCounter++
  isDragging.value = true
}
function onDragLeave() {
  dragCounter--
  if (dragCounter <= 0) {
    dragCounter = 0
    isDragging.value = false
  }
}
function onDrop(e: DragEvent) {
  dragCounter = 0
  isDragging.value = false
  const files = Array.from(e.dataTransfer?.files ?? [])
  if (files.length) uploadFiles(files)
}
function triggerFileInput() {
  fileInput.value?.click()
}
function onFilePick(e: Event) {
  const input = e.target as HTMLInputElement
  const files = Array.from(input.files ?? [])
  if (files.length) uploadFiles(files)
  input.value = ''
}

async function uploadFiles(files: File[]) {
  // Upload all files in parallel — each gets its own progress entry.
  await Promise.all(files.map((file) => uploadOne(file)))
  // After the batch, refresh the list once so new items appear at the top.
  loadData()
}

async function uploadOne(file: File): Promise<void> {
  const id = nextUploadId++
  const entry: Upload = {
    id,
    filename: file.name,
    progress: 0,
    status: 'uploading',
  }
  uploads.value.push(entry)

  try {
    const form = new FormData()
    form.append('files[]', file)
    const response = await apiPlatform.client.post('/api/assets/upload', form, {
      headers: { 'Content-Type': 'multipart/form-data' },
      onUploadProgress: (e) => {
        if (e.total) entry.progress = Math.round((e.loaded / e.total) * 100)
      },
    })
    const result = response.data?.results?.[0]
    if (result?.success) {
      entry.status = result.duplicate ? 'duplicate' : 'done'
      entry.progress = 100
      // Auto-dismiss successes after a moment; duplicates stay a bit longer.
      setTimeout(() => dismissUpload(id), result.duplicate ? 5000 : 2500)
    } else {
      entry.status = 'error'
      entry.error = result?.error ?? 'Upload failed'
    }
  } catch (e: any) {
    entry.status = 'error'
    entry.error = e.response?.data?.error || e.message || 'Upload failed'
  }
}

function dismissUpload(id: number) {
  uploads.value = uploads.value.filter((u) => u.id !== id)
}

// Refresh after delete from parent — items list will be reloaded if needed
watch(
  () => items.value.length,
  () => {},
)

onMounted(() => {
  loadData()
})
</script>

<style scoped>
.upload-zone {
  border: 2px dashed rgba(var(--v-theme-on-surface), 0.25);
  border-radius: 8px;
  cursor: pointer;
  transition: border-color 0.15s, background-color 0.15s;
  background: rgba(var(--v-theme-surface-variant), 0.3);
}
.upload-zone:hover,
.upload-zone--active {
  border-color: rgb(var(--v-theme-primary));
  background: rgba(var(--v-theme-primary), 0.06);
}

.asset-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(180px, 1fr));
  gap: 16px;
}
.asset-tile {
  cursor: pointer;
  transition: transform 0.1s;
}
.asset-tile:hover {
  transform: translateY(-2px);
}
.asset-tile__preview {
  aspect-ratio: 1;
  display: flex;
  align-items: center;
  justify-content: center;
  background: rgba(var(--v-theme-on-surface), 0.04);
  overflow: hidden;
  position: relative;
}
.tile-badge {
  position: absolute;
  top: 6px;
  right: 6px;
}
.asset-tile__preview img {
  width: 100%;
  height: 100%;
  object-fit: cover;
}
</style>
