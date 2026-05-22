<template>
  <div class="resource-header d-flex align-center">
    <v-progress-linear
      v-if="loading"
      indeterminate
      color="primary"
      height="2"
      absolute
      location="top"
    />

    <v-breadcrumbs :items="breadcrumbs" class="px-0 py-0">
      <template v-slot:divider>
        <v-icon size="small">mdi-chevron-right</v-icon>
      </template>
    </v-breadcrumbs>

    <v-spacer />

    <slot name="actions" />
  </div>
</template>

<style scoped>
.resource-header {
  position: relative;
  min-height: 48px;
  margin-bottom: 12px;
  gap: 8px;
}
</style>

<script setup lang="ts">
interface BreadcrumbItem {
  title: string
  disabled?: boolean
  to?: string
  href?: string
}

interface Props {
  breadcrumbs: BreadcrumbItem[]
  loading?: boolean
}

withDefaults(defineProps<Props>(), {
  loading: false
})
</script>
