<template>
  <v-card class="resource-list-card">
    <div class="list-header d-flex align-center pa-4">
      <h2 v-if="title" class="text-h6 font-weight-semibold">{{ title }}</h2>
      <v-chip v-if="!effectiveLoading" size="small" variant="tonal" class="ml-3">
        {{ allNodes.length }}
      </v-chip>
      <v-spacer />
      <span class="text-caption text-medium-emphasis mr-3 d-none d-md-inline">
        {{ t('taxonomy.dragHint') }}
      </span>
      <v-btn
        :prepend-icon="allExpanded ? 'mdi-collapse-all-outline' : 'mdi-expand-all-outline'"
        variant="tonal"
        size="small"
        class="mr-2"
        @click="toggleExpandAll"
      >
        {{ allExpanded ? t('taxonomy.collapseAll') : t('taxonomy.expandAll') }}
      </v-btn>
      <slot name="actions" />
    </div>
    <v-divider />

    <v-alert
      v-if="errorMessage"
      type="error"
      density="compact"
      variant="tonal"
      closable
      class="ma-2"
      @click:close="errorMessage = ''"
    >
      {{ errorMessage }}
    </v-alert>

    <div v-if="effectiveLoading" class="pa-8 text-center">
      <v-progress-circular indeterminate color="primary" />
    </div>

    <div v-else-if="!treeRoots.length" class="pa-8 text-center text-medium-emphasis">
      {{ t('taxonomy.empty') }}
    </div>

    <div v-else class="taxonomy-tree pa-2">
      <ul class="tree-list">
        <TaxonomyTreeNode
          v-for="node in treeRoots"
          :key="node.id"
          :node="node"
          :opened="opened"
          :drop-target="dropTarget"
          :dragging-id="draggingId"
          @toggle="toggleOpen"
          @view="handleView"
          @edit="handleEdit"
          @delete="handleDelete"
          @add-child="addChild"
          @dragstart-node="onDragStart"
          @dragover-node="onDragOver"
          @dragleave-node="onDragLeave"
          @drop-node="onDrop"
          @dragend-node="onDragEnd"
        />
      </ul>
      <div
        class="tree-root-dropzone"
        :class="{ active: dropTarget?.id === ROOT_DROP_ID }"
        @dragover.prevent="onRootDragOver"
        @dragleave="onRootDragLeave"
        @drop.prevent="onRootDrop"
      >
        {{ t('taxonomy.dropToRoot') }}
      </div>
    </div>
  </v-card>
</template>

<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import apiPlatform from '../../../services/apiPlatform'
import TaxonomyTreeNode from './TaxonomyTreeNode.vue'
import type { TaxonomyItem, TreeNode, DropTarget, DropPosition } from './taxonomyTreeTypes'

const ROOT_DROP_ID = -1 as const

const props = defineProps<{
  items: TaxonomyItem[]
  loading?: boolean
  title?: string
  resourceName?: string
}>()

const emit = defineEmits<{
  (e: 'view', item: TaxonomyItem): void
  (e: 'edit', item: TaxonomyItem): void
  (e: 'delete', item: TaxonomyItem): void
}>()

const { t, locale } = useI18n()

const opened = ref<Set<number>>(new Set())
const fetchedItems = ref<TaxonomyItem[] | null>(null)
const fetching = ref(false)
const errorMessage = ref('')

const draggingId = ref<number | null>(null)
const dropTarget = ref<DropTarget | null>(null)

const taxonomyPath = computed(() => apiPlatform.getResourcePath(props.resourceName || 'Taxonomy'))

async function fetchAll() {
  fetching.value = true
  try {
    const all: TaxonomyItem[] = []
    let page = 1
    const itemsPerPage = 100
    while (true) {
      const response = await apiPlatform.client.get(taxonomyPath.value, {
        params: { page, itemsPerPage },
        headers: { Accept: 'application/ld+json' },
      })
      const data = response.data
      const members = data['hydra:member'] || data.member || []
      all.push(...members)
      if (members.length < itemsPerPage) break
      page++
      if (page > 100) break
    }
    fetchedItems.value = all
  } catch (e) {
    console.error('Failed to fetch taxonomies for tree', e)
    fetchedItems.value = null
  } finally {
    fetching.value = false
  }
}

onMounted(fetchAll)
watch(() => props.items, () => { fetchAll() })

const effectiveItems = computed<TaxonomyItem[]>(() => fetchedItems.value ?? props.items)
const effectiveLoading = computed(() => fetching.value || props.loading)

function parentIdOf(item: TaxonomyItem): number | null {
  const p: any = item.parent
  if (!p) return null
  if (typeof p === 'object') {
    if (typeof p.id === 'number') return p.id
    if (typeof p['@id'] === 'string') {
      const m = p['@id'].match(/\/(\d+)$/)
      return m ? Number(m[1]) : null
    }
  }
  if (typeof p === 'string') {
    const m = p.match(/\/(\d+)$/)
    return m ? Number(m[1]) : null
  }
  return null
}

function pickLabel(item: TaxonomyItem): string | undefined {
  if (!item.translations?.length) return undefined
  const current = locale.value.replace('-', '_')
  const exact = item.translations.find((tr: any) => tr.locale === current || tr.locale === locale.value)
  return (exact || item.translations[0])?.label
}

const treeRoots = computed<TreeNode[]>(() => {
  const source = effectiveItems.value
  const byId = new Map<number, TreeNode>()
  for (const item of source) {
    byId.set(item.id, {
      id: item.id,
      code: item.code,
      label: pickLabel(item),
      position: typeof item.position === 'number' ? item.position : 0,
      parentId: parentIdOf(item),
      raw: item,
      children: [],
    })
  }
  const roots: TreeNode[] = []
  for (const node of byId.values()) {
    if (node.parentId !== null && byId.has(node.parentId)) {
      byId.get(node.parentId)!.children.push(node)
    } else {
      roots.push(node)
    }
  }
  const sortRec = (nodes: TreeNode[]) => {
    nodes.sort((a, b) => a.position - b.position || a.code.localeCompare(b.code))
    nodes.forEach(n => sortRec(n.children))
  }
  sortRec(roots)
  return roots
})

const allNodes = computed<TreeNode[]>(() => {
  const out: TreeNode[] = []
  const walk = (n: TreeNode[]) => n.forEach(x => { out.push(x); walk(x.children) })
  walk(treeRoots.value)
  return out
})

const nodesById = computed(() => {
  const m = new Map<number, TreeNode>()
  for (const n of allNodes.value) m.set(n.id, n)
  return m
})

const allExpanded = computed(() => {
  const expandable = allNodes.value.filter(n => n.children.length).map(n => n.id)
  return expandable.length > 0 && expandable.every(id => opened.value.has(id))
})

function toggleExpandAll() {
  if (allExpanded.value) {
    opened.value = new Set()
  } else {
    opened.value = new Set(allNodes.value.filter(n => n.children.length).map(n => n.id))
  }
}

function toggleOpen(id: number) {
  const next = new Set(opened.value)
  if (next.has(id)) next.delete(id)
  else next.add(id)
  opened.value = next
}

watch(treeRoots, (roots) => {
  if (opened.value.size === 0 && roots.length <= 20) {
    opened.value = new Set(roots.map(n => n.id))
  }
}, { immediate: false })

function handleView(node: TreeNode) { emit('view', node.raw) }
function handleEdit(node: TreeNode) { emit('edit', node.raw) }
function handleDelete(node: TreeNode) { emit('delete', node.raw) }
function addChild(node: TreeNode) {
  const url = new URL(window.location.href)
  url.pathname = `/edit/${props.resourceName || 'Taxonomy'}/new`
  url.searchParams.set('parent', node.raw['@id'] || `${taxonomyPath.value}/${node.id}`)
  window.location.assign(url.pathname + url.search)
}

// ---------- Drag-and-drop ----------

function isDescendant(ancestorId: number, candidateId: number): boolean {
  const a = nodesById.value.get(ancestorId)
  if (!a) return false
  const stack = [...a.children]
  while (stack.length) {
    const n = stack.pop()!
    if (n.id === candidateId) return true
    stack.push(...n.children)
  }
  return false
}

function onDragStart(node: TreeNode, ev: DragEvent) {
  draggingId.value = node.id
  if (ev.dataTransfer) {
    ev.dataTransfer.effectAllowed = 'move'
    ev.dataTransfer.setData('text/plain', String(node.id))
  }
}

function onDragEnd() {
  draggingId.value = null
  dropTarget.value = null
}

function onDragOver(node: TreeNode, position: DropPosition, ev: DragEvent) {
  const sourceId = draggingId.value
  if (sourceId === null) return
  if (sourceId === node.id) { dropTarget.value = null; return }
  if (isDescendant(sourceId, node.id)) { dropTarget.value = null; return }
  ev.preventDefault()
  if (ev.dataTransfer) ev.dataTransfer.dropEffect = 'move'
  dropTarget.value = { id: node.id, position }
}

function onDragLeave(_node: TreeNode, _ev: DragEvent) {
  // Cleared globally on drop / dragend; per-node leave creates flicker.
}

function onRootDragOver(ev: DragEvent) {
  if (draggingId.value === null) return
  ev.preventDefault()
  if (ev.dataTransfer) ev.dataTransfer.dropEffect = 'move'
  dropTarget.value = { id: ROOT_DROP_ID, position: 'inside' }
}

function onRootDragLeave() {
  if (dropTarget.value?.id === ROOT_DROP_ID) dropTarget.value = null
}

async function onRootDrop(ev: DragEvent) {
  ev.preventDefault()
  const sourceId = draggingId.value
  if (sourceId === null) return
  await applyMove(sourceId, null, treeRoots.value.length)
  onDragEnd()
}

async function onDrop(target: TreeNode, position: DropPosition, ev: DragEvent) {
  ev.preventDefault()
  const sourceId = draggingId.value
  if (sourceId === null) return
  if (sourceId === target.id) { onDragEnd(); return }
  if (isDescendant(sourceId, target.id)) {
    errorMessage.value = t('taxonomy.dropError')
    onDragEnd()
    return
  }

  let newParentId: number | null
  let insertIndex: number

  if (position === 'inside') {
    newParentId = target.id
    insertIndex = target.children.length
  } else {
    newParentId = target.parentId
    const siblings = newParentId === null
      ? treeRoots.value
      : (nodesById.value.get(newParentId)?.children ?? [])
    const idx = siblings.findIndex(s => s.id === target.id)
    insertIndex = position === 'before' ? idx : idx + 1
  }

  await applyMove(sourceId, newParentId, insertIndex)
  onDragEnd()
}

async function applyMove(sourceId: number, newParentId: number | null, insertIndex: number) {
  const source = nodesById.value.get(sourceId)
  if (!source) return

  // Build the new sibling list (target parent).
  const targetSiblings = (newParentId === null
    ? [...treeRoots.value]
    : [...(nodesById.value.get(newParentId)?.children ?? [])]
  ).filter(n => n.id !== sourceId)

  const clamped = Math.max(0, Math.min(insertIndex, targetSiblings.length))
  targetSiblings.splice(clamped, 0, source)

  // Build the old sibling list (source's previous parent), for renumbering.
  const oldParentId = source.parentId
  const oldSiblings = oldParentId === newParentId
    ? targetSiblings
    : (oldParentId === null
        ? treeRoots.value.filter(n => n.id !== sourceId)
        : (nodesById.value.get(oldParentId)?.children ?? []).filter(n => n.id !== sourceId))

  const payload: Array<{ id: number; parent: string | null; position: number }> = []

  targetSiblings.forEach((n, idx) => {
    const parentChange = n.id === sourceId ? (newParentId !== oldParentId) : false
    if (n.position !== idx || parentChange) {
      payload.push({
        id: n.id,
        parent: newParentId !== null ? `${taxonomyPath.value}/${newParentId}` : null,
        position: idx,
      })
    }
  })

  if (oldParentId !== newParentId) {
    oldSiblings.forEach((n, idx) => {
      if (n.position !== idx) {
        payload.push({
          id: n.id,
          parent: oldParentId !== null ? `${taxonomyPath.value}/${oldParentId}` : null,
          position: idx,
        })
      }
    })
  }

  if (payload.length === 0) return

  // Optimistic update: patch the in-memory items immediately.
  const snapshot = fetchedItems.value ? fetchedItems.value.map(i => ({ ...i })) : null
  if (fetchedItems.value) {
    const pidIri = newParentId !== null ? `${taxonomyPath.value}/${newParentId}` : null
    const oldPidIri = oldParentId !== null ? `${taxonomyPath.value}/${oldParentId}` : null
    for (const change of payload) {
      const item = fetchedItems.value.find(i => i.id === change.id)
      if (!item) continue
      item.position = change.position
      if (change.id === sourceId) {
        item.parent = pidIri
      } else if (oldParentId !== newParentId) {
        // Items in old siblings list keep their old parent; renumber only.
        const stillInOld = oldSiblings.some(n => n.id === change.id)
        if (stillInOld) item.parent = oldPidIri
        else item.parent = pidIri
      }
    }
  }

  try {
    await apiPlatform.client.post(`${taxonomyPath.value}/reorder`, { items: payload }, {
      headers: { 'Content-Type': 'application/ld+json' },
    })
    // Refresh in background to align IRIs / cascaded relations.
    fetchAll()
  } catch (e: any) {
    console.error('Reorder failed', e)
    errorMessage.value = e?.response?.data?.['hydra:description'] || e?.response?.data?.detail || t('taxonomy.reorderFailed')
    if (snapshot) fetchedItems.value = snapshot
  }
}
</script>

<style scoped>
.resource-list-card {
  overflow: hidden;
}
.taxonomy-tree {
  min-height: 200px;
}
.tree-list {
  list-style: none;
  margin: 0;
  padding: 0;
}
.tree-root-dropzone {
  margin: 12px 8px 4px;
  padding: 12px;
  border: 1px dashed rgba(var(--v-theme-on-surface), 0.18);
  border-radius: 6px;
  text-align: center;
  font-size: 12px;
  color: rgba(var(--v-theme-on-surface), 0.5);
  transition: all 0.15s ease;
}
.tree-root-dropzone.active {
  border-color: rgb(var(--v-theme-primary));
  background: rgba(var(--v-theme-primary), 0.06);
  color: rgb(var(--v-theme-primary));
}
</style>
