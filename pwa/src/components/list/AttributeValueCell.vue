<template>
  <span>
    <template v-if="displayValue">
      {{ displayValue }}
    </template>
    <template v-else>
      <span class="text-grey">-</span>
    </template>
  </span>
</template>

<script setup lang="ts">
import { computed } from 'vue'
import { useAttributeOptions } from '../../composables/useAttributeOptions'

interface Props {
  item: Record<string, any>
  field: string
}

const props = defineProps<Props>()

const { getOptionLabel } = useAttributeOptions()

const displayValue = computed(() => {
  const item = props.item

  // Check for simple value field
  if (item.value) {
    return item.value
  }

  // Check for single option (enum)
  if (item.option) {
    const optionIri = typeof item.option === 'object' ? item.option['@id'] : item.option
    // Show code if available in object
    if (typeof item.option === 'object' && item.option.code) {
      return item.option.code
    }
    return getOptionLabel(optionIri)
  }

  // Check for multiple options (multienum)
  if (item.values && Array.isArray(item.values) && item.values.length > 0) {
    const labels = item.values.map((v: any) => {
      const iri = typeof v === 'object' ? v['@id'] : v
      if (typeof v === 'object' && v.code) {
        return v.code
      }
      return getOptionLabel(iri)
    })
    return labels.join(', ')
  }

  return null
})
</script>
