<template>
  <div class="step-fields">
    <EnumField
      :model-value="(modelValue.format as string | null | undefined) ?? null"
      :label="t('asset_transformation.step.format_convert.format')"
      :enum-values="['png', 'jpg', 'jpeg', 'webp', 'avif']"
      @update:model-value="set('format', $event)"
    />
    <NumberField
      :model-value="(modelValue.quality as number | null | undefined) ?? null"
      :label="t('asset_transformation.step.format_convert.quality')"
      :step="1"
      @update:model-value="set('quality', $event)"
    />
  </div>
</template>

<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import NumberField from '@/components/fields/NumberField.vue'
import EnumField from '@/components/fields/EnumField.vue'

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
