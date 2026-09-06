import { AA_LARGE_TEXT, AA_TEXT } from './contrast'

/**
 * The atelier token inventory — handover 15 Phase 0.
 *
 * Names and roles only. Not one value lives here: theme.css is the source of
 * truth, /design-tokens reads what the browser resolved, and
 * theme.contrast.test.ts reads what was authored. A hex in this file would be a
 * fourth copy of the palette and the one that goes stale first.
 *
 * The inventory is asserted complete against theme.css in palette.test.ts, so a
 * token added to the stylesheet without a role written down here fails the
 * build rather than quietly missing from the review surface.
 */

export interface ColorToken {
  readonly name: string
  readonly role: string
}

export interface ColorGroup {
  readonly title: string
  readonly note: string
  readonly tokens: readonly ColorToken[]
}

export const ATELIER_COLOR_GROUPS: readonly ColorGroup[] = [
  {
    title: 'Paper',
    note: 'Three grounds. The product sits on warm off-white; pure grey never appears.',
    tokens: [
      { name: 'color-paper', role: 'The page itself' },
      { name: 'color-surface', role: 'Cards, panels, the tool rail' },
      { name: 'color-surface-sunk', role: 'Search field, tinted callout cards' },
    ],
  },
  {
    title: 'Ink',
    note: 'Warm near-black and two steps down from it. Never #000, never blue-grey.',
    tokens: [
      { name: 'color-ink', role: 'Headings, organ names, values' },
      { name: 'color-ink-soft', role: 'Descriptive prose, key-fact labels' },
      { name: 'color-ink-muted', role: 'Tagline, placeholder, body-system caption' },
      { name: 'color-hairline', role: 'Decorative rules between rows — not a control boundary' },
      { name: 'color-hairline-strong', role: 'The visible edge of an outline control' },
    ],
  },
  {
    title: 'Accent',
    note: 'Coral. Used as a fill and as a tint; used as text only through accent-ink.',
    tokens: [
      { name: 'color-accent', role: 'Primary CTA fill, active row border' },
      { name: 'color-accent-soft', role: 'Active nav pill, active library row fill' },
      { name: 'color-accent-ink', role: 'Text and icons on an accent-soft ground' },
    ],
  },
  {
    title: 'Functional',
    note: 'The canvas overlays. Each is fixed by what it marks, not by the palette.',
    tokens: [
      { name: 'color-hotspot', role: 'Resting structure marker' },
      { name: 'color-hotspot-live', role: 'Selected structure marker' },
      { name: 'color-note', role: 'The tip note on the canvas' },
      { name: 'color-note-edge', role: 'That note’s border' },
    ],
  },
]

export interface TypeStep {
  readonly name: string
  readonly token: string
  readonly face: 'display' | 'body' | 'ui'
  readonly spec: string
  readonly role: string
  readonly sample: string
}

export const ATELIER_TYPE_STEPS: readonly TypeStep[] = [
  {
    name: 'Display',
    token: 'text-display',
    face: 'display',
    spec: '44 / 1.1 · Fraunces 600',
    role: 'Organ name, page heading, wordmark',
    sample: 'The Heart',
  },
  {
    name: 'Title',
    token: 'text-title',
    face: 'display',
    spec: '28 / 1.2 · Fraunces 600',
    role: 'Section and card headings',
    sample: 'Left ventricle',
  },
  {
    name: 'Subtitle',
    token: 'text-subtitle',
    face: 'body',
    spec: '18 / 1.4 · Spectral italic',
    role: 'Taglines beneath a name',
    sample: 'The tireless pump',
  },
  {
    name: 'Body',
    token: 'text-body',
    face: 'body',
    spec: '16 / 1.7 · Spectral',
    role: 'Descriptive prose and lesson text',
    sample:
      'The heart is a muscular organ roughly the size of a closed fist, sitting between the lungs and slightly left of the midline.',
  },
  {
    name: 'UI',
    token: 'text-ui',
    face: 'ui',
    spec: '14 / 1.5 · Inter',
    role: 'Navigation, buttons, form controls',
    sample: 'View all organs',
  },
  {
    name: 'Label',
    token: 'text-label',
    face: 'ui',
    spec: '11 / 1.4 · Inter 600, tracking 0.14em, uppercase',
    role: 'The recurring small-caps motif',
    sample: 'Organ library',
  },
]

export interface ContrastPairing {
  readonly foreground: string
  readonly background: string
  readonly threshold: number
  readonly usage: string
}

/**
 * Every pairing the design actually renders, and the threshold each owes.
 *
 * A palette does not owe every combination of its own colours a passing ratio;
 * asserting the ones no screen renders would force a worse palette to satisfy a
 * test. What is here is what a reviewer can point at on a page.
 */
export const ATELIER_CONTRAST_PAIRINGS: readonly ContrastPairing[] = [
  {
    foreground: 'color-ink',
    background: 'color-paper',
    threshold: AA_TEXT,
    usage: 'Page text on the paper ground',
  },
  {
    foreground: 'color-ink',
    background: 'color-surface',
    threshold: AA_TEXT,
    usage: 'Card and panel text',
  },
  {
    foreground: 'color-ink',
    background: 'color-surface-sunk',
    threshold: AA_TEXT,
    usage: 'Text in a sunk field or tinted callout',
  },
  {
    foreground: 'color-ink',
    background: 'color-note',
    threshold: AA_TEXT,
    usage: 'The canvas tip note',
  },
  {
    foreground: 'color-ink',
    background: 'color-accent-soft',
    threshold: AA_TEXT,
    usage: 'Text inside an active row fill',
  },
  {
    foreground: 'color-ink-soft',
    background: 'color-paper',
    threshold: AA_TEXT,
    usage: 'Secondary prose on paper',
  },
  {
    foreground: 'color-ink-soft',
    background: 'color-surface',
    threshold: AA_TEXT,
    usage: 'Secondary prose on a card',
  },
  {
    foreground: 'color-ink-soft',
    background: 'color-surface-sunk',
    threshold: AA_TEXT,
    usage: 'Key-fact labels',
  },
  {
    foreground: 'color-ink-muted',
    background: 'color-paper',
    threshold: AA_TEXT,
    usage: 'The wordmark tagline',
  },
  {
    foreground: 'color-ink-muted',
    background: 'color-surface',
    threshold: AA_TEXT,
    usage: 'The body-system caption in a library row',
  },
  {
    foreground: 'color-ink-muted',
    background: 'color-surface-sunk',
    threshold: AA_TEXT,
    usage: 'The search placeholder',
  },
  {
    foreground: 'color-accent-ink',
    background: 'color-accent-soft',
    threshold: AA_TEXT,
    usage: 'The active navigation pill',
  },
  {
    foreground: 'color-accent-ink',
    background: 'color-paper',
    threshold: AA_TEXT,
    usage: 'An accent link on paper',
  },
  {
    foreground: 'color-accent-ink',
    background: 'color-surface',
    threshold: AA_TEXT,
    usage: 'An accent link on a card',
  },
  /*
   | The filled primary CTA. White on --color-accent measures 3.12:1 and cannot
   | ship, so the pairing pinned here is the one that can — Phase 5 inherits an
   | answer rather than a button to redo at the accessibility audit.
   */
  {
    foreground: 'color-ink',
    background: 'color-accent',
    threshold: AA_TEXT,
    usage: 'The label on a filled accent CTA',
  },
  /*
   | SC 1.4.11, non-text. --color-hairline is a decorative rule at 1.21:1 and is
   | deliberately absent: a control whose only visible boundary is a hairline
   | uses --color-hairline-strong instead.
   */
  {
    foreground: 'color-hairline-strong',
    background: 'color-paper',
    threshold: AA_LARGE_TEXT,
    usage: 'An outline button on paper',
  },
  {
    foreground: 'color-hairline-strong',
    background: 'color-surface',
    threshold: AA_LARGE_TEXT,
    usage: 'An outline button on a card',
  },
  {
    foreground: 'color-hairline-strong',
    background: 'color-surface-sunk',
    threshold: AA_LARGE_TEXT,
    usage: 'The search pill boundary',
  },
  /*
   | Markers are measured against --color-surface, not against paper or tissue:
   | Phase 4 gives every marker a 2px white ring, so white is what the dot is
   | adjacent to. That ring is why a marker reads on both pale and dark tissue,
   | and it is why the ring is not optional.
   */
  {
    foreground: 'color-hotspot',
    background: 'color-surface',
    threshold: AA_LARGE_TEXT,
    usage: 'A resting hotspot inside its white ring',
  },
  {
    foreground: 'color-hotspot-live',
    background: 'color-surface',
    threshold: AA_LARGE_TEXT,
    usage: 'An active hotspot inside its white ring',
  },
]
