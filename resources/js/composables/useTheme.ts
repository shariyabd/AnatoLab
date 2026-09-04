import { ref, readonly } from 'vue'

const STORAGE_KEY = 'anatolab.theme'

type Theme = 'light' | 'dark'

/**
 * The class on <html> is the source of truth, not this ref.
 *
 * app.blade.php sets that class before first paint to avoid a flash of the
 * wrong theme, so by the time Vue mounts the decision is already made. Reading
 * it back rather than re-deriving it keeps the two from disagreeing.
 */
const current = ref<Theme>(
  typeof document !== 'undefined' && document.documentElement.classList.contains('dark')
    ? 'dark'
    : 'light',
)

export function useTheme() {
  function apply(theme: Theme): void {
    current.value = theme
    document.documentElement.classList.toggle('dark', theme === 'dark')

    try {
      localStorage.setItem(STORAGE_KEY, theme)
    } catch {
      // Private browsing or blocked storage. The theme still applies for this
      // page view; it just will not be remembered.
    }
  }

  function toggle(): void {
    apply(current.value === 'dark' ? 'light' : 'dark')
  }

  return { theme: readonly(current), toggle, apply }
}
