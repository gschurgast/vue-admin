<template>
  <v-card v-if="variants.length > 0">
    <v-card-title class="d-flex align-center">
      <span>{{ t('variantList.title') }}</span>
      <v-chip size="x-small" variant="tonal" class="ml-2">{{ variants.length }}</v-chip>
    </v-card-title>
    <v-card-subtitle class="pb-3">
      {{ t('variantList.subtitle') }}
    </v-card-subtitle>
    <v-list density="compact" class="py-0">
      <v-list-item
        v-for="variant in sortedVariants"
        :key="variant.id"
        :to="`/edit/ProductVariant/${variant.id}`"
        :active="String(variant.id) === String(currentVariantId)"
      >
        <template #prepend>
          <v-icon
            size="small"
            :color="variant.isDefault ? 'amber-darken-2' : 'grey-lighten-1'"
          >
            {{ variant.isDefault ? 'mdi-star' : 'mdi-star-outline' }}
          </v-icon>
        </template>
        <v-list-item-title class="text-body-2 font-weight-medium">
          {{ variant.sku }}
        </v-list-item-title>
        <template #append>
          <v-chip
            v-if="variant.isDefault"
            color="amber-darken-2"
            size="x-small"
            variant="tonal"
          >
            {{ t('variantList.default') }}
          </v-chip>
        </template>
      </v-list-item>
    </v-list>
  </v-card>
</template>

<script setup lang="ts">
import { ref, computed, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import apiPlatform from '../../../services/apiPlatform'
import type { Generated__ProductVariant } from '../../../types/schemas'

type Variant = Generated__ProductVariant

interface Props {
  productIri: string | null
  currentVariantId?: string | number | null
}

const props = withDefaults(defineProps<Props>(), {
  currentVariantId: null
})

const { t } = useI18n()
const variants = ref<Variant[]>([])

const sortedVariants = computed(() =>
  [...variants.value].sort(
    (a, b) => (b.isDefault ? 1 : 0) - (a.isDefault ? 1 : 0) || (a.sku ?? '').localeCompare(b.sku ?? '')
  )
)

async function load(iri: string | null) {
  if (!iri) {
    variants.value = []
    return
  }
  try {
    const response = await apiPlatform.client.get('/api/product_variants', {
      params: { product: iri, itemsPerPage: 100 }
    })
    const items = response.data?.member ?? response.data?.['hydra:member'] ?? []
    variants.value = items.map((v: any) => ({
      id: Number(v.id),
      sku: v.sku,
      isDefault: !!v.isDefault
    }))
  } catch (error) {
    console.error('Failed to load product variants:', error)
    variants.value = []
  }
}

watch(() => props.productIri, (iri) => load(iri), { immediate: true })
</script>