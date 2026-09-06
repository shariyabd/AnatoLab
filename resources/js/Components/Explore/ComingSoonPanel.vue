<script setup lang="ts">
import SectionLabel from '@/Components/Atelier/SectionLabel.vue'
import type { UpcomingOrganCard } from '@/types/explore'

/**
 * The honest answer to `/explore/stomach` — handover 16, handover 15 Phase 7.
 *
 * A slug that is in the taxonomy but has no model yet used to be a 404, which
 * told a student the stomach was not part of this product. It is: handover 16
 * seeded all eleven systems and the full organ list as draft rows precisely so
 * coverage is a visible roadmap rather than an implication.
 *
 * So this panel says three things and offers nothing: what the organ is, that
 * it is coming, and that the rest of the library is open. It carries no button
 * and no link to a model, because there is no model — an inert state that
 * pretends to be interactive is worse than the 404 it replaced.
 */
defineProps<{
  organ: UpcomingOrganCard
}>()
</script>

<template>
  <section
    class="rounded-card bg-[var(--color-surface)] p-5 shadow-card"
    aria-labelledby="coming-soon-heading"
  >
    <SectionLabel tag="p">Coming soon</SectionLabel>

    <h2 id="coming-soon-heading" class="mt-2 font-display text-2xl leading-tight">
      {{ organ.name }}
    </h2>

    <p v-if="organ.scientificName" class="mt-1 text-ui italic text-[var(--color-ink-soft)]">
      {{ organ.scientificName }}
    </p>

    <p
      v-if="organ.bodySystem"
      class="mt-3 text-xs uppercase tracking-wide text-[var(--color-ink-muted)]"
    >
      {{ organ.bodySystem.name }}
    </p>

    <p v-if="organ.description" class="mt-3 text-ui text-[var(--color-ink-soft)]">
      {{ organ.description }}
    </p>

    <p
      class="mt-4 border-t border-[var(--color-hairline)] pt-4 text-ui text-[var(--color-ink-soft)]"
    >
      This organ is part of the atlas, but its 3D model has not been added yet. Pick a published
      organ from the library to keep exploring.
    </p>
  </section>
</template>
