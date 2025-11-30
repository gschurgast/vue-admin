<template>
 <BaseField
     :component="VTextField"
     v-bind="$props"
     @update:modelValue="$emit('update:modelValue', $event)"
 />
</template>
<script setup lang="ts">
import BaseField from "@/components/fields/BaseField.vue";
import { VTextField } from 'vuetify/components'
</script>
