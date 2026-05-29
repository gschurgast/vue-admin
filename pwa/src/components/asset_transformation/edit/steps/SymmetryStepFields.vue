<template>
  <div class="step-fields">
    <v-radio-group
      :model-value="(modelValue.axis as string | null | undefined) ?? null"
      :label="t('asset_transformation.step.symmetry.axis')"
      inline
      hide-details
      @update:model-value="set('axis', $event)"
    >
      <v-radio
        :label="t('asset_transformation.step.symmetry.axis_horizontal')"
        value="horizontal"
      />
      <v-radio
        :label="t('asset_transformation.step.symmetry.axis_vertical')"
        value="vertical"
      />
    </v-radio-group>
  </div>
</template>

<script setup lang="ts">
import { useI18n } from 'vue-i18n'

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