<template>
  <v-container fluid class="fill-height login-bg pa-0">
    <v-row align="center" justify="center" no-gutters class="w-100">
      <v-col cols="12" sm="8" md="5" lg="4" xl="3" class="pa-6">
        <div class="d-flex justify-center mb-6">
          <v-avatar size="48" color="primary">
            <v-icon size="28" color="white">mdi-flash</v-icon>
          </v-avatar>
          <span class="brand-title align-self-center ml-3">You-Pim</span>
        </div>

        <v-card class="pa-6">
          <h2 class="text-h5 font-weight-semibold text-center mb-1">
            {{ t('auth.welcomeBack') }}
          </h2>
          <p class="text-body-2 text-medium-emphasis text-center mb-6">
            {{ t('auth.signInToContinue') }}
          </p>

          <v-form ref="formRef" @submit.prevent="handleLogin">
            <label class="text-body-2 font-weight-medium d-block mb-1" for="login-email">
              {{ t('auth.email') }}
            </label>
            <v-text-field
              id="login-email"
              v-model="email"
              type="email"
              :rules="[rules.required, rules.email]"
              autocomplete="email"
              autofocus
              class="mb-2"
            />

            <label class="text-body-2 font-weight-medium d-block mb-1" for="login-password">
              {{ t('auth.password') }}
            </label>
            <v-text-field
              id="login-password"
              v-model="password"
              :type="showPassword ? 'text' : 'password'"
              :append-inner-icon="showPassword ? 'mdi-eye-off' : 'mdi-eye'"
              :rules="[rules.required]"
              autocomplete="current-password"
              @click:append-inner="showPassword = !showPassword"
              @keyup.enter="handleLogin"
            />

            <v-alert
              v-if="authStore.error"
              type="error"
              variant="tonal"
              density="compact"
              class="mt-2 mb-4"
            >
              {{ authStore.error }}
            </v-alert>

            <v-btn
              block
              color="primary"
              variant="flat"
              size="large"
              :loading="authStore.loading"
              :disabled="!isValid"
              class="mt-2"
              @click="handleLogin"
            >
              {{ t('auth.signIn') }}
            </v-btn>
          </v-form>
        </v-card>
      </v-col>
    </v-row>
  </v-container>
</template>

<style scoped>
.login-bg {
  background-color: rgb(var(--v-theme-background));
  min-height: 100vh;
}
.brand-title {
  font-size: 1.5rem;
  font-weight: 600;
  color: rgb(var(--v-theme-on-surface));
}
</style>

<script setup lang="ts">
import { ref, computed } from 'vue'
import { useRouter, useRoute } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { useAuthStore } from '../../stores/auth'
import { useResourcesStore } from '../../stores/resources'

const { t } = useI18n()
const router = useRouter()
const route = useRoute()
const authStore = useAuthStore()
const resourcesStore = useResourcesStore()

const email = ref('')
const password = ref('')
const showPassword = ref(false)
const formRef = ref()

const rules = {
  required: (v: string) => !!v || t('field.required'),
  email: (v: string) => /.+@.+\..+/.test(v) || t('auth.invalidEmail'),
}

const isValid = computed(() => {
  return email.value && password.value && /.+@.+\..+/.test(email.value)
})

async function handleLogin() {
  if (!isValid.value) return

  const success = await authStore.login(email.value, password.value)
  if (success) {
    // Load resources before navigating to ensure menu is populated
    await resourcesStore.loadResources(true)
    const redirect = route.query.redirect as string
    router.push(redirect || '/')
  }
}
</script>
