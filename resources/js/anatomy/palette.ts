/**
 * Every colour the viewer draws that is not read from organ data.
 *
 * The viewer is a standalone library: it imports no framework, reads no
 * stylesheet, and never touches the DOM's computed styles (invariant 3,
 * docs/architecture.md §5.4 rule 3). It therefore cannot resolve a CSS custom
 * property, and handover 15's acceptance criterion 2 — "every visual value in
 * the codebase resolves to a token" — has to be met a different way here than
 * it is in Vue.
 *
 * The way is the one the repository already uses for FIT_SIZE: mirror the value
 * and assert the mirror. `palette.test.ts` parses `resources/css/theme.css` and
 * fails the build if any constant below drifts from the token it names, exactly
 * as `fitSizeParity.test.ts` does against `config/anatomy.php`. A hex here is
 * not a second source of truth; it is a checked copy of the first one.
 */

/** `--color-hotspot`. A structure marker at rest. */
export const MARKER_RESTING = '#e8722e'

/** `--color-hotspot-live`. The selected or highlighted marker. */
export const MARKER_ACTIVE = '#2563eb'

/** `--color-surface`. The ring around every marker, at every state. */
export const MARKER_RING = '#ffffff'

/**
 * `--color-ink`. Every shadow the viewer draws itself — the pool the organ
 * stands on, and the drop shadow under a marker.
 *
 * Warm, not neutral black, which is the atelier palette's rule for a shadow
 * anywhere. The canvas is transparent over a warm-white card, so a neutral
 * shadow reads as a grey lens laid over the paper — which is what the hard
 * plinth this replaced actually looked like.
 */
export const SHADOW_INK = '#2a2320'

/**
 * Quiz feedback, and the two colours here that theme.css does **not** declare.
 *
 * The atelier palette has no success or danger token — nothing outside the
 * canvas signals correctness, so adding a pair to `theme.css` would be adding
 * tokens for one consumer, and `theme.css` belongs to the F05 lane in any case.
 * They stay local and named rather than as bare hex literals at the point of
 * use. If a correctness colour is ever needed in Vue, the tokens go in
 * theme.css first and these become mirrors like the four above.
 */
export const MARKER_FLASH_CORRECT = '#35c46a'
export const MARKER_FLASH_WRONG = '#e2564a'
