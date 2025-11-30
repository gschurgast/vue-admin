<script setup lang="ts">
import {useI18n} from 'vue-i18n'

import {loadLocaleMessages} from '@/plugins/i18n'


const {locale} = useI18n()

const availableLocales = [
 {code: 'en', label: 'English', flag: '🇺🇸'},
 {code: 'fr', label: 'Français', flag: '🇫🇷'},
 {code: 'de', label: 'Deutsch', flag: '🇩🇪'},
 {code: 'es', label: 'Español', flag: '🇪🇸'},
 {code: 'it', label: 'Italiano', flag: '🇮🇹'},
 {code: 'pt', label: 'Português', flag: '🇵🇹'},
 {code: 'da', label: 'Dansk', flag: '🇩🇰'},
 {code: 'no', label: 'Norsk', flag: '🇳🇴'},
 {code: 'sv', label: 'Svenska', flag: '🇸🇪'},
 {code: 'pl', label: 'Polski', flag: '🇵🇱'},
 {code: 'zh', label: '中文', flag: '🇨🇳'},
 {code: 'ja', label: '日本語', flag: '🇯🇵'},
 {code: 'ar', label: 'العربية', flag: '🇸🇦'},
 {code: 'he', label: 'עברית', flag: '🇮🇱'}
]


const props = defineProps({

 rail: Boolean,
})


async function changeLocale(newLocale: string) {
 await loadLocaleMessages(newLocale)
}
</script>

<template>
 <v-menu>
  <template v-slot:activator="{ props }">
   <v-btn icon :size="rail ? 24 : 40" v-bind="props">
    <span :class="rail ? 'text-h7' : 'text-h5'">{{ availableLocales.find(l => l.code === locale)?.flag || '🌐' }}</span>
   </v-btn>
  </template>
  <v-card elevation="8" max-width="380" color="grey-lighten-5" >
   <v-row no-gutters>
    <v-col
        v-for="lang in availableLocales"
        :key="lang.code"
        cols="6" sm="4"
        color="grey-lighten-4"
        @click="changeLocale(lang.code)"
    >
     <v-sheet class="pa-1" color="transparent">
      <v-btn
          block
          class="d-flex flex-column align-center"
          @click="changeLocale(lang.code)"
      >
       <span>{{ lang.flag }}</span>
       <span class="hidden sm:block">{{ lang.label }}</span>
      </v-btn>
     </v-sheet>
    </v-col>
   </v-row>
  </v-card>
 </v-menu>
</template>
