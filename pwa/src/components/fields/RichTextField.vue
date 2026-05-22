<template>
  <div class="rich-text-field">
    <v-label v-if="label" class="rich-text-field__label">{{ label }}</v-label>

    <AiHalo
      :active="!!generateHandler?.isLoading?.value"
      radius="8px"
      class="editor-halo"
    >
    <div class="editor-container" :class="{ 'has-error': !!errorMessage }">
      <div v-if="editor" class="editor-toolbar">
        <button
          v-for="(action, i) in toolbarActions"
          :key="i"
          type="button"
          class="tb-btn"
          :class="{ 'is-active': action.isActive?.(), 'is-separator': action.type === 'separator' }"
          :disabled="action.disabled?.()"
          :title="action.title"
          @mousedown.prevent
          @click="action.run?.()"
        >
          <v-icon v-if="action.icon" :icon="action.icon" size="18" />
        </button>

        <div v-if="generateHandler" class="tb-spacer" />
        <button
          v-if="generateHandler"
          type="button"
          class="tb-btn tb-btn--generate"
          :disabled="generateHandler.isLoading?.value || generateHandler.disabled?.value"
          :title="generateHandler.label?.value ?? 'Generate description'"
          @mousedown.prevent
          @click="onGenerateClick"
        >
          <v-icon icon="mdi-creation" size="18" />
          <span class="tb-btn__label">{{ generateHandler.label?.value ?? 'Generate' }}</span>
        </button>
      </div>

      <editor-content :editor="editor" class="editor-content" />
    </div>
    </AiHalo>

    <div v-if="errorMessage" class="v-input__details">
      <div class="v-messages">
        <div class="v-messages__message text-error">{{ errorMessage }}</div>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import { ref, watch, onBeforeUnmount, computed, inject, type Ref } from 'vue'
import { useEditor, EditorContent } from '@tiptap/vue-3'
import StarterKit from '@tiptap/starter-kit'
import Highlight from '@tiptap/extension-highlight'
import TaskList from '@tiptap/extension-task-list'
import TaskItem from '@tiptap/extension-task-item'
import AiHalo from '../AiHalo.vue'

export interface RichTextGenerateHandler {
  // Should return the generated HTML so the editor can apply it locally.
  // Returning void/undefined means "no content to apply".
  run: () => Promise<string | undefined | void> | string | undefined | void
  isLoading?: Ref<boolean>
  disabled?: Ref<boolean>
  label?: Ref<string>
}

interface Props {
  modelValue?: string
  label?: string
  errorMessages?: string | string[]
  field?: any
  enableGenerate?: boolean
}

const props = withDefaults(defineProps<Props>(), {
  modelValue: '',
  label: '',
  errorMessages: '',
  enableGenerate: false
})

const injectedGenerate = inject<RichTextGenerateHandler | null>('richTextGenerate', null)
const generateHandler = computed(() => (props.enableGenerate ? injectedGenerate : null))

async function onGenerateClick() {
  const handler = generateHandler.value
  if (!handler || !editor.value) return
  const result = await handler.run()
  if (typeof result === 'string' && result.length > 0) {
    // setContent with `true` emits an update so v-model fires and the form sees the change.
    editor.value.commands.setContent(result, true)
  }
}

const emit = defineEmits<{
  'update:modelValue': [value: string]
  'blur': []
}>()

const errorMessage = ref('')

watch(() => props.errorMessages, (val) => {
  errorMessage.value = Array.isArray(val) ? (val[0] || '') : (val || '')
}, { immediate: true })

const editor = useEditor({
  content: props.modelValue,
  extensions: [
    StarterKit,
    Highlight,
    TaskList,
    TaskItem.configure({ nested: true }),
  ],
  onUpdate: ({ editor }) => emit('update:modelValue', editor.getHTML()),
  onBlur: () => emit('blur'),
  editorProps: {
    attributes: { class: 'tiptap-content focus:outline-none' },
  },
})

watch(() => props.modelValue, (newValue) => {
  if (editor.value && newValue !== editor.value.getHTML()) {
    editor.value.commands.setContent(newValue, false)
  }
})

onBeforeUnmount(() => editor.value?.destroy())

type ToolbarAction = {
  type?: 'separator'
  icon?: string
  title?: string
  isActive?: () => boolean
  disabled?: () => boolean
  run?: () => void
}

const toolbarActions = computed<ToolbarAction[]>(() => {
  const e = editor.value
  if (!e) return []
  return [
    { icon: 'mdi-format-bold', title: 'Bold', isActive: () => e.isActive('bold'), run: () => e.chain().focus().toggleBold().run() },
    { icon: 'mdi-format-italic', title: 'Italic', isActive: () => e.isActive('italic'), run: () => e.chain().focus().toggleItalic().run() },
    { icon: 'mdi-format-strikethrough', title: 'Strikethrough', isActive: () => e.isActive('strike'), run: () => e.chain().focus().toggleStrike().run() },
    { icon: 'mdi-code-tags', title: 'Inline code', isActive: () => e.isActive('code'), run: () => e.chain().focus().toggleCode().run() },
    { icon: 'mdi-marker', title: 'Highlight', isActive: () => e.isActive('highlight'), run: () => e.chain().focus().toggleHighlight().run() },
    { type: 'separator' },
    { icon: 'mdi-format-header-1', title: 'Heading 1', isActive: () => e.isActive('heading', { level: 1 }), run: () => e.chain().focus().toggleHeading({ level: 1 }).run() },
    { icon: 'mdi-format-header-2', title: 'Heading 2', isActive: () => e.isActive('heading', { level: 2 }), run: () => e.chain().focus().toggleHeading({ level: 2 }).run() },
    { icon: 'mdi-format-paragraph', title: 'Paragraph', isActive: () => e.isActive('paragraph'), run: () => e.chain().focus().setParagraph().run() },
    { icon: 'mdi-format-list-bulleted', title: 'Bullet list', isActive: () => e.isActive('bulletList'), run: () => e.chain().focus().toggleBulletList().run() },
    { icon: 'mdi-format-list-numbered', title: 'Ordered list', isActive: () => e.isActive('orderedList'), run: () => e.chain().focus().toggleOrderedList().run() },
    { icon: 'mdi-format-list-checks', title: 'Task list', isActive: () => e.isActive('taskList'), run: () => e.chain().focus().toggleTaskList().run() },
    { icon: 'mdi-code-braces', title: 'Code block', isActive: () => e.isActive('codeBlock'), run: () => e.chain().focus().toggleCodeBlock().run() },
    { type: 'separator' },
    { icon: 'mdi-format-quote-close', title: 'Quote', isActive: () => e.isActive('blockquote'), run: () => e.chain().focus().toggleBlockquote().run() },
    { icon: 'mdi-minus', title: 'Horizontal rule', run: () => e.chain().focus().setHorizontalRule().run() },
    { type: 'separator' },
    { icon: 'mdi-keyboard-return', title: 'Hard break', run: () => e.chain().focus().setHardBreak().run() },
    { icon: 'mdi-format-clear', title: 'Clear formatting', run: () => e.chain().focus().clearNodes().unsetAllMarks().run() },
    { type: 'separator' },
    { icon: 'mdi-undo', title: 'Undo', disabled: () => !e.can().chain().focus().undo().run(), run: () => e.chain().focus().undo().run() },
    { icon: 'mdi-redo', title: 'Redo', disabled: () => !e.can().chain().focus().redo().run(), run: () => e.chain().focus().redo().run() },
  ]
})
</script>

<style scoped>
.rich-text-field {
  margin-bottom: 22px;
}

.rich-text-field__label {
  display: block;
  margin-bottom: 8px;
  font-size: 14px;
  opacity: 0.87;
}

.editor-container {
  border: 1px solid rgba(var(--v-border-color), var(--v-border-opacity, 0.38));
  border-radius: 8px;
  background-color: rgb(var(--v-theme-surface));
  transition: border-color 0.15s;
}

.editor-container:focus-within {
  border-color: rgb(var(--v-theme-primary));
}

.editor-container.has-error {
  border-color: rgb(var(--v-theme-error));
}

.editor-toolbar {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 2px;
  padding: 8px 10px;
  border-bottom: 1px solid rgba(var(--v-border-color), var(--v-border-opacity, 0.22));
}

.tb-btn {
  appearance: none;
  background: transparent;
  border: 0;
  border-radius: 6px;
  color: rgb(var(--v-theme-on-surface));
  cursor: pointer;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  height: 32px;
  width: 32px;
  padding: 0;
  transition: background-color 0.12s ease, color 0.12s ease;
}

.tb-btn:hover:not(.is-active):not(:disabled) {
  background-color: rgba(var(--v-theme-on-surface), 0.06);
}

.tb-btn:disabled {
  opacity: 0.35;
  cursor: not-allowed;
}

.tb-btn.is-active {
  background-color: rgb(var(--v-theme-primary));
  color: rgb(var(--v-theme-on-primary));
}

.tb-btn.is-separator {
  width: 1px;
  height: 22px;
  margin: 0 6px;
  background-color: rgba(var(--v-border-color), 0.18);
  border-radius: 0;
  pointer-events: none;
}

.tb-spacer {
  flex: 1 1 auto;
  min-width: 8px;
}

.tb-btn--generate {
  width: auto;
  padding: 0 10px;
  gap: 6px;
  color: rgb(var(--v-theme-primary));
  font-size: 0.75rem;
  font-weight: 500;
  letter-spacing: 0.02em;
}

.tb-btn--generate:hover:not(:disabled) {
  background-color: rgba(var(--v-theme-primary), 0.08);
  color: rgb(var(--v-theme-primary));
}

.tb-btn__label {
  white-space: nowrap;
}

.editor-content {
  padding: 16px 18px;
  min-height: 180px;
  max-height: 400px;
  overflow-y: auto;
}

:deep(.tiptap-content) {
  outline: none;
  min-height: 140px;
}

:deep(.tiptap-content p) {
  margin: 0 0 8px;
}

:deep(.tiptap-content h1) {
  font-size: 1.6rem;
  font-weight: 600;
  margin: 8px 0;
}

:deep(.tiptap-content h2) {
  font-size: 1.3rem;
  font-weight: 600;
  margin: 8px 0;
}

:deep(.tiptap-content ul),
:deep(.tiptap-content ol) {
  padding-left: 1.5rem;
  margin: 0 0 8px;
}

:deep(.tiptap-content blockquote) {
  border-left: 3px solid rgba(var(--v-border-color), 0.25);
  margin: 8px 0;
  padding: 4px 12px;
  color: rgba(var(--v-theme-on-surface), 0.75);
}

:deep(.tiptap-content code) {
  background: rgba(var(--v-theme-on-surface), 0.08);
  border-radius: 4px;
  padding: 2px 6px;
  font-size: 0.9em;
}

:deep(.tiptap-content pre) {
  background: rgba(var(--v-theme-on-surface), 0.06);
  border-radius: 6px;
  padding: 12px 14px;
  margin: 8px 0;
  font-size: 0.9em;
  overflow-x: auto;
}

:deep(.tiptap-content pre code) {
  background: transparent;
  padding: 0;
}

:deep(.tiptap-content mark) {
  background: #fef3c7;
  padding: 0 2px;
  border-radius: 2px;
}

:deep(.tiptap-content hr) {
  border: 0;
  border-top: 1px solid rgba(var(--v-border-color), 0.2);
  margin: 12px 0;
}

:deep(.tiptap-content ul[data-type="taskList"]) {
  list-style: none;
  padding-left: 0;
}

:deep(.tiptap-content ul[data-type="taskList"] li) {
  display: flex;
  gap: 8px;
  align-items: flex-start;
}

:deep(.tiptap-content ul[data-type="taskList"] li > label) {
  margin-top: 2px;
}

:deep(.ProseMirror p.is-editor-empty:first-child::before) {
  color: rgba(var(--v-theme-on-surface), 0.4);
  content: attr(data-placeholder);
  float: left;
  height: 0;
  pointer-events: none;
}

.text-error {
  color: rgb(var(--v-theme-error));
  font-size: 12px;
  padding-top: 6px;
  padding-left: 16px;
}
</style>
