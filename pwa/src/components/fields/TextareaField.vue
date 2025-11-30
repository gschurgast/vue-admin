<template>
 <BaseField
     :component="VTextarea"
     v-bind="$props"
     @update:modelValue="$emit('update:modelValue', $event)"
 />
</template>
<script setup lang="ts">
import BaseField from "@/components/fields/BaseField.vue";
import { VTextarea } from 'vuetify/components'
</script>
