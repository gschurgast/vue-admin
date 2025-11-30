<template>
  <v-dialog v-model="dialog" max-width="600px">
    <v-card>
      <v-card-text class="pa-0">
        <v-text-field
          v-model="search"
          ref="searchInput"
          placeholder="Search resources..."
          prepend-inner-icon="mdi-magnify"
          variant="solo"
          flat
          hide-details
          autofocus
          @keydown.down.prevent="selectNext"
          @keydown.up.prevent="selectPrev"
          @keydown.enter.prevent="navigateToSelected"
          @keydown.esc="close"
        />
        
        <v-divider />
        
        <v-list v-if="filteredResources.length > 0" class="py-0">
          <v-list-item
            v-for="(resource, index) in filteredResources"
            :key="resource.name"
            :class="{ 'bg-grey-lighten-3': index === selectedIndex }"
            @click="navigateTo(resource)"
            @mouseenter="selectedIndex = index"
          >
            <template v-slot:prepend>
              <v-icon>mdi-database</v-icon>
            </template>
            <v-list-item-title>{{ resource.title }}</v-list-item-title>
            <v-list-item-subtitle>{{ resource.name }}</v-list-item-subtitle>
          </v-list-item>
        </v-list>
        
        <div v-else class="pa-4 text-center text-grey">
          No resources found
        </div>
      </v-card-text>
      
      <v-divider />
      
      <v-card-actions class="px-4 py-2">
        <v-chip size="x-small" variant="outlined">
          <v-icon start size="x-small">mdi-arrow-up-down</v-icon>
          Navigate
        </v-chip>
        <v-chip size="x-small" variant="outlined">
          <v-icon start size="x-small">mdi-keyboard-return</v-icon>
          Select
        </v-chip>
        <v-chip size="x-small" variant="outlined">
          <v-icon start size="x-small">mdi-keyboard-esc</v-icon>
          Close
        </v-chip>
      </v-card-actions>
    </v-card>
  </v-dialog>
</template>

<script setup lang="ts">
import { ref, computed, watch, onMounted, onUnmounted } from 'vue'
import { useRouter } from 'vue-router'
import { useResourcesStore } from '@/stores/resources'

const router = useRouter()
const resourcesStore = useResourcesStore()

const dialog = ref(false)
const search = ref('')
const selectedIndex = ref(0)
const searchInput = ref(null)

const resources = computed(() => resourcesStore.resources)

const filteredResources = computed(() => {
  if (!search.value) return resources.value
  
  const query = search.value.toLowerCase()
  return resources.value.filter(resource => 
    resource.title.toLowerCase().includes(query) ||
    resource.name.toLowerCase().includes(query)
  )
})

// Reset selection when search changes
watch(search, () => {
  selectedIndex.value = 0
})

// Reset when dialog opens
watch(dialog, (newValue) => {
  if (newValue) {
    search.value = ''
    selectedIndex.value = 0
  }
})

function selectNext() {
  if (selectedIndex.value < filteredResources.value.length - 1) {
    selectedIndex.value++
  }
}

function selectPrev() {
  if (selectedIndex.value > 0) {
    selectedIndex.value--
  }
}

function navigateToSelected() {
  if (filteredResources.value.length > 0) {
    navigateTo(filteredResources.value[selectedIndex.value])
  }
}

function navigateTo(resource: { name: string }) {
  router.push(`/resource/${resource.name}`)
  close()
}

function close() {
  dialog.value = false
}

function open() {
  dialog.value = true
}

// Keyboard shortcut handler
function handleKeydown(event: KeyboardEvent) {
  // Check for Cmd+K (Mac) or Ctrl+K (Windows/Linux)
  if ((event.metaKey || event.ctrlKey) && event.key === 'k') {
    event.preventDefault()
    open()
  }
}

onMounted(() => {
  window.addEventListener('keydown', handleKeydown)
})

onUnmounted(() => {
  window.removeEventListener('keydown', handleKeydown)
})

// Expose open method for parent component
defineExpose({ open })
</script>
