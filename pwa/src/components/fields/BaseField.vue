<script setup>
import { computed } from 'vue'

const props = defineProps({
 component: {
  type: Object,
  required: true
 },
 modelValue: null,
 label: String,
 required: Boolean,
 errorMessages: Array,
 formatter: Function,
 parser: Function
})

const emit = defineEmits(['update:modelValue'])

const value = computed({
 get: () => props.formatter ? props.formatter(props.modelValue) : props.modelValue,
 set: v => emit('update:modelValue', props.parser ? props.parser(v) : v)
})
</script>

<template>
 <component
     :is="component"
     v-model="value"
     :label="label"
     :required="required"
     :error-messages="errorMessages"
     clearable
 />
</template>
