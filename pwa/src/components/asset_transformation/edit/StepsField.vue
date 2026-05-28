<template>
  <div class="steps-field">
    <WarningBanner :warnings="allWarnings" />

    <draggable
      v-model="local"
      item-key="id"
      handle=".drag-handle"
      :animation="200"
      ghost-class="step-ghost"
      class="steps-list"
    >
      <template #item="{ element, index }">
        <v-card
          class="step-card mb-2"
          :class="{ 'step-card--warning': hasWarning(index) }"
          variant="outlined"
        >
          <div class="step-row">
            <v-icon class="drag-handle" :title="t('asset_transformation.steps.drag')">
              mdi-drag-vertical
            </v-icon>

            <div class="step-body">
              <div class="step-header">
                <strong>{{ index + 1 }}. {{ stepLabel(element.type) }}</strong>
                <v-chip
                  v-if="hasWarning(index)"
                  color="warning"
                  size="x-small"
                  variant="tonal"
                  class="ml-2"
                >
                  {{ t('asset_transformation.warnings.chip') }}
                </v-chip>
              </div>

              <component
                :is="componentRegistry[element.type as StepType]"
                v-if="componentRegistry[element.type as StepType]"
                :model-value="element.params ?? {}"
                @update:model-value="updateParams(index, $event)"
              />
              <div v-else class="text-error">
                {{ t('asset_transformation.steps.unknown_type', { type: element.type }) }}
              </div>
            </div>

            <PageActionBtn
              kind="ghost"
              icon
              :title="t('asset_transformation.steps.remove')"
              @click="removeStep(index)"
            >
              <v-icon>mdi-delete-outline</v-icon>
            </PageActionBtn>
          </div>
        </v-card>
      </template>
    </draggable>

    <v-menu>
      <template #activator="{ props: menuProps }">
        <PageActionBtn
          kind="secondary"
          prepend-icon="mdi-plus"
          v-bind="menuProps"
        >
          {{ t('asset_transformation.steps.add') }}
        </PageActionBtn>
      </template>
      <v-list density="compact">
        <v-list-item
          v-for="type in stepTypes"
          :key="type"
          :title="stepLabel(type)"
          @click="addStep(type)"
        />
      </v-list>
    </v-menu>

    <PreviewPanel
      :steps="local"
      :output-ext="resolvedExt"
      :transformation-id="formData?.id ?? formData?.['@id'] ?? null"
    />
  </div>
</template>

<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import draggable from 'vuedraggable'

import ResizeStepFields from './steps/ResizeStepFields.vue'
import CropStepFields from './steps/CropStepFields.vue'
import RotateStepFields from './steps/RotateStepFields.vue'
import FormatConvertStepFields from './steps/FormatConvertStepFields.vue'
import AddBackgroundStepFields from './steps/AddBackgroundStepFields.vue'
import RemoveBackgroundStepFields from './steps/RemoveBackgroundStepFields.vue'
import WarningBanner from './WarningBanner.vue'
import PreviewPanel from './PreviewPanel.vue'

import PageActionBtn from '@/components/common/PageActionBtn.vue'

import {
  useTransformationWarnings,
  type TransformationStep,
  type TransformationWarning,
} from '@/composables/useTransformationWarnings'

type StepType =
  | 'resize'
  | 'crop'
  | 'rotate'
  | 'format_convert'
  | 'add_background'
  | 'remove_background'

interface Props {
  modelValue?: TransformationStep[]
  // formData provided by ResourceForm — used to read the current output extension
  // so warnings update reactively when format_convert is reordered/edited.
  formData?: Record<string, unknown>
  // Optional explicit override (caller may know the resolved ext)
  outputExt?: string
  // Server-side warnings (transformation.warnings) merged with the client mirror.
  serverWarnings?: TransformationWarning[]
}

const props = withDefaults(defineProps<Props>(), {
  modelValue: () => [],
  formData: () => ({}),
  outputExt: undefined,
  serverWarnings: () => [],
})

const emit = defineEmits<{
  (e: 'update:modelValue', steps: TransformationStep[]): void
}>()

const { t, te } = useI18n()

const componentRegistry: Record<StepType, unknown> = {
  resize: ResizeStepFields,
  crop: CropStepFields,
  rotate: RotateStepFields,
  format_convert: FormatConvertStepFields,
  add_background: AddBackgroundStepFields,
  remove_background: RemoveBackgroundStepFields,
}

const stepTypes: StepType[] = [
  'resize',
  'crop',
  'rotate',
  'format_convert',
  'add_background',
  'remove_background',
]

// vuedraggable expects a writable array via v-model; we keep emit semantics.
const local = computed<TransformationStep[]>({
  get: () => props.modelValue ?? [],
  set: (v) => emit('update:modelValue', ensureIds(v)),
})

function ensureIds(steps: TransformationStep[]): TransformationStep[] {
  return steps.map((s) =>
    s.id
      ? s
      : { ...s, id: typeof crypto?.randomUUID === 'function' ? crypto.randomUUID() : `${Date.now()}-${Math.random()}` }
  )
}

// Resolve the output extension from the formData (preferred) or last
// format_convert step in the pipeline. Falls back to 'png' (lossless default).
const resolvedExt = computed<string>(() => {
  if (props.outputExt) return props.outputExt
  const fd = props.formData ?? {}
  const direct = (fd.outputExt ?? fd.ext) as string | undefined
  if (direct) return direct
  const last = [...(props.modelValue ?? [])]
    .reverse()
    .find((s) => s.type === 'format_convert' && s.params && (s.params as Record<string, unknown>).format)
  const fmt = last?.params?.format as string | undefined
  return fmt ?? 'png'
})

const clientWarnings = computed<TransformationWarning[]>(() =>
  useTransformationWarnings(props.modelValue, resolvedExt.value)
)

// Merge server + client warnings, dedup by (code, stepIndex).
const allWarnings = computed<TransformationWarning[]>(() => {
  const merged: TransformationWarning[] = []
  const seen = new Set<string>()
  for (const w of [...(props.serverWarnings ?? []), ...clientWarnings.value]) {
    const key = `${w.code}::${w.stepIndex ?? '-'}`
    if (seen.has(key)) continue
    seen.add(key)
    merged.push(w)
  }
  return merged
})

const warningIndices = computed<Set<number>>(() => {
  const s = new Set<number>()
  for (const w of allWarnings.value) {
    if (typeof w.stepIndex === 'number') s.add(w.stepIndex)
  }
  return s
})

function hasWarning(index: number): boolean {
  return warningIndices.value.has(index)
}

function stepLabel(type: string): string {
  const key = `asset_transformation.step.${type}.label`
  return te(key) ? t(key) : type
}

function updateParams(index: number, params: Record<string, unknown>): void {
  const next = [...(props.modelValue ?? [])]
  next[index] = { ...next[index], params }
  emit('update:modelValue', next)
}

function removeStep(index: number): void {
  const next = [...(props.modelValue ?? [])]
  next.splice(index, 1)
  emit('update:modelValue', next)
}

function addStep(type: StepType): void {
  const id =
    typeof crypto?.randomUUID === 'function' ? crypto.randomUUID() : `${Date.now()}-${Math.random()}`
  const next = [...(props.modelValue ?? []), { id, type, params: {} }]
  emit('update:modelValue', next)
}
</script>

<style scoped>
.steps-field {
  display: flex;
  flex-direction: column;
  gap: 0.5rem;
}

.steps-list {
  display: flex;
  flex-direction: column;
  gap: 0.5rem;
}

.step-card {
  padding: 0.75rem;
  background: rgb(var(--v-theme-surface));
}

.step-card--warning {
  border-color: rgb(var(--v-theme-warning));
}

.step-row {
  display: flex;
  align-items: flex-start;
  gap: 0.75rem;
}

.drag-handle {
  cursor: grab;
  margin-top: 0.5rem;
}

.drag-handle:active {
  cursor: grabbing;
}

.step-body {
  flex: 1;
  display: flex;
  flex-direction: column;
  gap: 0.5rem;
}

.step-header {
  display: flex;
  align-items: center;
}

.step-ghost {
  opacity: 0.5;
  background: rgb(var(--v-theme-surface-variant));
}
</style>
