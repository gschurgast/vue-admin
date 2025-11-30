import { createI18n } from 'vue-i18n'
import { nextTick } from 'vue'
import enMessages from '../locales/en.json'

// Simple deep merge implementation to avoid external dependency
function deepMerge(target: any, source: any) {
    if (typeof target !== 'object' || target === null) {
        return source
    }
    if (typeof source !== 'object' || source === null) {
        return target
    }

    const output = { ...target }

    Object.keys(source).forEach(key => {
        if (source[key] && typeof source[key] === 'object' && !Array.isArray(source[key])) {
            if (!(key in target)) {
                Object.assign(output, { [key]: source[key] })
            } else {
                output[key] = deepMerge(target[key], source[key])
            }
        } else {
            Object.assign(output, { [key]: source[key] })
        }
    })

    return output
}

// 1. Load top-level json files for lazy loading
const localeFiles = import.meta.glob('../locales/*.json')

// 2. Lazy load resource-specific files
const resourceLocaleFiles = import.meta.glob('../locales/*/*.json')

const i18n = createI18n({
    legacy: false,
    locale: localStorage.getItem('locale') || 'en',
    fallbackLocale: 'en',
    messages: {
        en: enMessages // Load English eagerly as default
    },
})

// Helper to load the main locale file (e.g., fr.json)
export async function loadLocaleMessages(locale: string) {
    // English is already loaded eagerly, skip it
    if (locale === 'en') {
        i18n.global.locale.value = locale
        localStorage.setItem('locale', locale)
        document.documentElement.dir = 'ltr'
        document.documentElement.lang = locale
        return nextTick()
    }

    // Check if already loaded
    if (i18n.global.availableLocales.includes(locale as any) && (i18n.global.messages.value as any)[locale]) {
        i18n.global.locale.value = locale as any
        localStorage.setItem('locale', locale)
        const isRtl = ['ar', 'he'].includes(locale)
        document.documentElement.dir = isRtl ? 'rtl' : 'ltr'
        document.documentElement.lang = locale
        return nextTick()
    }

    const path = `../locales/${locale}.json`
    if (localeFiles[path]) {
        const messages = await localeFiles[path]()
        i18n.global.setLocaleMessage(locale as any, (messages as any).default)
    }

    // Set the locale
    i18n.global.locale.value = locale as any
    localStorage.setItem('locale', locale)

    // Handle RTL
    const isRtl = ['ar', 'he'].includes(locale)
    document.documentElement.dir = isRtl ? 'rtl' : 'ltr'
    document.documentElement.lang = locale

    return nextTick()
}

// Helper to load resource-specific messages (e.g., locales/Book/fr.json)
export async function loadResourceMessages(resource: string, locale: string) {
    const promises: Promise<void>[] = []

    // Capitalize first letter to match directory structure (Book, Author)
    const capitalizedResource = resource.charAt(0).toUpperCase() + resource.slice(1)

    // Always load English as fallback first (if not already the current locale)
    if (locale !== 'en') {
        const enPath = `../locales/${capitalizedResource}/en.json`
        if (resourceLocaleFiles[enPath]) {
            promises.push(
                resourceLocaleFiles[enPath]().then((mod: any) => {
                    i18n.global.mergeLocaleMessage('en', mod.default)
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

    if (currentLocale !== 'en') {
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
