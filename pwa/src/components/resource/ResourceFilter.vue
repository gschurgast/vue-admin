<template>
  <v-expand-transition>
    <v-card v-show="modelValue" color="blue-grey-lighten-5" >
      <v-card-text>
        <v-row>
          <v-col
            v-for="filter in filterFields"
            :key="filter.field"
            cols="12"
            md="4"
          >
            <!-- Custom filter component -->
            <component
              v-if="filter.customComponent && customComponents[`filters/${filter.customComponent}`]"
              :is="customComponents[`filters/${filter.customComponent}`]"
              :filter="filter"
              :model-value="localFilters[filter.field]"
              @update:model-value="updateFilter(filter.field, $event)"
              :label="getFilterLabel(filter)"
            />

            <!-- Date range filter -->
            <DateRangeFilter
              v-else-if="filter.type === 'date-range'"
              :filter="filter"
              :model-value="localFilters[filter.field]"
              @update:model-value="updateFilter(filter.field, $event)"
              :label="getFilterLabel(filter)"
            />

            <!-- Date filter -->
            <DateFilter
              v-else-if="filter.type === 'date'"
              :filter="filter"
              :model-value="localFilters[filter.field]"
              @update:model-value="updateFilter(filter.field, $event)"
              :label="getFilterLabel(filter)"
              @search="handleSearch"
            />

            <!-- Text filter (default) -->
            <TextFilter
              v-else
              :filter="filter"
              :model-value="localFilters[filter.field]"
              @update:model-value="updateFilter(filter.field, $event)"
              :label="getFilterLabel(filter)"
            />
          </v-col>
        </v-row>
      </v-card-text>
     <v-card-actions>
      <v-spacer></v-spacer>

            <v-btn color="primary" variant="outlined" @click="handleSearch">
              <v-icon left>mdi-magnify</v-icon>
              {{ t('common.search') }}
            </v-btn>
            <v-btn variant="outlined" @click="handleClear">
              {{ t('common.clear') }}
            </v-btn>
     </v-card-actions>

    </v-card>
  </v-expand-transition>
</template>

<script setup lang="ts">
import { ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import TextFilter from '../filters/TextFilter.vue'
import DateFilter from '../filters/DateFilter.vue'
import DateRangeFilter from '../filters/DateRangeFilter.vue'

interface Props {
  modelValue: boolean
  filterFields: Array<any>
  filters: Record<string, any>
  customComponents?: Record<string, any>
  resourceName: string
}

const props = withDefaults(defineProps<Props>(), {
  customComponents: () => ({})
})

const emit = defineEmits<{
  'update:modelValue': [value: boolean]
  'update:filters': [value: Record<string, any>]
  'search': []
  'clear': []
}>()

const { t } = useI18n()
const localFilters = ref({ ...props.filters })

// Watch for external filter changes (e.g., when cleared from parent)
watch(() => props.filters, (newValue) => {
  localFilters.value = { ...newValue }
}, { deep: true })

// Update local filters and emit to parent
function updateFilter(field: string, value: any) {
  localFilters.value[field] = value
  emit('update:filters', { ...localFilters.value })
}

function getFilterLabel(filter: any): string {
  if (filter.label) return filter.label
  const resourceLower = String(props.resourceName).toLowerCase()
  return t(`resources.${resourceLower}.fields.${filter.field}`, filter.field)
}

function handleSearch() {
  emit('search')
}

function handleClear() {
  localFilters.value = {}
  emit('clear')
}
</script>
