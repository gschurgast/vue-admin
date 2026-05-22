<template>
  <v-btn
    :variant="resolvedVariant"
    :color="resolvedColor"
    :size="size"
    :prepend-icon="prependIcon"
    :append-icon="appendIcon"
    :loading="loading"
    :disabled="disabled"
    class="page-action-btn"
  >
    <slot />
  </v-btn>
</template>

<script setup lang="ts">
import { computed } from 'vue'

type Kind = 'primary' | 'success' | 'danger' | 'secondary' | 'ghost'

const props = withDefaults(defineProps<{
  kind?: Kind
  size?: 'x-small' | 'small' | 'default' | 'large' | 'x-large'
  prependIcon?: string
  appendIcon?: string
  loading?: boolean
  disabled?: boolean
}>(), {
  kind: 'secondary',
  size: 'small',
  loading: false,
  disabled: false,
})

const resolvedVariant = computed(() => {
  switch (props.kind) {
    case 'primary':
    case 'success':
    case 'danger':
      return 'flat' as const
    case 'secondary':
      return 'tonal' as const
    case 'ghost':
    default:
      return 'text' as const
  }
})

const resolvedColor = computed(() => {
  switch (props.kind) {
    case 'primary': return 'primary'
    case 'success': return 'success'
    case 'danger': return 'error'
    default: return undefined
  }
})
</script>
