import { inject, provide, ref, watch, type Ref } from 'vue'
import { useI18n } from 'vue-i18n'

/**
 * Shared edit-locale for a Product/Variant form. Lets the user switch the locale
 * used to persist localizable attribute values without changing the UI locale.
 *
 * The choice is persisted in localStorage so it survives save/refresh and is
 * shared across product / variant edit pages — users typically work in one
 * language at a time, regardless of their UI locale.
 *
 * - `provideFormLocale()` exposes a ref at the page root.
 * - `useFormLocale()` injects it from any descendant; falls back to a local ref
 *   tied to the user's current UI locale when no provider exists.
 */
const KEY = Symbol('formLocale')
const STORAGE_KEY = 'form_locale'

function readStored(): string | null {
  try {
    const v = localStorage.getItem(STORAGE_KEY)
    return v && v.length > 0 ? v : null
  } catch {
    return null
  }
}

function writeStored(value: string): void {
  try {
    localStorage.setItem(STORAGE_KEY, value)
  } catch {
    /* private mode / quota — ignore */
  }
}

export function provideFormLocale(initial?: string): Ref<string> {
  const { locale } = useI18n()
  // Priority: persisted choice > caller-provided initial > UI locale.
  const state = ref<string>(readStored() ?? initial ?? locale.value)
  watch(state, (v) => writeStored(v))
  provide(KEY, state)
  return state
}

export function useFormLocale(): Ref<string> {
  const injected = inject<Ref<string> | null>(KEY, null)
  if (injected) return injected
  // No provider: fall back to read-only-ish ref initialized from storage or i18n.
  const { locale } = useI18n()
  return ref(readStored() ?? locale.value)
}
