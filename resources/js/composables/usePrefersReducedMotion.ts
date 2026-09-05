import { onScopeDispose, readonly, ref } from 'vue'

const QUERY = '(prefers-reduced-motion: reduce)'

/**
 * The OS motion preference, as a reactive boolean.
 *
 * CSS already honours it globally (resources/css/app.css), but CSS cannot
 * reach a requestAnimationFrame camera tween — the viewer takes the answer as
 * a `ViewerOptions` flag instead, and the tool rail needs it to explain why
 * auto-rotate is unavailable rather than offering a button that does nothing
 * (docs/architecture.md §5.2).
 *
 * Reactive rather than read once: the preference can change mid-session, and a
 * viewer that keeps spinning after the user asks it to stop is the bug this is
 * here to prevent.
 */
export function usePrefersReducedMotion() {
  const prefersReducedMotion = ref(false)

  if (typeof window !== 'undefined' && typeof window.matchMedia === 'function') {
    const media = window.matchMedia(QUERY)
    prefersReducedMotion.value = media.matches

    const onChange = (event: MediaQueryListEvent): void => {
      prefersReducedMotion.value = event.matches
    }

    media.addEventListener('change', onChange)
    onScopeDispose(() => media.removeEventListener('change', onChange))
  }

  return readonly(prefersReducedMotion)
}
