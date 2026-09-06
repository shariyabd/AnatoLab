<script setup lang="ts">
import { computed, ref } from 'vue'
import Icon from '@/Components/Atelier/Icon.vue'
import SectionLabel from '@/Components/Atelier/SectionLabel.vue'
import type { ExploreOrganCard, UpcomingOrganCard } from '@/types/explore'

/**
 * The organ picker — handover 05, restyled by handover 15 Phase 2.
 *
 * Hovering or focusing a row warms that organ's GLB through the viewer's
 * `prefetchOrgan`, so the model is usually already decoded by the time the
 * student commits to the click. That is why `ExploreOrganResource` carries a
 * `modelUrl` the row never displays, and it is the one behaviour a restyle
 * must not drop.
 *
 * Prefetch fires on `focusin` as well as `pointerenter`: a keyboard user
 * arrives at the row the same way, and there is no reason for them to wait
 * longer for the model.
 */
const props = withDefaults(
  defineProps<{
    organs: readonly ExploreOrganCard[]
    upcoming?: readonly UpcomingOrganCard[]
    currentSlug: string | null
  }>(),
  { upcoming: () => [] },
)

defineEmits<{
  (event: 'select', organ: ExploreOrganCard): void
  (event: 'prefetch', organ: ExploreOrganCard): void
}>()

/**
 * Below this, a flat list reads better and grouping is noise — nine organs
 * under seven headings is mostly headings. Above it the list stops being
 * scannable without them (handover 15 Phase 2).
 *
 * Counted over both lists, not just the published one. Handover 16 seeds the
 * full taxonomy, so the panel is past this threshold long before the models
 * are — and nine published organs scattered through eleven headings is exactly
 * the case the headings exist for.
 */
const GROUPING_THRESHOLD = 12

const totalCount = computed(() => props.organs.length + props.upcoming.length)

const grouped = computed(() => totalCount.value > GROUPING_THRESHOLD)

interface OrganGroup {
  readonly key: string
  readonly name: string
  readonly organs: readonly ExploreOrganCard[]
  readonly upcoming: readonly UpcomingOrganCard[]
}

/**
 * Insertion-ordered, so the groups follow whatever order the API sent the
 * organs in. Re-sorting here would silently override a decision that belongs
 * to `AnatomyService::listPublishedOrgans`.
 *
 * Published organs are absorbed first so they set the order of the headings;
 * the taxonomy then fills in behind them, and a system with nothing published
 * yet appears at the end rather than pushing a system that has content down the
 * panel. A system present only in the taxonomy still gets its heading — that is
 * the point of showing coverage at all (handover 15 Phase 7).
 */
const groups = computed<OrganGroup[]>(() => {
  const byKey = new Map<string, OrganGroup>()

  function group(key: string, name: string): OrganGroup {
    const existing = byKey.get(key)

    if (existing !== undefined) return existing

    const created: OrganGroup = { key, name, organs: [], upcoming: [] }
    byKey.set(key, created)

    return created
  }

  for (const organ of props.organs) {
    const key = organ.bodySystem?.slug ?? 'unfiled'
    const existing = group(key, organ.bodySystem?.name ?? 'Other')

    byKey.set(key, { ...existing, organs: [...existing.organs, organ] })
  }

  for (const organ of props.upcoming) {
    const key = organ.bodySystem?.slug ?? 'unfiled'
    const existing = group(key, organ.bodySystem?.name ?? 'Other')

    byKey.set(key, { ...existing, upcoming: [...existing.upcoming, organ] })
  }

  return [...byKey.values()]
})

const systemCount = computed(() => groups.value.length)

/**
 * Thumbnails that 404.
 *
 * The resource sends null rather than a URL it knows is broken, but it cannot
 * know: `thumbnail_path` is a placeholder on every seeded organ and no
 * thumbnail file exists yet, so today every one of these requests fails. The
 * tile keeps its space and falls back to the organ's accent rather than
 * collapsing, so the row does not reflow when real thumbnails land. This is
 * view state, not data.
 */
const broken = ref(new Set<string>())

function onThumbnailError(id: string): void {
  broken.value = new Set(broken.value).add(id)
}
</script>

<template>
  <section
    class="rounded-card bg-[var(--color-surface)] shadow-card"
    aria-labelledby="organ-library-heading"
  >
    <header class="px-4 pb-2 pt-4">
      <SectionLabel tag="h2" id="organ-library-heading">Organ library</SectionLabel>
    </header>

    <p v-if="totalCount === 0" class="px-4 pb-4 text-ui text-[var(--color-ink-soft)]">
      No organs have been published yet.
    </p>

    <!--
      The panel scrolls, the page does not. At sixty organs an unbounded list
      pushes the viewer off screen; bounded, the sticky headings say where you
      are in it (handover 15 Phase 7).
    -->
    <div v-else class="max-h-[min(28rem,calc(100vh-18rem))] overflow-y-auto px-2 pb-2">
      <template v-for="group in groups" :key="group.key">
        <h3
          v-if="grouped"
          class="sticky top-0 z-10 bg-[var(--color-surface)] px-2 py-2 text-label uppercase text-[var(--color-ink-soft)]"
        >
          {{ group.name }}
        </h3>

        <ul
          v-if="group.organs.length > 0"
          :aria-label="grouped ? group.name : 'Organs'"
          class="space-y-0.5"
        >
          <li v-for="organ in group.organs" :key="organ.id">
            <button
              type="button"
              class="flex w-full items-center gap-3 rounded-tile border p-2 text-left transition-colors"
              :class="
                currentSlug === organ.slug
                  ? 'border-[color-mix(in_srgb,var(--color-accent)_30%,transparent)] bg-[var(--color-accent-soft)]'
                  : 'border-transparent hover:bg-[var(--color-paper)]'
              "
              :aria-current="currentSlug === organ.slug ? 'true' : undefined"
              @click="$emit('select', organ)"
              @pointerenter="$emit('prefetch', organ)"
              @focusin="$emit('prefetch', organ)"
            >
              <img
                v-if="organ.thumbnailUrl && !broken.has(organ.id)"
                :src="organ.thumbnailUrl"
                alt=""
                class="size-11 shrink-0 rounded-tile object-cover"
                loading="lazy"
                decoding="async"
                @error="onThumbnailError(organ.id)"
              />
              <span
                v-else
                class="size-11 shrink-0 rounded-tile"
                :style="{ backgroundColor: organ.accentColor }"
                aria-hidden="true"
              />

              <span class="min-w-0 flex-1">
                <span class="block truncate font-display text-[1.0625rem] leading-tight">
                  {{ organ.name }}
                </span>
                <span class="block truncate text-xs text-[var(--color-ink-muted)]">
                  <template v-if="organ.bodySystem">{{ organ.bodySystem.name }}</template>
                  <template v-if="organ.bodySystem && organ.structureCount !== undefined">
                    &middot;
                  </template>
                  <template v-if="organ.structureCount !== undefined">
                    {{ organ.structureCount }} structures
                  </template>
                </span>
              </span>
            </button>
          </li>
        </ul>

        <!--
          The coming-soon rows: the taxonomy handover 16 seeded, rendered as
          what they are. A <li> and a <span>, never a <button> — the row has no
          model to open, so the honest control is no control. `aria-disabled`
          would announce a disabled button; there is no button to disable, and
          a list item that is simply not interactive needs no ARIA at all.
        -->
        <ul
          v-if="group.upcoming.length > 0"
          :aria-label="grouped ? `${group.name}, coming soon` : 'Coming soon'"
          class="space-y-0.5"
        >
          <!--
            Recessed by colour token, never by an opacity on the row. Both
            captions here are verified pairings on --color-surface
            (design/palette.ts ATELIER_CONTRAST_PAIRINGS); an opacity wrapper
            would multiply straight through them and put text that measures
            4.5:1 in the token layer somewhere under 3:1 on screen, which is the
            exact failure handover 15's contrast criterion exists to catch. The
            swatch carries the fade instead — it is aria-hidden decoration and
            has no ratio to lose.
          -->
          <li v-for="organ in group.upcoming" :key="organ.id">
            <span
              class="flex w-full items-center gap-3 rounded-tile border border-transparent p-2 text-left"
            >
              <span
                class="size-11 shrink-0 rounded-tile opacity-30"
                :style="{ backgroundColor: organ.accentColor }"
                aria-hidden="true"
              />

              <span class="min-w-0 flex-1">
                <span
                  class="block truncate font-display text-[1.0625rem] leading-tight text-[var(--color-ink-soft)]"
                >
                  {{ organ.name }}
                </span>
                <span
                  class="block truncate text-xs uppercase tracking-wide text-[var(--color-ink-muted)]"
                >
                  Coming soon
                </span>
              </span>
            </span>
          </li>
        </ul>
      </template>
    </div>

    <!--
      A count rather than the reference's "View all organs →". This panel is
      already the whole library, so that link would go to the page it is on.
    -->
    <footer
      v-if="totalCount > 0"
      class="flex items-center gap-1.5 border-t border-[var(--color-hairline)] px-4 py-3 text-xs text-[var(--color-ink-muted)]"
    >
      <Icon name="cube" class="size-3.5" />
      {{ organs.length }} organs across {{ systemCount }}
      {{ systemCount === 1 ? 'system' : 'systems' }}
      <template v-if="upcoming.length > 0">&middot; {{ upcoming.length }} coming soon</template>
    </footer>
  </section>
</template>
