<template>

<v-empty-state
  headline="403"
  :title="t('resource.forbidden.title')"
  :text="t('resource.forbidden.message', { resource: resourceName })"
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
