<template>
  <v-dialog v-model="localOpen" max-width="400px">
    <v-card>
      <v-card-title>{{ t('resource.delete', { resource: resourceTitle }) }}</v-card-title>
      <v-card-text>{{ t('resource.confirmDelete', { resource: resourceTitle.toLowerCase() }) }}</v-card-text>
      <v-card-actions>
        <v-spacer></v-spacer>
        <v-btn @click="handleCancel">{{ t('common.cancel') }}</v-btn>
        <v-btn color="error" @click="handleConfirm">{{ t('common.delete') }}</v-btn>
      </v-card-actions>
    </v-card>
  </v-dialog>
</template>

<script setup lang="ts">
import { ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'

interface Props {
  modelValue: boolean
  resourceTitle: string
}

const props = defineProps<Props>()

const emit = defineEmits<{
  'update:modelValue': [value: boolean]
  'confirm': []
  'cancel': []
}>()

const { t } = useI18n()
const localOpen = ref(props.modelValue)

// Sync dialog state
watch(() => props.modelValue, (newValue) => {
  localOpen.value = newValue
})

watch(localOpen, (newValue) => {
  emit('update:modelValue', newValue)
})

function handleConfirm() {
  emit('confirm')
  localOpen.value = false
}

function handleCancel() {
  emit('cancel')
  localOpen.value = false
}
</script>
