<template>
  <v-container fluid class="fill-height bg-grey-lighten-4">
    <v-row align="center" justify="center">
      <v-col cols="12" sm="8" md="4">
        <v-card class="elevation-12">
          <v-toolbar color="primary" dark flat>
            <v-toolbar-title>{{ t('auth.login') }}</v-toolbar-title>
          </v-toolbar>

          <v-card-text>
            <v-form @submit.prevent="handleLogin" ref="formRef">
              <v-text-field
                v-model="email"
                :label="t('auth.email')"
                prepend-icon="mdi-email"
                type="email"
                :rules="[rules.required, rules.email]"
                :error-messages="authStore.error ? '' : undefined"
                autocomplete="email"
                autofocus
              />

              <v-text-field
                v-model="password"
                :label="t('auth.password')"
                prepend-icon="mdi-lock"
                :type="showPassword ? 'text' : 'password'"
                :append-inner-icon="showPassword ? 'mdi-eye-off' : 'mdi-eye'"
                @click:append-inner="showPassword = !showPassword"
                :rules="[rules.required]"
                autocomplete="current-password"
                @keyup.enter="handleLogin"
              />

              <v-alert
                v-if="authStore.error"
                type="error"
                variant="tonal"
                density="compact"
                class="mt-4"
              >
                {{ authStore.error }}
              </v-alert>
            </v-form>
          </v-card-text>

          <v-card-actions>
            <v-spacer />
            <v-btn
              color="primary"
              variant="elevated"
              :loading="authStore.loading"
              :disabled="!isValid"
              @click="handleLogin"
            >
              {{ t('auth.signIn') }}
            </v-btn>
          </v-card-actions>
        </v-card>
      </v-col>
    </v-row>
  </v-container>
</template>

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
