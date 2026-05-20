<template>
  <div class="readonly-value">
    <!-- Boolean -->
    <v-chip
      v-if="type === 'boolean'"
      :color="boolValue ? 'success' : 'grey'"
      :prepend-icon="boolValue ? 'mdi-check-circle' : 'mdi-close-circle'"
      size="small"
      variant="tonal"
    >
      {{ boolValue ? t('boolean.true') : t('boolean.false') }}
    </v-chip>

    <!-- Media (image/file URL) -->
    <div v-else-if="type === 'media'" class="d-flex align-center ga-2 flex-wrap">
      <v-img
        v-if="isImageUrl(rawValue)"
        :src="rawValue"
        max-width="120"
        max-height="80"
        cover
        rounded
        class="elevation-1"
      />
      <a
        :href="rawValue"
        target="_blank"
        rel="noopener"
        class="text-caption text-decoration-none text-primary text-truncate"
        style="max-width: 100%;"
      >
        <v-icon size="x-small">mdi-open-in-new</v-icon>
        {{ truncateMiddle(rawValue, 60) }}
      </a>
    </div>

    <!-- Rich text (HTML) -->
    <div v-else-if="type === 'richtext'">
      <div
        v-if="!showFull"
        class="text-body-2 text-medium-emphasis"
      >
        {{ stripHtml(rawValue, 200) }}
      </div>
      <div
        v-else
        class="rich-content text-body-2"
        v-html="sanitizedHtml"
      />
      <v-btn
        v-if="hasMoreToShow"
        size="x-small"
        variant="text"
        color="primary"
        class="mt-1 px-0"
        @click="showFull = !showFull"
      >
        {{ showFull ? t('common.showLess') : t('common.showFull') }}
      </v-btn>
    </div>

    <!-- Textarea -->
    <div
      v-else-if="type === 'textarea'"
      class="text-body-2 text-pre-line"
    >
      {{ truncateText(rawValue, 300) }}
    </div>

    <!-- JSON / Measure -->
    <div v-else-if="type === 'json' || type === 'measure'">
      <div v-if="type === 'measure' && parsedJson && parsedJson.value !== undefined" class="d-flex align-center ga-1">
        <span class="text-body-2 font-weight-medium">{{ parsedJson.value }}</span>
        <v-chip size="x-small" variant="tonal">{{ parsedJson.unit }}</v-chip>
      </div>
      <pre v-else-if="parsedJson" class="json-block">{{ JSON.stringify(parsedJson, null, 2) }}</pre>
      <span v-else class="text-body-2">{{ rawValue }}</span>
    </div>

    <!-- Enum (single option) -->
    <v-chip
      v-else-if="type === 'enum' && optionLabel"
      size="small"
      variant="tonal"
      color="primary"
    >
      {{ optionLabel }}
    </v-chip>

    <!-- Multi-enum -->
    <div v-else-if="type === 'multienum'" class="d-flex flex-wrap ga-1">
      <v-chip
        v-for="(label, i) in multiOptionLabels"
        :key="i"
        size="x-small"
        variant="tonal"
        color="primary"
      >
        {{ label }}
      </v-chip>
      <span v-if="multiOptionLabels.length === 0" class="text-body-2 text-medium-emphasis">—</span>
    </div>

    <!-- Relation (IRI) -->
    <RouterLink
      v-else-if="type === 'relation' && relationLink"
      :to="relationLink"
      class="text-body-2 text-primary text-decoration-none d-inline-flex align-center ga-1"
    >
      <v-icon size="x-small">mdi-link-variant</v-icon>
      <span>{{ relationLabel }}</span>
      <v-progress-circular
        v-if="relationLoading"
        indeterminate
        size="12"
        width="2"
      />
    </RouterLink>

    <!-- Number / Integer / Decimal -->
    <span
      v-else-if="['number', 'integer', 'decimal'].includes(type)"
      class="text-body-2 font-weight-medium"
    >
      {{ formatNumber(rawValue) }}
    </span>

    <!-- Default text -->
    <span v-else class="text-body-2">
      {{ rawValue !== null && rawValue !== '' ? rawValue : '—' }}
    </span>
  </div>
</template>

<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import DOMPurify from 'dompurify'
import apiPlatform from '../../../services/apiPlatform'

const { t } = useI18n()

const relationLabelCache = new Map<string, string>()
const relationInflight = new Map<string, Promise<string>>()

interface Props {
  attributeValue: any
}

const props = defineProps<Props>()

const showFull = ref(false)

const type = computed<string>(() => props.attributeValue?.attributeDefinition?.type || 'text')
const rawValue = computed(() => props.attributeValue?.value)

const boolValue = computed(() => {
  const v = rawValue.value
  return v === true || v === 'true' || v === 1 || v === '1'
})

const parsedJson = computed(() => {
  const v = rawValue.value
  if (!v) return null
  if (typeof v === 'object') return v
  if (typeof v === 'string') {
    try {
      return JSON.parse(v)
    } catch {
      return null
    }
  }
  return null
})

const sanitizedHtml = computed(() => {
  return rawValue.value ? DOMPurify.sanitize(String(rawValue.value)) : ''
})

const hasMoreToShow = computed(() => {
  const stripped = stripHtml(rawValue.value, Infinity)
  return stripped.length > 200
})

const optionLabel = computed<string>(() => {
  const opt = props.attributeValue?.option
  if (!opt) return ''
  return opt.label || opt.code || String(opt)
})

const multiOptionLabels = computed<string[]>(() => {
  const values = props.attributeValue?.values
  if (!Array.isArray(values)) return []
  return values.map((v: any) => v?.label || v?.code || String(v))
})

const relationLink = computed<string | null>(() => {
  const v = rawValue.value
  if (typeof v !== 'string' || !v.startsWith('/api/')) return null
  // /api/collections/5 -> /show/Collection/5
  const match = v.match(/^\/api\/([a-z_]+)\/(\d+)$/i)
  if (!match) return null
  const resource = singularize(match[1])
  return `/show/${resource}/${match[2]}`
})

const relationLoading = ref(false)
const fetchedLabel = ref<string | null>(null)

const relationLabel = computed<string>(() => {
  if (fetchedLabel.value) return fetchedLabel.value
  const v = rawValue.value
  if (typeof v !== 'string') return '—'
  const m = v.match(/^\/api\/([a-z_]+)\/(\d+)$/i)
  return m ? `${singularize(m[1])} #${m[2]}` : v
})

function extractLabel(data: any): string {
  if (!data || typeof data !== 'object') return ''
  const direct = data.label || data.title || data.name || data.code
  if (direct) return String(direct)
  if (Array.isArray(data.translations) && data.translations.length > 0) {
    const t = data.translations.find((x: any) => x?.label) || data.translations[0]
    if (t?.label) return String(t.label)
  }
  return ''
}

async function loadRelationLabel(iri: string): Promise<string> {
  if (relationLabelCache.has(iri)) return relationLabelCache.get(iri)!
  if (relationInflight.has(iri)) return relationInflight.get(iri)!
  const p = (async () => {
    try {
      const data = await apiPlatform.getByIri(iri)
      const label = extractLabel(data) || iri
      relationLabelCache.set(iri, label)
      return label
    } catch {
      relationLabelCache.set(iri, iri)
      return iri
    } finally {
      relationInflight.delete(iri)
    }
  })()
  relationInflight.set(iri, p)
  return p
}

watch(
  () => [type.value, rawValue.value] as const,
  async ([t, v]) => {
    fetchedLabel.value = null
    if (t !== 'relation' || typeof v !== 'string' || !v.startsWith('/api/')) return
    if (relationLabelCache.has(v)) {
      fetchedLabel.value = relationLabelCache.get(v)!
      return
    }
    relationLoading.value = true
    try {
      fetchedLabel.value = await loadRelationLabel(v)
    } finally {
      relationLoading.value = false
    }
  },
  { immediate: true }
)

function singularize(plural: string): string {
  // collections -> Collection, attribute_definitions -> AttributeDefinition
  const parts = plural.split('_').map(p => p.charAt(0).toUpperCase() + p.slice(1))
  let joined = parts.join('')
  if (joined.endsWith('ies')) joined = joined.slice(0, -3) + 'y'
  else if (joined.endsWith('s')) joined = joined.slice(0, -1)
  return joined
}

function stripHtml(html: any, max: number): string {
  if (!html) return ''
  const text = String(html).replace(/<[^>]*>/g, '').replace(/\s+/g, ' ').trim()
  return text.length > max ? text.slice(0, max) + '…' : text
}

function truncateText(text: any, max: number): string {
  if (text === null || text === undefined || text === '') return '—'
  const s = String(text)
  return s.length > max ? s.slice(0, max) + '…' : s
}

function truncateMiddle(text: any, max: number): string {
  if (!text) return ''
  const s = String(text)
  if (s.length <= max) return s
  const half = Math.floor((max - 1) / 2)
  return s.slice(0, half) + '…' + s.slice(-half)
}

function formatNumber(v: any): string {
  if (v === null || v === undefined || v === '') return '—'
  const n = Number(v)
  return Number.isFinite(n) ? n.toLocaleString() : String(v)
}

function isImageUrl(v: any): boolean {
  if (typeof v !== 'string') return false
  return /\.(jpe?g|png|gif|webp|svg|avif)(\?|$)/i.test(v)
}
</script>

<style scoped>
.readonly-value {
  word-break: break-word;
}
.json-block {
  background: rgba(var(--v-theme-on-surface), 0.04);
  border-radius: 4px;
  padding: 6px 8px;
  font-size: 0.75rem;
  white-space: pre-wrap;
  margin: 0;
  max-height: 200px;
  overflow: auto;
}
.rich-content :deep(p) {
  margin: 0 0 6px;
}
.rich-content :deep(strong) {
  font-weight: 600;
}
.rich-content :deep(ul),
.rich-content :deep(ol) {
  padding-left: 20px;
  margin: 4px 0;
}
.text-pre-line {
  white-space: pre-line;
}
</style>
