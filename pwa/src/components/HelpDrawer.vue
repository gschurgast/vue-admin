<template>
  <v-navigation-drawer
      v-model="isOpen"
      location="right"
      temporary
      width="400"
  >
    <v-toolbar title="Help & Documentation" density="compact">
      <template v-slot:prepend>
        <v-btn 
          v-if="props.resourceName" 
          icon 
          size="small"
          @click="toggleDocType"
        >
          <v-icon>{{ showingMainDoc ? 'mdi-file-document' : 'mdi-home' }}</v-icon>
          <v-tooltip activator="parent" location="bottom">
            {{ showingMainDoc ? 'Show Resource Help' : 'Show Main Help' }}
          </v-tooltip>
        </v-btn>
      </template>
      <template v-slot:append>
        <v-btn icon="mdi-close" @click="close"></v-btn>
      </template>
    </v-toolbar>

    <v-card-text>
      <div v-if="loading" class="text-center pa-4">
        <v-progress-circular indeterminate color="primary"></v-progress-circular>
      </div>
      <div v-else-if="error" class="text-error">
        {{ error }}
      </div>
      <div v-else>
        <!-- Alert when no resource-specific documentation exists -->
        <v-alert
          v-if="noResourceDoc && props.resourceName"
          type="info"
          variant="tonal"
          density="compact"
          class="mb-4"
        >
          {{ t('help.noResourceDoc', { resource: props.resourceName }) }}
        </v-alert>

        <!-- Render H1 title if exists -->
        <div v-if="parsedSections.title" class="text-h5 mb-4">
          {{ parsedSections.title }}
        </div>
        
        <!-- Render intro content before first H2 -->
        <div v-if="parsedSections.intro" v-html="parsedSections.intro" class="markdown-content mb-4"></div>
        
        <!-- Render sections as accordion -->
        <v-expansion-panels v-if="parsedSections.sections.length > 0" variant="accordion">
          <v-expansion-panel
            v-for="(section, index) in parsedSections.sections"
            :key="index"
            :title="section.title"
          >
            <v-expansion-panel-text>
              <div v-html="section.content" class="markdown-content"></div>
            </v-expansion-panel-text>
          </v-expansion-panel>
        </v-expansion-panels>
      </div>
    </v-card-text>
  </v-navigation-drawer>
</template>

<script setup lang="ts">
import { ref, computed, watch } from 'vue'
import { useI18n } from 'vue-i18n'

interface Props {
  modelValue: boolean
  resourceName?: string
}

interface Section {
  title: string
  content: string
}

interface ParsedContent {
  title: string
  intro: string
  sections: Section[]
}

const props = withDefaults(defineProps<Props>(), {
  resourceName: undefined
})

const emit = defineEmits<{
  'update:modelValue': [value: boolean]
}>()

// Get i18n at component level (must be called at top of setup)
const { locale: currentLocale, t } = useI18n()

const loading = ref(false)
const error = ref<string | null>(null)
const markdownContent = ref('')
const showingMainDoc = ref(false)
const noResourceDoc = ref(false)

const isOpen = computed({
  get: () => props.modelValue,
  set: (value) => emit('update:modelValue', value)
})

function close() {
  isOpen.value = false
}

function toggleDocType() {
  showingMainDoc.value = !showingMainDoc.value
  loadDocumentation()
}

// Simple markdown to HTML converter for content within sections
function parseMarkdownContent(markdown: string): string {
  let html = markdown
  
  // H3 headers
  html = html.replace(/^### (.*$)/gim, '<h3 class="text-subtitle-1 font-weight-bold mt-3 mb-2">$1</h3>')
  
  // Bold
  html = html.replace(/\*\*(.*?)\*\*/g, '<strong>$1</strong>')
  
  // Lists - handle multi-line lists properly
  const lines = html.split('\n')
  let inList = false
  const processedLines: string[] = []
  
  for (let i = 0; i < lines.length; i++) {
    const line = lines[i]
    if (line.match(/^- /)) {
      if (!inList) {
        processedLines.push('<ul class="mb-3">')
        inList = true
      }
      processedLines.push('<li>' + line.substring(2) + '</li>')
    } else {
      if (inList) {
        processedLines.push('</ul>')
        inList = false
      }
      if (line.trim() !== '' && !line.startsWith('<')) {
        processedLines.push('<p class="mb-3">' + line + '</p>')
      } else {
        processedLines.push(line)
      }
    }
  }
  
  if (inList) {
    processedLines.push('</ul>')
  }
  
  return processedLines.join('\n')
}

// Parse markdown into sections based on H2 headers
const parsedSections = computed((): ParsedContent => {
  const content = markdownContent.value
  const result: ParsedContent = {
    title: '',
    intro: '',
    sections: []
  }
  
  if (!content) return result
  
  // Extract H1 title
  const h1Match = content.match(/^# (.+)$/m)
  if (h1Match) {
    result.title = h1Match[1]
  }
  
  // Split by H2 headers
  const h2Regex = /^## (.+)$/gm
  const sections = content.split(h2Regex)
  
  // First section is everything before the first H2 (intro)
  if (sections.length > 0) {
    let intro = sections[0]
    // Remove H1 from intro if it exists
    intro = intro.replace(/^# .+$/m, '').trim()
    if (intro) {
      result.intro = parseMarkdownContent(intro)
    }
  }
  
  // Process H2 sections (they come in pairs: title, content, title, content, ...)
  for (let i = 1; i < sections.length; i += 2) {
    if (i + 1 < sections.length) {
      result.sections.push({
        title: sections[i].trim(),
        content: parseMarkdownContent(sections[i + 1].trim())
      })
    }
  }
  
  return result
})

async function loadDocumentation() {
  loading.value = true
  error.value = null
  noResourceDoc.value = false

  try {
    // Use locale from component setup
    const locale = currentLocale.value || 'en'

    // Use Vite's import.meta.glob to load markdown files
    const docs = import.meta.glob('../documentation/**/*.md', { as: 'raw', eager: false })

    let content = ''
    let foundResourceDoc = false

    // If showing main doc is toggled, skip resource-specific docs
    if (!showingMainDoc.value && props.resourceName) {
      // Try: /documentation/[locale]/[Resource].md
      const localizedResourcePath = `../documentation/${locale}/${props.resourceName}.md`
      // Fallback: /documentation/[Resource].md
      const resourcePath = `../documentation/${props.resourceName}.md`

      if (docs[localizedResourcePath]) {
        content = await docs[localizedResourcePath]()
        foundResourceDoc = true
      } else if (docs[resourcePath]) {
        content = await docs[resourcePath]()
        foundResourceDoc = true
      }
    }

    // If no resource-specific doc found, try localized main.md
    if (!content) {
      // Track that we're on a resource page but no specific doc exists
      if (props.resourceName && !showingMainDoc.value) {
        noResourceDoc.value = true
      }

      const localizedMainPath = `../documentation/${locale}/main.md`
      const fallbackPath = '../documentation/main.md'

      if (docs[localizedMainPath]) {
        content = await docs[localizedMainPath]()
      } else if (docs[fallbackPath]) {
        content = await docs[fallbackPath]()
      } else {
        throw new Error('No documentation found')
      }
    }

    markdownContent.value = content
  } catch (e) {
    error.value = 'Failed to load documentation'
    console.error('Documentation load error:', e)
  } finally {
    loading.value = false
  }
}

// Load documentation when drawer opens or resource changes
watch([() => props.modelValue, () => props.resourceName], ([isOpen], [wasOpen, oldResourceName]) => {
  // Reset to resource-specific doc when resource changes
  if (props.resourceName !== oldResourceName) {
    showingMainDoc.value = false
  }

  if (isOpen) {
    loadDocumentation()
  }
}, { immediate: true })

// Reload documentation when locale changes while drawer is open
watch(currentLocale, () => {
  if (props.modelValue) {
    loadDocumentation()
  }
})
</script>

<style scoped>
.markdown-content :deep(h3) {
  font-weight: 600;
}

.markdown-content :deep(ul) {
  padding-left: 1.5rem;
}

.markdown-content :deep(li) {
  margin-bottom: 0.5rem;
}

.markdown-content :deep(p) {
  line-height: 1.6;
}

.markdown-content :deep(strong) {
  font-weight: 600;
}
</style>
