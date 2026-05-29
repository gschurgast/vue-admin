<template>
  <div class="step-fields">
    <EnumField
      :model-value="(modelValue.mode as string | null | undefined) ?? 'rect'"
      :label="t('asset_transformation.step.crop.mode')"
      :enum-values="['rect', 'aspect']"
      @update:model-value="set('mode', $event)"
    />

    <template v-if="(modelValue.mode ?? 'rect') === 'rect'">
      <NumberField
        :model-value="(modelValue.x as number | null | undefined) ?? null"
        :label="t('asset_transformation.step.crop.x')"
        :step="1"
        @update:model-value="set('x', $event)"
      />
      <NumberField
        :model-value="(modelValue.y as number | null | undefined) ?? null"
        :label="t('asset_transformation.step.crop.y')"
        :step="1"
        @update:model-value="set('y', $event)"
      />
      <NumberField
        :model-value="(modelValue.width as number | null | undefined) ?? null"
        :label="t('asset_transformation.step.crop.width')"
        :step="1"
        @update:model-value="set('width', $event)"
      />
      <NumberField
        :model-value="(modelValue.height as number | null | undefined) ?? null"
        :label="t('asset_transformation.step.crop.height')"
        :step="1"
        @update:model-value="set('height', $event)"
      />
    </template>

    <template v-else>
      <v-text-field
        :model-value="(modelValue.aspectRatio as string | null | undefined) ?? ''"
        :label="t('asset_transformation.step.crop.aspect_ratio')"
        placeholder="16:9"
        hint="Format W:H (ex. 16:9, 4:3, 1:1)"
        persistent-hint
        :rules="[(v: string) => !v || /^\d+:\d+$/.test(v) || 'Format attendu : W:H (entiers, ex. 16:9)']"
        @update:model-value="set('aspectRatio', $event || null)"
      />
      <EnumField
        :model-value="(modelValue.anchor as string | null | undefined) ?? 'center'"
        :label="t('asset_transformation.step.crop.anchor')"
        :enum-values="['top-left','top','top-right','left','center','right','bottom-left','bottom','bottom-right']"
        @update:model-value="set('anchor', $event)"
      />
    </template>
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
