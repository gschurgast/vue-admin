<template>
  <div class="step-fields">
    <NumberField
      :model-value="(modelValue.angle as number | null | undefined) ?? null"
      :label="t('asset_transformation.step.rotate.angle')"
      :step="1"
      @update:model-value="set('angle', $event)"
    />
    <CodeField
      :model-value="(modelValue.background as string | null | undefined) ?? null"
      :label="t('asset_transformation.step.rotate.background')"
      @update:model-value="set('background', $event)"
    />
  </div>
</template>

<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import NumberField from '@/components/fields/NumberField.vue'
import CodeField from '@/components/fields/CodeField.vue'

interface Props {
  modelValue: Record<string, unknown>
}

const props = withDefaults(defineProps<Props>(), {
  modelValue: () => ({}),
})

const emit = defineEmits<{
  (e: 'update:modelValue', value: Record<string, unknown>): void
}>()

const { t } = useI18n()

function set(key: string, value: unknown): void {
  emit('update:modelValue', { ...props.modelValue, [key]: value })
}
</script>
