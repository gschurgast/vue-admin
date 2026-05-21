<template>
  <li class="tree-node">
    <div
      class="tree-row"
      :class="{
        'is-dragging': draggingId === node.id,
        'drop-inside': isInsideTarget,
      }"
      draggable="true"
      @dragstart.stop="emit('dragstart-node', node, $event)"
      @dragend.stop="emit('dragend-node', node, $event)"
      @dragover.prevent.stop="handleDragOver"
      @dragleave.stop="emit('dragleave-node', node, $event)"
      @drop.stop="handleDrop"
    >
      <div class="drop-zone drop-before" :class="{ active: isBeforeTarget }" />
      <div class="drop-zone drop-after" :class="{ active: isAfterTarget }" />

      <v-btn
        v-if="node.children.length"
        :icon="isOpen ? 'mdi-menu-down' : 'mdi-menu-right'"
        variant="text"
        size="x-small"
        class="toggle-btn"
        @click.stop="emit('toggle', node.id)"
      />
      <span v-else class="toggle-spacer" />

      <v-icon size="small" class="mr-1" :color="node.children.length ? 'primary' : 'grey'">
        {{ node.children.length ? 'mdi-folder-outline' : 'mdi-tag-outline' }}
      </v-icon>

      <span class="font-weight-medium">{{ node.code }}</span>
      <span v-if="node.label" class="text-caption text-medium-emphasis ml-2">
        — {{ node.label }}
      </span>

      <v-spacer />

      <div class="row-actions">
        <v-btn
          :title="t('taxonomy.addChild')"
          icon="mdi-plus"
          variant="text"
          size="x-small"
          @click.stop="emit('add-child', node)"
        />
        <v-btn
          icon="mdi-eye"
          variant="text"
          size="x-small"
          @click.stop="emit('view', node)"
        />
        <v-btn
          icon="mdi-pencil"
          variant="text"
          size="x-small"
          @click.stop="emit('edit', node)"
        />
        <v-btn
          icon="mdi-delete"
          variant="text"
          size="x-small"
          color="error"
          @click.stop="emit('delete', node)"
        />
      </div>
    </div>

    <ul v-if="node.children.length && isOpen" class="tree-children">
      <TaxonomyTreeNode
        v-for="child in node.children"
        :key="child.id"
        :node="child"
        :opened="opened"
        :drop-target="dropTarget"
        :dragging-id="draggingId"
        @toggle="(id) => emit('toggle', id)"
        @view="(n) => emit('view', n)"
        @edit="(n) => emit('edit', n)"
        @delete="(n) => emit('delete', n)"
        @add-child="(n) => emit('add-child', n)"
        @dragstart-node="(n, e) => emit('dragstart-node', n, e)"
        @dragover-node="(n, p, e) => emit('dragover-node', n, p, e)"
        @dragleave-node="(n, e) => emit('dragleave-node', n, e)"
        @drop-node="(n, p, e) => emit('drop-node', n, p, e)"
        @dragend-node="(n, e) => emit('dragend-node', n, e)"
      />
    </ul>
  </li>
</template>

<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import type { TreeNode, DropTarget, DropPosition } from './taxonomyTreeTypes'

const props = defineProps<{
  node: TreeNode
  opened: Set<number>
  dropTarget: DropTarget | null
  draggingId: number | null
}>()

const emit = defineEmits<{
  (e: 'toggle', id: number): void
  (e: 'view', node: TreeNode): void
  (e: 'edit', node: TreeNode): void
  (e: 'delete', node: TreeNode): void
  (e: 'add-child', node: TreeNode): void
  (e: 'dragstart-node', node: TreeNode, ev: DragEvent): void
  (e: 'dragover-node', node: TreeNode, position: DropPosition, ev: DragEvent): void
  (e: 'dragleave-node', node: TreeNode, ev: DragEvent): void
  (e: 'drop-node', node: TreeNode, position: DropPosition, ev: DragEvent): void
  (e: 'dragend-node', node: TreeNode, ev: DragEvent): void
}>()

const { t } = useI18n()

const isOpen = computed(() => props.opened.has(props.node.id))

const isBeforeTarget = computed(() =>
  props.dropTarget?.id === props.node.id && props.dropTarget.position === 'before'
)
const isAfterTarget = computed(() =>
  props.dropTarget?.id === props.node.id && props.dropTarget.position === 'after'
)
const isInsideTarget = computed(() =>
  props.dropTarget?.id === props.node.id && props.dropTarget.position === 'inside'
)

function positionFromEvent(ev: DragEvent): DropPosition {
  const target = ev.currentTarget as HTMLElement
  const rect = target.getBoundingClientRect()
  const y = ev.clientY - rect.top
  const h = rect.height
  if (y < h * 0.25) return 'before'
  if (y > h * 0.75) return 'after'
  return 'inside'
}

function handleDragOver(ev: DragEvent) {
  const pos = positionFromEvent(ev)
  emit('dragover-node', props.node, pos, ev)
}

function handleDrop(ev: DragEvent) {
  const pos = positionFromEvent(ev)
  emit('drop-node', props.node, pos, ev)
}
</script>

<style scoped>
.tree-node {
  list-style: none;
}
.tree-row {
  position: relative;
  display: flex;
  align-items: center;
  gap: 2px;
  padding: 4px 8px;
  border-radius: 4px;
  cursor: grab;
  user-select: none;
  transition: background-color 0.1s ease;
}
.tree-row:hover {
  background-color: rgba(var(--v-theme-on-surface), 0.04);
}
.tree-row:active {
  cursor: grabbing;
}
.tree-row.is-dragging {
  opacity: 0.4;
}
.tree-row.drop-inside {
  background-color: rgba(var(--v-theme-primary), 0.12);
  outline: 1px solid rgb(var(--v-theme-primary));
}
.drop-zone {
  position: absolute;
  left: 24px;
  right: 8px;
  height: 3px;
  pointer-events: none;
  border-radius: 2px;
}
.drop-zone.drop-before { top: -1px; }
.drop-zone.drop-after { bottom: -1px; }
.drop-zone.active {
  background-color: rgb(var(--v-theme-primary));
  box-shadow: 0 0 0 1px rgb(var(--v-theme-primary));
}
.toggle-btn,
.toggle-spacer {
  width: 24px;
  min-width: 24px;
  height: 24px;
}
.tree-children {
  list-style: none;
  margin: 0;
  padding-left: 22px;
  border-left: 1px dashed rgba(var(--v-theme-on-surface), 0.12);
  margin-left: 12px;
}
.row-actions {
  display: flex;
  align-items: center;
  opacity: 0;
  transition: opacity 0.15s ease;
}
.tree-row:hover .row-actions {
  opacity: 1;
}
</style>
