<template>
  <div class="inline-editor">
    <AttributeValueEditor
      :definition="definition"
      :value="props.attributeValue.value"
      :option="props.attributeValue.option"
      :values="props.attributeValue.values"
      :label="label"
      @change="onChange"
    />
  </div>
</template>

<script setup lang="ts">
import { computed, onMounted } from 'vue'
import { useAttributeOptions } from '../../../composables/useAttributeOptions'
import AttributeValueEditor from '../../fields/AttributeValueEditor.vue'

interface Props {
  attributeValue: {
    attributeDefinition?: any
    value?: string | null
    values?: string[] | null
    option?: any
  }
  label?: string
}

const props = withDefaults(defineProps<Props>(), {
  label: '',
})

const emit = defineEmits<{
  change: [data: { value?: string | null; option?: string | null; values?: string[] | null }]
}>()

const { ensureLoaded } = useAttributeOptions()

const definition = computed(() => props.attributeValue.attributeDefinition)

function onChange(data: { value?: string | null; option?: string | null; values?: string[] | null }) {
  emit('change', data)
}

onMounted(() => {
  ensureLoaded()
})
</script>
