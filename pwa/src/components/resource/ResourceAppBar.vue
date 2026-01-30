<template>
  <v-app-bar density="compact" color="blue-grey-darken-1">
    <v-progress-linear
      v-if="loading"
      indeterminate
      color="primary"
      height="3"
      absolute
      location="top"
    />

    <!-- Breadcrumbs -->
    <v-breadcrumbs :items="breadcrumbs" class="py-0">
      <template v-slot:divider>
        <v-icon size="small">mdi-chevron-right</v-icon>
      </template>
    </v-breadcrumbs>

    <v-spacer />

    <template v-slot:append>
      <slot name="actions" />
    </template>
  </v-app-bar>
</template>

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
