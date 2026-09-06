<script setup lang="ts">
import { computed, ref } from 'vue'
import Icon from '@/Components/Atelier/Icon.vue'
import SectionLabel from '@/Components/Atelier/SectionLabel.vue'
import type { ExploreOrganCard } from '@/types/explore'

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
const props = defineProps<{
  organs: readonly ExploreOrganCard[]
  currentSlug: string | null
}>()

defineEmits<{
  (event: 'select', organ: ExploreOrganCard): void
  (event: 'prefetch', organ: ExploreOrganCard): void
}>()

/**
 * Below this, a flat list reads better and grouping is noise — nine organs
 * under seven headings is mostly headings. Above it the list stops being
 * scannable without them (handover 15 Phase 2).
 */
const GROUPING_THRESHOLD = 12

const grouped = computed(() => props.organs.length > GROUPING_THRESHOLD)

interface OrganGroup {
  readonly key: string
  readonly name: string
  readonly organs: readonly ExploreOrganCard[]
}

/**
 * Insertion-ordered, so the groups follow whatever order the API sent the
 * organs in. Re-sorting here would silently override a decision that belongs
 * to `AnatomyService::listPublishedOrgans`.
 */
const groups = computed<OrganGroup[]>(() => {
  const byKey = new Map<string, OrganGroup>()

  for (const organ of props.organs) {
    const key = organ.bodySystem?.slug ?? 'unfiled'
    const existing = byKey.get(key)

    if (existing === undefined) {
      byKey.set(key, {
        key,
        name: organ.bodySystem?.name ?? 'Other',
        organs: [organ],
      })
    } else {
      byKey.set(key, { ...existing, organs: [...existing.organs, organ] })
    }
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

    <p v-if="organs.length === 0" class="px-4 pb-4 text-ui text-[var(--color-ink-soft)]">
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

        <ul :aria-label="grouped ? group.name : 'Organs'" class="space-y-0.5">
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
      </template>
    </div>

    <!--
      A count rather than the reference's "View all organs →". This panel is
      already the whole library, so that link would go to the page it is on.
    -->
    <footer
      v-if="organs.length > 0"
      class="flex items-center gap-1.5 border-t border-[var(--color-hairline)] px-4 py-3 text-xs text-[var(--color-ink-muted)]"
    >
      <Icon name="cube" class="size-3.5" />
      {{ organs.length }} organs across {{ systemCount }}
      {{ systemCount === 1 ? 'system' : 'systems' }}
    </footer>
  </section>
</template>
