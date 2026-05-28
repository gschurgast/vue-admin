import { ref, onUnmounted } from 'vue'
import apiPlatform from '../services/apiPlatform'

/**
 * Phase 5 / Plan 05-05 — Preview blob URL ref-counted.
 *
 * POST /api/asset_transformations/preview with { assetId, ext, steps } → blob.
 * Pattern cloned from useAssetUrl, but cache is keyed by a stable hash of the
 * payload (since the same asset can be previewed with N different step lists).
 *
 * Error surface:
 *   - 429 → { status: 429, retryAfter: <seconds> }
 *   - 404 → { status: 404 } (asset !isPublic per T-05-03)
 *   - other → { status, message }
 *
 * Blob URLs are revoked on unmount once refCount drops to 0.
 */

export interface PreviewStep {
  type: string
  params?: Record<string, unknown>
}

export interface PreviewPayload {
  assetId: number
  ext: string
  steps: PreviewStep[]
}

export interface PreviewError {
  status: number
  retryAfter?: number
  message?: string
}

const cache = new Map<string, string>()
const refCount = new Map<string, number>()

function hashKey(payload: PreviewPayload): string {
  // Deterministic stable key — JSON.stringify with sorted top-level keys is
  // good enough; steps order matters semantically so preserve it as-is.
  return JSON.stringify({ a: payload.assetId, e: payload.ext, s: payload.steps })
}

function release(key: string) {
  const next = (refCount.get(key) ?? 1) - 1
  if (next <= 0) {
    const url = cache.get(key)
    if (url) URL.revokeObjectURL(url)
    cache.delete(key)
    refCount.delete(key)
  } else {
    refCount.set(key, next)
  }
}

export function usePreviewUrl() {
  const ownedKeys = new Set<string>()
  const url = ref<string | null>(null)
  const error = ref<PreviewError | null>(null)
  const isLoading = ref(false)

  async function refresh(payload: PreviewPayload): Promise<void> {
    if (!payload.assetId || !payload.ext || !Array.isArray(payload.steps) || payload.steps.length === 0) {
      error.value = { status: 0, message: 'invalid payload' }
      return
    }
    const key = hashKey(payload)
    error.value = null

    if (cache.has(key)) {
      url.value = cache.get(key)!
      if (!ownedKeys.has(key)) {
        refCount.set(key, (refCount.get(key) ?? 0) + 1)
        ownedKeys.add(key)
      }
      return
    }

    isLoading.value = true
    try {
      const response = await apiPlatform.client.post(
        '/api/asset_transformations/preview',
        { assetId: payload.assetId, ext: payload.ext, steps: payload.steps },
        { responseType: 'blob' },
      )
      const blobUrl = URL.createObjectURL(response.data as Blob)
      cache.set(key, blobUrl)
      refCount.set(key, (refCount.get(key) ?? 0) + 1)
      ownedKeys.add(key)
      url.value = blobUrl
    } catch (e: unknown) {
      const status = (e as { response?: { status?: number; headers?: Record<string, string> } }).response?.status ?? 0
      if (status === 429) {
        const retryAfterHeader = (e as { response?: { headers?: Record<string, string> } }).response?.headers?.['retry-after']
        const retryAfter = retryAfterHeader ? parseInt(retryAfterHeader, 10) : undefined
        error.value = { status: 429, retryAfter }
      } else if (status === 404) {
        error.value = { status: 404 }
      } else {
        error.value = { status, message: (e as Error).message }
      }
      url.value = null
    } finally {
      isLoading.value = false
    }
  }

  function clear() {
    url.value = null
    error.value = null
  }

  onUnmounted(() => {
    ownedKeys.forEach(release)
    ownedKeys.clear()
  })

  return { url, error, isLoading, refresh, clear }
}