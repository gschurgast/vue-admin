<template>

<v-empty-state
  headline="Whoops"
  :title="t('resource.notFound.title')"
  :text="t('resource.notFound.message', { resource: resourceName })"
  image="https://vuetifyjs.b-cdn.net/docs/images/logos/v.png"
>
  <template v-slot:actions>
    <v-btn variant="outlined" to="/">{{ t('common.goHome') }}</v-btn>
  </template>
</v-empty-state>


</template>

<script setup lang="ts">
import { useI18n } from 'vue-i18n'

defineProps<{
  resourceName: string
}>()

const { t } = useI18n()
</script>
