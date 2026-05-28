<template>
  <div class="step-fields">
    <EnumField
      :model-value="(modelValue.type as string | null | undefined) ?? null"
      :label="t('asset_transformation.step.add_background.type')"
      :enum-values="['color', 'asset']"
      @update:model-value="set('type', $event)"
    />
    <CodeField
      v-if="modelValue.type === 'color'"
      :model-value="(modelValue.color as string | null | undefined) ?? null"
      :label="t('asset_transformation.step.add_background.color')"
      @update:model-value="set('color', $event)"
    />
    <NumberField
      v-else-if="modelValue.type === 'asset'"
      :model-value="(modelValue.assetId as number | null | undefined) ?? null"
      :label="t('asset_transformation.step.add_background.asset_id')"
      :step="1"
      @update:model-value="set('assetId', $event)"
    />
  </div>
</template>

<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import EnumField from '@/components/fields/EnumField.vue'
import CodeField from '@/components/fields/CodeField.vue'
import NumberField from '@/components/fields/NumberField.vue'

// NOTE: per plan 05-04 task 2, the spec mentions RelationField for assetId.
// RelationField requires pre-fetched items + a parent-supplied resource list,
// which is unavailable inside an embedded step sub-form. We expose a numeric
// assetId NumberField as a pragmatic intermediate (still validated server-side
// by AddBackgroundStepParams). A richer asset picker may follow in a later plan.

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
