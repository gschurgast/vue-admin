<template>
  <div class="rich-text-field">
    <v-label v-if="label" class="mb-2">{{ label }}</v-label>
    
    <div class="editor-container" :class="{ 'has-error': !!errorMessage }">
      <div v-if="editor" class="editor-toolbar">
        <v-btn-group class="mr-2">
          <v-btn
            size="x-small"
            :color="editor.isActive('bold') ? 'primary' : undefined"
            @click="editor.chain().focus().toggleBold().run()"
            icon="mdi-format-bold"
          />
          <v-btn
            size="x-small"
            :color="editor.isActive('italic') ? 'primary' : undefined"
            @click="editor.chain().focus().toggleItalic().run()"
            icon="mdi-format-italic"
          />
          <v-btn
            size="x-small"
            :color="editor.isActive('strike') ? 'primary' : undefined"
            @click="editor.chain().focus().toggleStrike().run()"
            icon="mdi-format-strikethrough"
          />
        </v-btn-group>

        <v-btn-group class="mr-2">
          <v-btn
            size="x-small"
            :color="editor.isActive('heading', { level: 1 }) ? 'primary' : undefined"
            @click="editor.chain().focus().toggleHeading({ level: 1 }).run()"
            icon="mdi-format-header-1"
          />
          <v-btn
            size="x-small"
            :color="editor.isActive('heading', { level: 2 }) ? 'primary' : undefined"
            @click="editor.chain().focus().toggleHeading({ level: 2 }).run()"
            icon="mdi-format-header-2"
          />
          <v-btn
            size="x-small"
            :color="editor.isActive('heading', { level: 3 }) ? 'primary' : undefined"
            @click="editor.chain().focus().toggleHeading({ level: 3 }).run()"
            icon="mdi-format-header-3"
          />
        </v-btn-group>

        <v-btn-group>
          <v-btn
            size="x-small"
            :color="editor.isActive('bulletList') ? 'primary' : undefined"
            @click="editor.chain().focus().toggleBulletList().run()"
            icon="mdi-format-list-bulleted"
          />
          <v-btn
            size="x-small"
            :color="editor.isActive('orderedList') ? 'primary' : undefined"
            @click="editor.chain().focus().toggleOrderedList().run()"
            icon="mdi-format-list-numbered"
          />
        </v-btn-group>
      </div>

      <editor-content :editor="editor" class="editor-content" />
    </div>

    <div v-if="errorMessage" class="v-input__details">
      <div class="v-messages">
        <div class="v-messages__message text-error">{{ errorMessage }}</div>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import { ref, watch, onBeforeUnmount } from 'vue'
import { useEditor, EditorContent } from '@tiptap/vue-3'
import StarterKit from '@tiptap/starter-kit'

interface Props {
  modelValue?: string
  label?: string
  errorMessages?: string | string[]
  field?: any
}

const props = withDefaults(defineProps<Props>(), {
  modelValue: '',
  label: '',
  errorMessages: ''
})

const emit = defineEmits<{
  'update:modelValue': [value: string]
}>()

const errorMessage = ref('')

watch(() => props.errorMessages, (val) => {
  if (Array.isArray(val)) {
    errorMessage.value = val[0] || ''
  } else {
    errorMessage.value = val || ''
  }
}, { immediate: true })

const editor = useEditor({
  content: props.modelValue,
  extensions: [
    StarterKit,
  ],
  onUpdate: ({ editor }) => {
    emit('update:modelValue', editor.getHTML())
  },
  editorProps: {
    attributes: {
      class: 'prose prose-sm sm:prose lg:prose-lg xl:prose-2xl mx-auto focus:outline-none',
    },
  },
})

// Watch for external changes
watch(() => props.modelValue, (newValue) => {
  if (editor.value && newValue !== editor.value.getHTML()) {
    editor.value.commands.setContent(newValue, false)
  }
})

onBeforeUnmount(() => {
  editor.value?.destroy()
})
</script>

<style scoped>
.rich-text-field {
  margin-bottom: 22px;
}

.editor-container {
  border: 1px solid rgba(0, 0, 0, 0.38);
  border-radius: 4px;
  overflow: hidden;
  transition: border-color 0.15s;
}

.editor-container:focus-within {
  border-color: rgb(var(--v-theme-primary));
  border-width: 2px;
  margin: -1px; /* Compensate for border width */
}

.editor-container.has-error {
  border-color: rgb(var(--v-theme-error));
}

.editor-toolbar {
  border-bottom: 1px solid rgba(0, 0, 0, 0.12);
  padding: 4px;
  background-color: #f5f5f5;
  display: flex;
  flex-wrap: wrap;
  gap: 4px;
}

.editor-content {
  padding: 16px;
  min-height: 150px;
  max-height: 400px;
  overflow-y: auto;
}

/* TipTap default styles */
:deep(.ProseMirror) {
  outline: none;
}

:deep(.ProseMirror p.is-editor-empty:first-child::before) {
  color: #adb5bd;
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
