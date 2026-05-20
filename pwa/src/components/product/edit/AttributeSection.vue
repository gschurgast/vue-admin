<template>
  <v-card :variant="empty ? 'outlined' : 'elevated'" :border="empty">
    <v-card-item :class="`bg-${color}-lighten-5`">
      <template #prepend>
        <v-avatar :color="color" size="36" variant="tonal">
          <v-icon>{{ icon }}</v-icon>
        </v-avatar>
      </template>
      <v-card-title class="d-flex align-center pa-0">
        <span class="text-subtitle-1 font-weight-medium">{{ title }}</span>
        <v-chip
          :color="color"
          size="x-small"
          variant="flat"
          class="ml-2 text-uppercase"
        >
          {{ scopeLabel }}
        </v-chip>
        <v-chip
          v-if="readonly"
          size="x-small"
          variant="tonal"
          color="grey"
          class="ml-2"
          prepend-icon="mdi-lock-outline"
        >
          {{ t('common.readonly') }}
        </v-chip>
        <v-chip
          v-if="attributes.length > 0"
          size="x-small"
          variant="tonal"
          class="ml-2"
        >
          {{ attributes.length }}
        </v-chip>
        <v-spacer />
        <v-btn
          v-if="readonly && editLink"
          :to="editLink"
          :color="color"
          size="small"
          variant="tonal"
        >
          <v-icon start size="small">mdi-pencil-outline</v-icon>
          {{ t('attributes.editOnProduct') }}
        </v-btn>
        <v-btn
          v-else-if="!readonly"
          :color="color"
          :disabled="addDisabled"
          size="small"
          variant="tonal"
          @click="emit('add')"
        >
          <v-icon start size="small">mdi-plus</v-icon>
          {{ t('common.add') }}
        </v-btn>
      </v-card-title>
      <v-card-subtitle class="pa-0 mt-1">
        {{ subtitle }}
      </v-card-subtitle>
    </v-card-item>

    <v-divider />

    <v-card-text :class="{ 'py-3': !empty, 'py-2': empty }">
      <template v-if="!empty">
        <v-form v-if="!readonly">
          <div
            v-for="(attrValue, index) in attributes"
            :key="`${scopeLabel}-${attrValue.id || attrValue._tempId || index}`"
            class="d-flex align-start attribute-row"
          >
            <div class="flex-grow-1">
              <InlineAttributeValueEditor
                :attribute-value="attrValue"
                :label="attrValue.attributeDefinition?.code || 'Unknown'"
                @change="emit('change', attrValue, $event)"
              />
            </div>
            <v-btn
              icon="mdi-delete-outline"
              size="small"
              variant="text"
              color="error"
              class="mt-3 ml-2"
              @click="emit('delete', index)"
            />
          </div>
        </v-form>

        <!-- Read-only display: type-aware key/value list -->
        <div v-else class="readonly-list">
          <div
            v-for="(attrValue, index) in attributes"
            :key="`ro-${scopeLabel}-${attrValue.id || index}`"
            class="readonly-item"
          >
            <div class="readonly-label text-caption text-medium-emphasis text-uppercase">
              {{ attrValue.attributeDefinition?.code || 'Unknown' }}
            </div>
            <div class="readonly-content">
              <ReadOnlyAttributeValue :attribute-value="attrValue" />
            </div>
          </div>
        </div>
      </template>

      <div v-else class="text-medium-emphasis text-body-2 text-center py-2">
        <v-icon size="small" class="mr-1">mdi-information-outline</v-icon>
        {{ emptyText }}
      </div>
    </v-card-text>
  </v-card>
</template>

<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import InlineAttributeValueEditor from './InlineAttributeValueEditor.vue'
import ReadOnlyAttributeValue from './ReadOnlyAttributeValue.vue'

const { t } = useI18n()

interface Props {
  title: string
  subtitle: string
  icon: string
  color: string
  scopeLabel: string
  attributes: any[]
  emptyText: string
  readonly?: boolean
  editLink?: string | null
  addDisabled?: boolean
}

const props = withDefaults(defineProps<Props>(), {
  readonly: false,
  editLink: null,
  addDisabled: false
})

const emit = defineEmits<{
  add: []
  change: [attrValue: any, data: { value?: string | null, option?: string | null, values?: string[] | null }]
  delete: [index: number]
}>()

const empty = computed(() => props.attributes.length === 0)

function formatValue(attrValue: any): string {
  if (attrValue.value !== null && attrValue.value !== undefined && attrValue.value !== '') {
    const str = String(attrValue.value)
    return str.length > 200 ? str.slice(0, 200) + '…' : str
  }
  if (attrValue.option) {
    return attrValue.option.label || attrValue.option.code || String(attrValue.option)
  }
  if (Array.isArray(attrValue.values) && attrValue.values.length > 0) {
    return attrValue.values
      .map((v: any) => v?.label || v?.code || v)
      .join(', ')
  }
  return '—'
}
</script>

<style scoped>
.attribute-row + .attribute-row {
  border-top: 1px dashed rgba(var(--v-border-color), 0.12);
  padding-top: 8px;
  margin-top: 4px;
}
.readonly-list {
  display: grid;
  grid-template-columns: 1fr;
  gap: 10px;
}
.readonly-item {
  display: grid;
  grid-template-columns: 140px 1fr;
  align-items: start;
  gap: 12px;
  padding: 6px 0;
  border-bottom: 1px dashed rgba(var(--v-border-color), 0.12);
}
.readonly-item:last-child {
  border-bottom: none;
}
.readonly-label {
  letter-spacing: 0.5px;
  padding-top: 4px;
  word-break: break-word;
}
</style>
