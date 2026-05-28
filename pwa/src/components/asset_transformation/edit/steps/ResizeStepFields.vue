<template>
  <div class="step-fields">
    <NumberField
      :model-value="(modelValue.width as number | null | undefined) ?? null"
      :label="t('asset_transformation.step.resize.width')"
      :step="1"
      @update:model-value="set('width', $event)"
    />
    <NumberField
      :model-value="(modelValue.height as number | null | undefined) ?? null"
      :label="t('asset_transformation.step.resize.height')"
      :step="1"
      @update:model-value="set('height', $event)"
    />
    <EnumField
      :model-value="(modelValue.mode as string | null | undefined) ?? null"
      :label="t('asset_transformation.step.resize.mode')"
      :enum-values="['fit', 'cover', 'contain']"
      @update:model-value="set('mode', $event)"
    />
    <BooleanField
      :model-value="Boolean(modelValue.upscale)"
      :label="t('asset_transformation.step.resize.upscale')"
      @update:model-value="set('upscale', $event)"
    />
  </div>
</template>

<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import NumberField from '@/components/fields/NumberField.vue'
import EnumField from '@/components/fields/EnumField.vue'
import BooleanField from '@/components/fields/BooleanField.vue'

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
