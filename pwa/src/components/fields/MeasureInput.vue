<template>
  <v-number-input
    v-model="numericValue"
    control-variant="stacked"
    :label="label"
    :min="numericMin"
    :max="numericMax"
    :step="step"
    :hint="hint"
    :persistent-hint="persistentHint"
    :rules="rules"
    :required="required"
    :suffix="unit || ''"
    @update:model-value="emitChange"
  />
</template>

<script setup lang="ts">
import { ref, computed, watch } from 'vue'

interface Props {
  /** JSON string in the form `{"value": number, "unit": string}` */
  modelValue?: string | null
  /** Unit shown as a non-editable suffix and persisted with the value */
  unit?: string | null
  label?: string
  min?: number | string | null
  max?: number | string | null
  step?: number | string
  hint?: string
  persistentHint?: boolean
  required?: boolean
  rules?: Array<(v: any) => boolean | string>
}

const props = withDefaults(defineProps<Props>(), {
  modelValue: null,
  unit: '',
  label: 'Value',
  min: null,
  max: null,
  step: 1,
  hint: '',
  persistentHint: false,
  required: false,
  rules: () => [],
})

const emit = defineEmits<{
  'update:modelValue': [value: string]
}>()

const numericValue = ref<number | null>(null)

const numericMin = computed(() => toNumber(props.min))
const numericMax = computed(() => toNumber(props.max))

function toNumber(v: number | string | null | undefined): number | undefined {
  if (v === null || v === undefined || v === '') return undefined
  const n = Number(v)
  return Number.isFinite(n) ? n : undefined
}

function parse(value: string | null | undefined): number | null {
  if (!value) return null
  try {
    const parsed = JSON.parse(value)
    return typeof parsed?.value === 'number' ? parsed.value : null
  } catch {
    return null
  }
}

function emitChange() {
  const payload = JSON.stringify({
    value: numericValue.value,
    unit: props.unit || '',
  })
  emit('update:modelValue', payload)
}

watch(
  () => props.modelValue,
  (val) => {
    numericValue.value = parse(val)
  },
  { immediate: true }
)
</script>
