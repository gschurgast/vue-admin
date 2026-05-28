<template>
  <div class="step-fields">
    <EnumField
      :model-value="(modelValue.model as string | null | undefined) ?? 'birefnet'"
      :label="t('asset_transformation.step.remove_background.model')"
      :enum-values="['birefnet', 'isnet-general-use']"
      @update:model-value="set('model', $event)"
    />
    <BooleanField
      :model-value="Boolean(modelValue.fallbackOnTimeout)"
      :label="t('asset_transformation.step.remove_background.fallback_on_timeout')"
      @update:model-value="set('fallbackOnTimeout', $event)"
    />
  </div>
</template>

<script setup lang="ts">
import { useI18n } from 'vue-i18n'
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
