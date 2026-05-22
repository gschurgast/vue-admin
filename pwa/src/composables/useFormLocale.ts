import { inject, provide, ref, type Ref } from 'vue'
import { useI18n } from 'vue-i18n'

/**
 * Shared edit-locale for a Product/Variant form. Lets the user switch the locale
 * used to persist localizable attribute values without changing the UI locale.
 *
 * - `provideFormLocale()` exposes a ref at the page root.
 * - `useFormLocale()` injects it from any descendant; falls back to a local ref
 *   tied to the user's current UI locale when no provider exists.
 */
const KEY = Symbol('formLocale')

export function provideFormLocale(initial?: string): Ref<string> {
  const { locale } = useI18n()
  const state = ref<string>(initial ?? locale.value)
  provide(KEY, state)
  return state
}

export function useFormLocale(): Ref<string> {
  const injected = inject<Ref<string> | null>(KEY, null)
  if (injected) return injected
  // No provider: fall back to read-only-ish ref initialized from i18n.
  const { locale } = useI18n()
  return ref(locale.value)
}
