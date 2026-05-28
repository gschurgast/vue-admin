<template>
  <v-dialog v-model="open" max-width="1200" scrollable>
    <v-card>
      <v-card-title class="d-flex align-center justify-space-between">
        {{ t('asset_picker.title') }}
        <v-btn variant="text" icon="mdi-close" @click="cancel" />
      </v-card-title>
      <v-divider />
      <v-card-text class="pa-4" style="min-height: 60vh;">
        <AssetGrid
          :resource-name="'Asset'"
          :title="''"
          @view="onSelect"
        />
      </v-card-text>
    </v-card>
  </v-dialog>
</template>

<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { useLocalStorage } from '@vueuse/core'
import AssetGrid from '../../asset/list/AssetGrid.vue'

interface Props {
  modelValue: boolean
  transformationId: number | string | null
}

const props = defineProps<Props>()

const emit = defineEmits<{
  (e: 'update:modelValue', value: boolean): void
  (e: 'select', assetId: number): void
}>()

const { t } = useI18n()

const open = computed<boolean>({
  get: () => props.modelValue,
  set: (v) => emit('update:modelValue', v),
})

// D-07 — mémorisation localStorage per-transformation. Lecture/écriture
// déléguée au PreviewPanel parent ; le dialog se contente d'émettre select(id).
const _lastSelected = useLocalStorage<number | null>(
  () => `preview_asset_${props.transformationId ?? 'unknown'}`,
  null,
)

function onSelect(item: { id?: number; '@id'?: string }) {
  const id = item.id ?? (item['@id'] ? Number(String(item['@id']).split('/').pop()) : NaN)
  if (Number.isFinite(id)) {
    _lastSelected.value = id as number
    emit('select', id as number)
    open.value = false
  }
}

function cancel() {
  open.value = false
}
</script>