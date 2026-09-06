<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { Head } from '@inertiajs/vue3'
import SectionLabel from '@/Components/Atelier/SectionLabel.vue'
import { AA_TEXT, contrastRatioOf } from '@/design/contrast'
import {
  ATELIER_COLOR_GROUPS,
  ATELIER_CONTRAST_PAIRINGS,
  ATELIER_TYPE_STEPS,
} from '@/design/palette'

/**
 * The atelier design tokens — handover 15 Phase 0.
 *
 * The review surface for every later phase of the visual language: every
 * colour, type step, radius, shadow and component state on one page, so a
 * change to theme.css can be looked at rather than imagined.
 *
 * Two things it deliberately does not do:
 *
 * - **It holds no values.** Every swatch, every ratio and every sample reads
 *   what the browser actually resolved for the token. A page that restated the
 *   palette would eventually disagree with it, and it is the page a reviewer
 *   would believe.
 * - **It renders no live data.** No organ, no structure, no request. It is a
 *   specification sheet, which is why it takes no props and needs no login.
 *
 * The buttons and rows below are inert samples. They are real elements rather
 * than pictures of elements so that hover and focus can be reviewed too — the
 * focus ring in particular is load-bearing for the 3D layer's keyboard path
 * (docs/engineering.md §4) and cannot be judged from a screenshot.
 */

const resolved = ref<Record<string, string>>({})

function readResolvedTokens(): void {
  const styles = getComputedStyle(document.documentElement)
  const values: Record<string, string> = {}

  for (const group of ATELIER_COLOR_GROUPS) {
    for (const entry of group.tokens) {
      values[entry.name] = styles.getPropertyValue(`--${entry.name}`).trim()
    }
  }

  resolved.value = values
}

onMounted(readResolvedTokens)

interface ContrastRow {
  readonly usage: string
  readonly foreground: string
  readonly background: string
  readonly threshold: number
  readonly ratio: number | null
  readonly passes: boolean
}

const contrastRows = computed<ContrastRow[]>(() =>
  ATELIER_CONTRAST_PAIRINGS.map((pairing) => {
    const ratio = contrastRatioOf(
      resolved.value[pairing.foreground] ?? '',
      resolved.value[pairing.background] ?? '',
    )

    return {
      usage: pairing.usage,
      foreground: pairing.foreground,
      background: pairing.background,
      threshold: pairing.threshold,
      ratio,
      passes: ratio !== null && ratio >= pairing.threshold,
    }
  }),
)

const failingCount = computed(() => contrastRows.value.filter((row) => !row.passes).length)

const faceClass: Record<string, string> = {
  display: 'font-display',
  body: 'font-body',
  ui: 'font-ui',
}

/** Only the label step is uppercased; the rest are set as written. */
const stepClass: Record<string, string> = {
  'text-display': 'text-display',
  'text-title': 'text-title',
  'text-subtitle': 'text-subtitle italic',
  'text-body': 'text-body',
  'text-ui': 'text-ui',
  'text-label': 'text-label uppercase',
}
</script>

<template>
  <Head title="Design tokens" />

  <!--
    No <main> and no landmark of its own: AppLayout already provides both, and a
    second element carrying id="main-content" would take the skip link to the
    wrong one.
  -->
  <div class="rounded-card bg-[var(--color-paper)] text-[var(--color-ink)]">
    <div class="mx-auto max-w-4xl px-6 py-14">
      <header class="max-w-2xl">
        <SectionLabel tag="p">Atelier visual language</SectionLabel>

        <h1 class="mt-4 font-display text-display">Design tokens</h1>

        <p class="mt-4 font-body text-body text-[var(--color-ink-soft)]">
          Every visual value the application is allowed to use. All of them are declared in
          <code class="font-mono text-[0.9em]">resources/css/theme.css</code>; nothing on this page
          restates one. The swatches and ratios below are read back from the browser, so what you
          are looking at is what the stylesheet resolved.
        </p>
      </header>

      <!-- Typography -->
      <section class="mt-16" aria-labelledby="type-heading">
        <SectionLabel tag="h2" id="type-heading">Type scale</SectionLabel>

        <p class="mt-3 max-w-2xl font-body text-body text-[var(--color-ink-soft)]">
          Three faces with three jobs. Fraunces carries the personality and never sets a control;
          Spectral is for reading and never sets a button; Inter is for anything the eye scans
          rather than reads. All three are self-hosted, latin-subset only, and swap.
        </p>

        <dl class="mt-8 divide-y divide-[var(--color-hairline)]">
          <div
            v-for="step in ATELIER_TYPE_STEPS"
            :key="step.token"
            class="grid gap-4 py-6 sm:grid-cols-[13rem_1fr]"
          >
            <dt>
              <SectionLabel>{{ step.name }}</SectionLabel>
              <p class="mt-1 font-mono text-xs text-[var(--color-ink-muted)]">{{ step.spec }}</p>
              <p class="mt-1 text-ui text-[var(--color-ink-muted)]">{{ step.role }}</p>
            </dt>

            <dd :class="[faceClass[step.face], stepClass[step.token]]">
              {{ step.sample }}
            </dd>
          </div>
        </dl>
      </section>

      <!-- Colour -->
      <section class="mt-16" aria-labelledby="colour-heading">
        <SectionLabel tag="h2" id="colour-heading">Colour</SectionLabel>

        <div v-for="group in ATELIER_COLOR_GROUPS" :key="group.title" class="mt-8">
          <h3 class="font-display text-title">{{ group.title }}</h3>

          <p class="mt-1 max-w-2xl font-body text-body text-[var(--color-ink-soft)]">
            {{ group.note }}
          </p>

          <ul class="mt-5 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            <li
              v-for="entry in group.tokens"
              :key="entry.name"
              class="flex items-center gap-4 rounded-tile bg-[var(--color-surface)] p-3 shadow-card"
            >
              <span
                class="size-12 shrink-0 rounded-tile border border-[var(--color-hairline)]"
                :style="{ backgroundColor: `var(--${entry.name})` }"
                aria-hidden="true"
              />

              <span class="min-w-0">
                <span class="block font-mono text-xs text-[var(--color-ink)]"
                  >--{{ entry.name }}</span
                >
                <span class="block font-mono text-xs uppercase text-[var(--color-ink-muted)]">
                  {{ resolved[entry.name] || '—' }}
                </span>
                <span class="mt-1 block text-ui text-[var(--color-ink-soft)]">{{
                  entry.role
                }}</span>
              </span>
            </li>
          </ul>
        </div>
      </section>

      <!-- Contrast -->
      <section class="mt-16" aria-labelledby="contrast-heading">
        <SectionLabel tag="h2" id="contrast-heading">Contrast</SectionLabel>

        <p class="mt-3 max-w-2xl font-body text-body text-[var(--color-ink-soft)]">
          Measured, not assumed — warm greys on warm paper fail easily. Every pairing the design
          actually renders is listed with the WCAG 2.2 threshold it owes: 4.5:1 for text, 3:1 for a
          control boundary or a marker. The same table is asserted against the stylesheet in
          <code class="font-mono text-[0.9em]">theme.contrast.test.ts</code>, so a value cannot drop
          below its threshold without failing the build.
        </p>

        <p class="mt-4 text-ui" :class="failingCount === 0 ? 'text-[var(--color-ink)]' : ''">
          <strong>{{ contrastRows.length - failingCount }}</strong> of
          <strong>{{ contrastRows.length }}</strong> pairings pass.
        </p>

        <div class="mt-5 overflow-x-auto">
          <table class="w-full border-collapse text-left text-ui">
            <thead>
              <tr class="border-b border-[var(--color-hairline-strong)]">
                <th scope="col" class="py-2 pr-4 font-medium">Where it is used</th>
                <th scope="col" class="py-2 pr-4 font-medium">Pairing</th>
                <th scope="col" class="py-2 pr-4 font-medium">Needs</th>
                <th scope="col" class="py-2 pr-4 font-medium">Measured</th>
                <th scope="col" class="py-2 font-medium">Sample</th>
              </tr>
            </thead>

            <tbody>
              <tr
                v-for="row in contrastRows"
                :key="`${row.foreground}-${row.background}`"
                class="border-b border-[var(--color-hairline)]"
              >
                <td class="py-2 pr-4">{{ row.usage }}</td>
                <td class="py-2 pr-4 font-mono text-xs text-[var(--color-ink-muted)]">
                  {{ row.foreground }} on {{ row.background }}
                </td>
                <td class="py-2 pr-4 tabular-nums">{{ row.threshold.toFixed(1) }}:1</td>
                <td class="py-2 pr-4 tabular-nums">
                  <span :class="row.passes ? '' : 'text-[var(--color-accent-ink)] font-semibold'">
                    {{ row.ratio === null ? '—' : `${row.ratio.toFixed(2)}:1` }}
                  </span>
                  <span class="ml-2 text-xs text-[var(--color-ink-muted)]">
                    {{ row.passes ? 'pass' : 'fail' }}
                  </span>
                </td>
                <td
                  class="py-2"
                  :style="{
                    backgroundColor: `var(--${row.background})`,
                    color: `var(--${row.foreground})`,
                  }"
                >
                  <span class="px-2">{{ row.threshold === AA_TEXT ? 'Aortic valve' : '▬▬▬' }}</span>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </section>

      <!-- Form -->
      <section class="mt-16" aria-labelledby="form-heading">
        <SectionLabel tag="h2" id="form-heading">Radius and shadow</SectionLabel>

        <p class="mt-3 max-w-2xl font-body text-body text-[var(--color-ink-soft)]">
          Two radii and two shadows. Both shadows are warm-tinted rather than neutral black, which
          is what stops a white card on warm paper reading as a cut-out.
        </p>

        <ul class="mt-6 grid gap-6 sm:grid-cols-2 lg:grid-cols-4">
          <li class="rounded-card bg-[var(--color-surface)] p-5 shadow-card">
            <SectionLabel>Card</SectionLabel>
            <p class="mt-2 font-mono text-xs text-[var(--color-ink-muted)]">--radius-card</p>
            <p class="font-mono text-xs text-[var(--color-ink-muted)]">--shadow-card</p>
          </li>

          <li class="rounded-tile bg-[var(--color-surface)] p-5 shadow-card">
            <SectionLabel>Tile</SectionLabel>
            <p class="mt-2 font-mono text-xs text-[var(--color-ink-muted)]">--radius-tile</p>
            <p class="font-mono text-xs text-[var(--color-ink-muted)]">--shadow-card</p>
          </li>

          <li class="rounded-card bg-[var(--color-surface)] p-5 shadow-rail">
            <SectionLabel>Rail</SectionLabel>
            <p class="mt-2 font-mono text-xs text-[var(--color-ink-muted)]">--shadow-rail</p>
            <p class="text-ui text-[var(--color-ink-soft)]">Floats over the canvas</p>
          </li>

          <li class="rounded-card bg-[var(--color-surface-sunk)] p-5">
            <SectionLabel>Sunk</SectionLabel>
            <p class="mt-2 font-mono text-xs text-[var(--color-ink-muted)]">--color-surface-sunk</p>
            <p class="text-ui text-[var(--color-ink-soft)]">No shadow — it recedes</p>
          </li>
        </ul>
      </section>

      <!-- Component states -->
      <section class="mt-16" aria-labelledby="states-heading">
        <SectionLabel tag="h2" id="states-heading">Component states</SectionLabel>

        <p class="mt-3 max-w-2xl font-body text-body text-[var(--color-ink-soft)]">
          Inert samples of the states the later phases assemble. There is no disabled state on this
          page and there will not be one: a control the viewer reports as degraded is hidden, not
          shown greyed out with a tooltip explaining that it does nothing.
        </p>

        <div class="mt-8 grid gap-6 lg:grid-cols-2">
          <!-- The label motif -->
          <article class="rounded-card bg-[var(--color-surface)] p-6 shadow-card">
            <h3 class="font-display text-title">Section label</h3>

            <p class="mt-2 font-body text-body text-[var(--color-ink-soft)]">
              Written in normal case and uppercased by CSS, so a screen reader announces a phrase
              instead of spelling it out.
            </p>

            <div class="mt-5 space-y-3">
              <SectionLabel>Organ library</SectionLabel>
              <SectionLabel>Key facts</SectionLabel>
              <SectionLabel dot-color="var(--color-accent)">The heart</SectionLabel>
              <SectionLabel>3D specimen · Heart</SectionLabel>
            </div>
          </article>

          <!-- Buttons -->
          <article class="rounded-card bg-[var(--color-surface)] p-6 shadow-card">
            <h3 class="font-display text-title">Actions</h3>

            <p class="mt-2 font-body text-body text-[var(--color-ink-soft)]">
              The filled CTA sets its label in
              <code class="font-mono text-[0.9em]">--color-ink</code>, not white: white on
              <code class="font-mono text-[0.9em]">--color-accent</code> measures 3.12:1 and fails.
            </p>

            <div class="mt-5 space-y-3">
              <button
                type="button"
                class="w-full rounded-tile bg-[var(--color-accent)] px-4 py-2.5 text-ui font-medium text-[var(--color-ink)] transition-opacity hover:opacity-90"
              >
                View lesson →
              </button>

              <div class="grid grid-cols-2 gap-3">
                <button
                  type="button"
                  class="rounded-tile border border-[var(--color-hairline-strong)] px-4 py-2.5 text-ui font-medium transition-colors hover:bg-[var(--color-surface-sunk)]"
                >
                  Animate
                </button>
                <button
                  type="button"
                  class="rounded-tile border border-[var(--color-hairline-strong)] px-4 py-2.5 text-ui font-medium transition-colors hover:bg-[var(--color-surface-sunk)]"
                >
                  Quiz
                </button>
              </div>

              <button
                type="button"
                class="w-full rounded-tile border border-[var(--color-hairline-strong)] px-4 py-2.5 text-ui font-medium transition-colors hover:bg-[var(--color-surface-sunk)]"
              >
                Compare
              </button>
            </div>
          </article>

          <!-- Navigation and search -->
          <article class="rounded-card bg-[var(--color-surface)] p-6 shadow-card">
            <h3 class="font-display text-title">Shell</h3>

            <div class="mt-5 flex flex-wrap items-baseline gap-3">
              <span class="font-display text-title">AnatoLab</span>
              <span class="font-body text-subtitle italic text-[var(--color-ink-muted)]">
                anatomy, taken apart
              </span>
            </div>

            <ul class="mt-5 flex flex-wrap items-center gap-1">
              <li>
                <span
                  class="block rounded-full bg-[var(--color-accent-soft)] px-3.5 py-1.5 text-ui font-medium text-[var(--color-accent-ink)]"
                  >Explore</span
                >
              </li>
              <li>
                <span class="block rounded-full px-3.5 py-1.5 text-ui text-[var(--color-ink-soft)]"
                  >Lessons</span
                >
              </li>
              <li>
                <span class="block rounded-full px-3.5 py-1.5 text-ui text-[var(--color-ink-soft)]"
                  >Missions</span
                >
              </li>
            </ul>

            <p
              class="mt-5 rounded-full border border-[var(--color-hairline-strong)] bg-[var(--color-surface-sunk)] px-4 py-2 text-ui text-[var(--color-ink-muted)]"
            >
              Search organs and structures
            </p>
          </article>

          <!-- Library rows -->
          <article class="rounded-card bg-[var(--color-surface)] p-6 shadow-card">
            <h3 class="font-display text-title">Library row</h3>

            <p class="mt-2 font-body text-body text-[var(--color-ink-soft)]">
              The thumbnail tile is empty here because the asset pipeline emits no organ thumbnail
              yet — see the delivery note. The tile keeps its space so the row does not reflow when
              one arrives.
            </p>

            <ul class="mt-5 space-y-1">
              <li
                class="flex items-center gap-3 rounded-tile border border-[color-mix(in_srgb,var(--color-accent)_30%,transparent)] bg-[var(--color-accent-soft)] p-2"
              >
                <span
                  class="size-11 shrink-0 rounded-tile bg-[var(--color-surface-sunk)]"
                  aria-hidden="true"
                />
                <span>
                  <span class="block font-display text-[1.0625rem] leading-tight">Heart</span>
                  <span class="block text-xs text-[var(--color-ink-muted)]">Cardiovascular</span>
                </span>
              </li>

              <li class="flex items-center gap-3 rounded-tile p-2 hover:bg-[var(--color-paper)]">
                <span
                  class="size-11 shrink-0 rounded-tile bg-[var(--color-surface-sunk)]"
                  aria-hidden="true"
                />
                <span>
                  <span class="block font-display text-[1.0625rem] leading-tight">Lungs</span>
                  <span class="block text-xs text-[var(--color-ink-muted)]">Respiratory</span>
                </span>
              </li>

              <li class="flex items-center gap-3 rounded-tile p-2 opacity-60">
                <span
                  class="size-11 shrink-0 rounded-tile bg-[var(--color-surface-sunk)]"
                  aria-hidden="true"
                />
                <span>
                  <span class="block font-display text-[1.0625rem] leading-tight">Skeletal</span>
                  <span class="block text-xs text-[var(--color-ink-muted)]">Coming soon</span>
                </span>
              </li>
            </ul>
          </article>

          <!-- Canvas overlays -->
          <article class="rounded-card bg-[var(--color-surface)] p-6 shadow-card">
            <h3 class="font-display text-title">Canvas overlays</h3>

            <div
              class="relative mt-5 h-56 overflow-hidden rounded-tile bg-[var(--color-surface-sunk)]"
            >
              <p
                class="absolute right-3 top-3 max-w-[15rem] rotate-[0.6deg] rounded-tile border border-[var(--color-note-edge)] bg-[var(--color-note)] px-3 py-2 font-body text-[0.8125rem] leading-snug shadow-card"
              >
                Drag to rotate. Click a marker to read what it is.
              </p>

              <SectionLabel class="absolute bottom-3 left-3">3D specimen · Heart</SectionLabel>

              <div class="absolute left-1/2 top-1/2 flex -translate-x-1/2 -translate-y-1/2 gap-6">
                <span class="flex flex-col items-center gap-2">
                  <span
                    class="size-3.5 rounded-full bg-[var(--color-hotspot)] ring-2 ring-[var(--color-surface)] shadow-card"
                    aria-hidden="true"
                  />
                  <span class="text-xs text-[var(--color-ink-muted)]">Resting</span>
                </span>

                <span class="flex flex-col items-center gap-2">
                  <span
                    class="size-4 rounded-full bg-[var(--color-hotspot-live)] ring-[3px] ring-[var(--color-surface)] shadow-card"
                    aria-hidden="true"
                  />
                  <span class="text-xs text-[var(--color-ink-muted)]">Active</span>
                </span>

                <span class="flex flex-col items-center gap-2">
                  <span
                    class="size-3.5 rounded-full bg-[var(--color-hotspot)] opacity-30 ring-2 ring-[var(--color-surface)]"
                    aria-hidden="true"
                  />
                  <span class="text-xs text-[var(--color-ink-muted)]">Occluded</span>
                </span>
              </div>
            </div>
          </article>

          <!-- Callout -->
          <article class="rounded-card bg-[var(--color-surface)] p-6 shadow-card">
            <h3 class="font-display text-title">Callout</h3>

            <div class="mt-5 rounded-card bg-[var(--color-surface)] p-4 shadow-card">
              <div class="flex items-start gap-2">
                <span
                  class="mt-1.5 size-2.5 shrink-0 rounded-full bg-[var(--color-hotspot-live)]"
                  aria-hidden="true"
                />
                <p class="flex-1 font-display text-[1.125rem] leading-tight">Left ventricle</p>
                <span class="text-ui text-[var(--color-ink-muted)]" aria-hidden="true">✕</span>
              </div>

              <p
                class="mt-2 font-body text-[0.9375rem] leading-relaxed text-[var(--color-ink-soft)]"
              >
                Pumps oxygenated blood into the aorta and onward to the whole body.
              </p>
            </div>

            <p class="mt-5 font-body text-body text-[var(--color-ink-soft)]">
              Tab to any control on this page to see the focus ring. It is the same ring the viewer
              controls use, and it is the reason the 3D layer has a keyboard path at all.
            </p>
          </article>
        </div>
      </section>
    </div>
  </div>
</template>
