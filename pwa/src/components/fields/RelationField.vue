<template>
 <BaseField
     :component="VAutocomplete"
     v-bind="$props"
     item-title="name"
     item-value="@id"
     @update:modelValue="$emit('update:modelValue', $event)"
 />
</template>
<script setup lang="ts">
import BaseField from "@/components/fields/BaseField.vue";
import { VAutocomplete } from 'vuetify/components'
</script>
