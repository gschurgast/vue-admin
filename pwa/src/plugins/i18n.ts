import { createI18n } from 'vue-i18n'
import { nextTick } from 'vue'
import enMessages from '../locales/en_US.json'

// Lazy load locale files
const localeFiles = import.meta.glob('../locales/*.json')
const resourceLocaleFiles = import.meta.glob('../locales/*/*.json')

const RTL_LOCALES = ['ar_SA', 'he_IL']

const i18n = createI18n({
    legacy: false,
    locale: localStorage.getItem('locale') || 'en_US',
    fallbackLocale: 'en_US',
    messages: {
        en_US: enMessages
    },
})

// Apply locale settings to document and localStorage
function applyLocale(locale: string) {
    i18n.global.locale.value = locale as any
    localStorage.setItem('locale', locale)
    document.documentElement.dir = RTL_LOCALES.includes(locale) ? 'rtl' : 'ltr'
    document.documentElement.lang = locale.split('_')[0]
}

// Helper to load the main locale file (e.g., fr_FR.json)
export async function loadLocaleMessages(locale: string) {
    // Load messages if not already loaded (en_US is eagerly loaded)
    if (locale !== 'en_US' && !i18n.global.availableLocales.includes(locale as any)) {
        const path = `../locales/${locale}.json`
        if (localeFiles[path]) {
            const messages = await localeFiles[path]()
            i18n.global.setLocaleMessage(locale as any, (messages as any).default)
        }
    }

    applyLocale(locale)
    return nextTick()
}

// Helper to load resource-specific messages (e.g., locales/Book/fr_FR.json)
export async function loadResourceMessages(resource: string, locale: string) {
    const promises: Promise<void>[] = []

    // Capitalize first letter to match directory structure (Book, Author)
    const capitalizedResource = resource.charAt(0).toUpperCase() + resource.slice(1)

    // Always load English as fallback first (if not already the current locale)
    if (locale !== 'en_US') {
        const enPath = `../locales/${capitalizedResource}/en_US.json`
        if (resourceLocaleFiles[enPath]) {
            promises.push(
                resourceLocaleFiles[enPath]().then((mod: any) => {
                    i18n.global.mergeLocaleMessage('en_US', mod.default)
                })
            )
        }
    }

    // Then load the requested locale
    const path = `../locales/${capitalizedResource}/${locale}.json`

    if (resourceLocaleFiles[path]) {

        promises.push(
            resourceLocaleFiles[path]().then((mod: any) => {
                i18n.global.mergeLocaleMessage(locale as any, mod.default)
            })
        )
    } else {
        console.warn('[i18n] Not found:', path)
    }

    await Promise.all(promises)
}

// Initial load function to be called before app mount
export async function initI18n() {
    const currentLocale = i18n.global.locale.value
    const promises = []

    if (currentLocale !== 'en_US') {
        // Load global locale messages
        promises.push(loadLocaleMessages(currentLocale))
    }

    // Preload the last visited resource translations to avoid warnings on refresh
    const lastResource = localStorage.getItem('last_resource')
    if (lastResource) {
        promises.push(loadResourceMessages(lastResource, currentLocale))
    }

    await Promise.all(promises)
}

export default i18n
