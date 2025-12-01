<template>
  <div>
    <v-row>
      <!-- Profile Picture Section -->
      <v-col cols="12" md="4" class="text-center">
        <div class="mb-4">
          <v-avatar size="150" class="elevation-4">
            <v-img
              v-if="picturePreview || currentPicture"
              :src="picturePreview || fullPictureUrl"
              cover
            />
            <v-icon v-else size="80" color="grey-lighten-1">mdi-account</v-icon>
          </v-avatar>
        </div>

        <input
          ref="fileInput"
          type="file"
          accept="image/jpeg,image/png,image/gif,image/webp"
          style="display: none"
          @change="handleFileSelect"
        />

        <v-btn
          color="primary"
          variant="outlined"
          size="small"
          class="mr-2"
          @click="fileInput?.click()"
          :loading="uploadingPicture"
        >
          <v-icon start>mdi-camera</v-icon>
          {{ t('account.changePicture') }}
        </v-btn>

        <v-btn
          v-if="currentPicture || picturePreview"
          color="error"
          variant="text"
          size="small"
          @click="handleDeletePicture"
        >
          <v-icon>mdi-delete</v-icon>
        </v-btn>

        <v-alert
          v-if="pictureError"
          type="error"
          variant="tonal"
          density="compact"
          class="mt-3"
          closable
          @click:close="pictureError = null"
        >
          {{ pictureError }}
        </v-alert>
      </v-col>

      <!-- Profile Form Section -->
      <v-col cols="12" md="8">
        <ResourceForm
          v-model="localFormData"
          :fields="filteredFields"
          :custom-components="customComponents"
          :relation-data="relationData"
          :loading-relations="loadingRelations"
          :field-errors="fieldErrors"
        />

        <!-- Password Change Section (only for current user) -->
        <v-divider v-if="isCurrentUser" class="my-4" />

        <div v-if="isCurrentUser">
          <h4 class="text-subtitle-1 mb-3">{{ t('account.changePassword') }}</h4>

          <v-text-field
            v-model="newPassword"
            :label="t('account.newPassword')"
            :type="showNewPassword ? 'text' : 'password'"
            :append-inner-icon="showNewPassword ? 'mdi-eye-off' : 'mdi-eye'"
            @click:append-inner="showNewPassword = !showNewPassword"
            prepend-icon="mdi-lock"
            variant="outlined"
            density="comfortable"
            class="mb-3"
            :rules="newPassword ? [rules.minLength] : []"
            autocomplete="new-password"
          />

          <v-text-field
            v-model="confirmPassword"
            :label="t('account.confirmPassword')"
            :type="showConfirmPassword ? 'text' : 'password'"
            :append-inner-icon="showConfirmPassword ? 'mdi-eye-off' : 'mdi-eye'"
            @click:append-inner="showConfirmPassword = !showConfirmPassword"
            prepend-icon="mdi-lock-check"
            variant="outlined"
            density="comfortable"
            :rules="confirmPassword ? [rules.passwordMatch] : []"
            :error-messages="passwordMismatchError"
            autocomplete="new-password"
          />
        </div>
      </v-col>
    </v-row>
  </div>
</template>

<script setup lang="ts">
import { ref, computed, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import ResourceForm from '../../resource/ResourceForm.vue'
import apiPlatform from '../../../services/apiPlatform'
import { useAuthStore } from '../../../stores/auth'

interface Props {
  formData: Record<string, any>
  fields: Array<any>
  customComponents?: Record<string, any>
  relationData?: Record<string, any>
  loadingRelations?: Record<string, boolean>
  fieldErrors?: Record<string, string[]>
  resourceName?: string
}

const props = withDefaults(defineProps<Props>(), {
  customComponents: () => ({}),
  relationData: () => ({}),
  loadingRelations: () => ({}),
  fieldErrors: () => ({}),
  resourceName: ''
})

const emit = defineEmits<{
  'update:formData': [value: Record<string, any>]
}>()

const { t } = useI18n()
const authStore = useAuthStore()

const localFormData = ref({ ...props.formData })

// Check if we're editing the current user
const isCurrentUser = computed(() => {
  return authStore.user?.id === localFormData.value.id
})
const fileInput = ref<HTMLInputElement | null>(null)
const uploadingPicture = ref(false)
const picturePreview = ref<string | null>(null)
const pictureError = ref<string | null>(null)
const pendingFile = ref<File | null>(null)

// Password fields
const newPassword = ref('')
const confirmPassword = ref('')
const showNewPassword = ref(false)
const showConfirmPassword = ref(false)

const currentPicture = computed(() => localFormData.value.picture)
const userId = computed(() => localFormData.value.id)

const rules = {
  minLength: (v: string) => v.length >= 6 || t('account.passwordMinLength'),
  passwordMatch: () => newPassword.value === confirmPassword.value || t('account.passwordMismatch'),
}

const passwordMismatchError = computed(() => {
  if (confirmPassword.value && newPassword.value !== confirmPassword.value) {
    return t('account.passwordMismatch')
  }
  return ''
})

const fullPictureUrl = computed(() => {
  if (!currentPicture.value) return null
  const baseUrl = import.meta.env.VITE_API_URL || 'http://localhost:8080'
  return `${baseUrl}${currentPicture.value}`
})

// Filter out the picture field from ResourceForm (we handle it separately)
const filteredFields = computed(() => {
  return props.fields.filter(f => f.name !== 'picture')
})

// Watch for external changes
watch(() => props.formData, (newValue) => {
  if (JSON.stringify(newValue) !== JSON.stringify(localFormData.value)) {
    localFormData.value = { ...newValue }
    picturePreview.value = null
    pendingFile.value = null
  }
}, { deep: true })

// Emit changes
watch(localFormData, (newValue) => {
  if (JSON.stringify(newValue) !== JSON.stringify(props.formData)) {
    emit('update:formData', { ...newValue })
  }
}, { deep: true })

// Watch password changes and add to formData
watch([newPassword, confirmPassword], () => {
  if (newPassword.value && newPassword.value === confirmPassword.value && newPassword.value.length >= 6) {
    localFormData.value = {
      ...localFormData.value,
      plainPassword: newPassword.value
    }
  } else {
    // Remove plainPassword if not valid
    const { plainPassword, ...rest } = localFormData.value
    if (plainPassword !== undefined) {
      localFormData.value = rest
    }
  }
})

function handleFileSelect(event: Event) {
  const target = event.target as HTMLInputElement
  const file = target.files?.[0]

  if (!file) return

  pictureError.value = null

  // Validate file size (max 5MB)
  if (file.size > 5 * 1024 * 1024) {
    pictureError.value = t('account.fileTooLarge')
    return
  }

  // Validate file type
  const allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp']
  if (!allowedTypes.includes(file.type)) {
    pictureError.value = 'Invalid file type. Allowed: JPEG, PNG, GIF, WebP'
    return
  }

  // Store for upload and create preview
  pendingFile.value = file
  const reader = new FileReader()
  reader.onload = (e) => {
    picturePreview.value = e.target?.result as string
  }
  reader.readAsDataURL(file)

  // Upload immediately
  uploadPicture(file)

  // Reset file input
  if (fileInput.value) {
    fileInput.value.value = ''
  }
}

async function uploadPicture(file: File) {
  if (!userId.value) return

  uploadingPicture.value = true
  pictureError.value = null

  try {
    const result = await apiPlatform.uploadUserPicture(userId.value, file)
    localFormData.value = {
      ...localFormData.value,
      picture: result.picture
    }
    picturePreview.value = null
    pendingFile.value = null

    // Update auth store if editing current user
    if (isCurrentUser.value) {
      await authStore.fetchProfile()
    }
  } catch (e: any) {
    pictureError.value = e.response?.data?.error || 'Failed to upload picture'
    picturePreview.value = null
    pendingFile.value = null
  } finally {
    uploadingPicture.value = false
  }
}

async function handleDeletePicture() {
  if (!userId.value) return

  pictureError.value = null

  try {
    await apiPlatform.deleteUserPicture(userId.value)
    localFormData.value = {
      ...localFormData.value,
      picture: null
    }
    picturePreview.value = null
    pendingFile.value = null

    // Update auth store if editing current user
    if (isCurrentUser.value) {
      await authStore.fetchProfile()
    }
  } catch (e: any) {
    pictureError.value = e.response?.data?.error || 'Failed to delete picture'
  }
}
</script>
