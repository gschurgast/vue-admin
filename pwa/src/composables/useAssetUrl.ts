import { ref, onUnmounted } from 'vue'
import apiPlatform from '../services/apiPlatform'

/**
 * Fetches an asset's binary via the authenticated streaming endpoint
 * and exposes it as a blob URL suitable for <img>, <video>, <iframe>, etc.
 *
 * A small module-level cache avoids refetching the same asset across
 * components. Blob URLs created by a given component are revoked when
 * the component unmounts.
 */
const cache = new Map<number, string>()
const refCount = new Map<number, number>()

function release(id: number) {
  const next = (refCount.get(id) ?? 1) - 1
  if (next <= 0) {
    const url = cache.get(id)
    if (url) URL.revokeObjectURL(url)
    cache.delete(id)
    refCount.delete(id)
  } else {
    refCount.set(id, next)
  }
}

export function useAssetUrl() {
  const ownedIds = new Set<number>()

  const urls = ref<Record<number, string>>({})
  const loading = ref<Record<number, boolean>>({})

  async function load(id: number): Promise<string | null> {
    if (cache.has(id)) {
      urls.value[id] = cache.get(id)!
      refCount.set(id, (refCount.get(id) ?? 0) + 1)
      ownedIds.add(id)
      return urls.value[id]
    }
    loading.value[id] = true
    try {
      const response = await apiPlatform.client.get(`/api/assets/${id}/content`, {
        responseType: 'blob',
      })
      const url = URL.createObjectURL(response.data as Blob)
      cache.set(id, url)
      refCount.set(id, (refCount.get(id) ?? 0) + 1)
      ownedIds.add(id)
      urls.value[id] = url
      return url
    } catch (e) {
      return null
    } finally {
      loading.value[id] = false
    }
  }

  onUnmounted(() => {
    ownedIds.forEach(release)
    ownedIds.clear()
  })

  function invalidate(id: number) {
    const url = cache.get(id)
    if (url) URL.revokeObjectURL(url)
    cache.delete(id)
    refCount.delete(id)
    delete urls.value[id]
  }

  return { urls, loading, load, invalidate }
}
