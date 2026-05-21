import { computed } from 'vue'
import { useTheme } from 'vuetify'

const STORAGE_KEY = 'youpim_theme'
const LIGHT = 'youPimTheme'
const DARK = 'youPimDark'

export function useThemeMode() {
  const theme = useTheme()

  const stored = localStorage.getItem(STORAGE_KEY)
  if (stored === LIGHT || stored === DARK) {
    theme.global.name.value = stored
  }

  const isDark = computed(() => theme.global.name.value === DARK)

  function toggle() {
    const next = isDark.value ? LIGHT : DARK
    theme.global.name.value = next
    localStorage.setItem(STORAGE_KEY, next)
  }

  function set(mode: 'light' | 'dark') {
    const next = mode === 'dark' ? DARK : LIGHT
    theme.global.name.value = next
    localStorage.setItem(STORAGE_KEY, next)
  }

  return { isDark, toggle, set }
}
