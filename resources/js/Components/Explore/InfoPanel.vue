<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { Link } from '@inertiajs/vue3'
import Icon from '@/Components/Atelier/Icon.vue'
import SectionLabel from '@/Components/Atelier/SectionLabel.vue'
import type {
  OrganDto,
  OrganEditorial,
  StructureDetail,
  StructureDto,
  StructureId,
} from '@/types/explore'

/**
 * Organ metadata, the selected structure, and what it connects to.
 * Handover 05; rebuilt to the atelier language by handover 15 Phase 5.
 *
 * Two sources, on purpose. Everything except `location` and
 * `relatedStructures` comes from the `OrganDto` the page already holds, so the
 * panel is populated the instant a structure is selected — before, and whether
 * or not, the detail request lands. `detail` only ever adds.
 *
 * Display only: no fetching, no branching on business rules
 * (docs/engineering.md §4).
 *
 * **What is missing, and why nothing is invented to fill it.** The handover's
 * panel wants a tagline, a `KEY FACTS` table, a medical-importance card and a
 * did-you-know card. None of those columns exists on `organs`, and handover 15
 * may not migrate a table F03 owns. So they arrive through `editorial`, typed
 * in `types/explore.ts`, and every block that reads it is `v-if`-guarded: the
 * panel is finished and simply shows less until the columns land.
 *
 * **The action row is short on purpose.** `View lessons` is real —
 * `/lessons?organ={slug}` is a filter `LessonIndexRequest` already supports.
 * `Guided tour` is real, and is the honest name for what the handover calls
 * Animate: the models carry no animation clips, so this is the viewer's
 * scripted camera-and-marker choreography over the structures, and it is
 * hidden outright when there are none to choreograph. `Quiz` and `Compare` are
 * absent: this page holds no organ-to-quiz link and no comparison view exists.
 * Hide, don't stub.
 */
const props = withDefaults(
  defineProps<{
    organ: OrganDto | null
    structure: StructureDto | null
    detail: StructureDetail | null
    detailFailed: boolean
    /** Related structures outside the loaded organ cannot be selected in it. */
    selectableIds: readonly StructureId[]
    /** The body system this organ belongs to, for the label and its dot. */
    bodySystemName?: string | null
    /** Rendered as the small circular portrait; null until thumbnails exist. */
    thumbnailUrl?: string | null
    /** Not in the database yet — see the note above. */
    editorial?: OrganEditorial | null
  }>(),
  { bodySystemName: null, thumbnailUrl: null, editorial: null },
)

defineEmits<{
  (event: 'select-related', id: StructureId): void
  (event: 'play-tour'): void
}>()

/** Guards against a detail response for a structure the student has left. */
const related = computed(() =>
  props.detail !== null && props.structure !== null && props.detail.id === props.structure.id
    ? (props.detail.relatedStructures ?? [])
    : [],
)

const location = computed(() =>
  props.detail !== null && props.structure !== null && props.detail.id === props.structure.id
    ? props.detail.location
    : null,
)

const keyFacts = computed(() => props.editorial?.keyFacts ?? [])

/**
 * No organ has a thumbnail file yet, so today every one of these requests
 * fails. The library tile falls back to the organ's accent; a 56px portrait has
 * nothing to fall back to and is simply dropped, because a broken-image glyph
 * beside the organ's name is worse than no portrait at all.
 */
const thumbnailBroken = ref(false)

watch(
  () => props.thumbnailUrl,
  () => (thumbnailBroken.value = false),
)

/**
 * A tour is a choreography over the organ's structures, so an organ with none
 * has nothing to play and the control is not offered. This is the same test
 * the viewer applies before emitting `capability:degraded` for `animation`.
 */
const canPlayTour = computed(() => (props.organ?.structures.length ?? 0) > 0)
</script>

<template>
  <div class="space-y-4">
    <section
      v-if="organ"
      class="rounded-card bg-[var(--color-surface)] p-5 shadow-card"
      aria-labelledby="info-organ-heading"
    >
      <div class="flex items-start gap-4">
        <div class="min-w-0 flex-1">
          <SectionLabel :dot-color="organ.accentColor">
            {{ bodySystemName ?? organ.name }}
          </SectionLabel>

          <h2 id="info-organ-heading" class="mt-2 font-display text-display">
            {{ organ.name }}
          </h2>

          <p
            v-if="editorial?.tagline"
            class="mt-1 font-body text-subtitle italic text-[var(--color-ink-soft)]"
          >
            {{ editorial.tagline }}
          </p>
          <p
            v-else-if="organ.scientificName"
            class="mt-1 font-body text-subtitle italic text-[var(--color-ink-soft)]"
          >
            {{ organ.scientificName }}
          </p>
        </div>

        <img
          v-if="thumbnailUrl && !thumbnailBroken"
          :src="thumbnailUrl"
          alt=""
          class="size-14 shrink-0 rounded-full object-cover"
          loading="lazy"
          decoding="async"
          @error="thumbnailBroken = true"
        />
      </div>

      <p v-if="organ.description" class="mt-4 font-body text-body text-[var(--color-ink-soft)]">
        {{ organ.description }}
      </p>

      <template v-if="keyFacts.length > 0">
        <SectionLabel tag="h3" class="mt-6">Key facts</SectionLabel>

        <dl class="mt-2 divide-y divide-[var(--color-hairline)]">
          <div
            v-for="fact in keyFacts"
            :key="fact.label"
            class="flex items-center justify-between gap-4 py-2"
          >
            <dt class="flex items-center gap-2 text-ui text-[var(--color-ink-soft)]">
              <Icon :name="fact.icon" class="size-4 shrink-0" />
              {{ fact.label }}
            </dt>
            <dd class="text-ui text-[var(--color-ink)]">{{ fact.value }}</dd>
          </div>
        </dl>
      </template>

      <div
        v-if="editorial?.medicalImportance"
        class="mt-5 flex gap-3 rounded-tile bg-[var(--color-surface-sunk)] p-3.5"
      >
        <Icon name="stethoscope" class="mt-0.5 size-4 shrink-0 text-[var(--color-ink-soft)]" />
        <div>
          <SectionLabel tag="h3">Medical importance</SectionLabel>
          <p class="mt-1 font-body text-[0.9375rem] leading-relaxed text-[var(--color-ink-soft)]">
            {{ editorial.medicalImportance }}
          </p>
        </div>
      </div>

      <div
        v-if="editorial?.didYouKnow"
        class="mt-3 flex gap-3 rounded-tile bg-[var(--color-surface-sunk)] p-3.5"
      >
        <Icon name="spark" class="mt-0.5 size-4 shrink-0 text-[var(--color-ink-soft)]" />
        <div>
          <SectionLabel tag="h3">Did you know</SectionLabel>
          <p class="mt-1 font-body text-[0.9375rem] leading-relaxed text-[var(--color-ink-soft)]">
            {{ editorial.didYouKnow }}
          </p>
        </div>
      </div>

      <div class="mt-5 space-y-2">
        <Link
          :href="`/lessons?organ=${organ.slug}`"
          class="flex w-full items-center justify-center gap-2 rounded-tile bg-[var(--color-accent)] px-4 py-2.5 text-ui font-medium text-[var(--color-ink)] transition-opacity hover:opacity-90"
        >
          View lessons
          <Icon name="arrow" class="size-4" />
        </Link>

        <button
          v-if="canPlayTour"
          type="button"
          class="w-full rounded-tile border border-[var(--color-hairline-strong)] px-4 py-2.5 text-ui font-medium transition-colors hover:bg-[var(--color-surface-sunk)]"
          @click="$emit('play-tour')"
        >
          Guided tour
        </button>
      </div>
    </section>

    <section
      class="rounded-card bg-[var(--color-surface)] p-5 shadow-card"
      aria-labelledby="info-structure-heading"
    >
      <SectionLabel tag="h2" id="info-structure-heading">Selected structure</SectionLabel>

      <p v-if="structure === null" class="mt-3 font-body text-body text-[var(--color-ink-soft)]">
        Choose a structure from the list, or click a marker on the model.
      </p>

      <div v-else class="mt-3 space-y-4">
        <div>
          <h3 class="font-display text-title">{{ structure.name }}</h3>
          <p
            v-if="structure.taTerm"
            class="mt-0.5 font-body text-[0.9375rem] italic text-[var(--color-ink-muted)]"
          >
            {{ structure.taTerm }}
          </p>
        </div>

        <p v-if="structure.description" class="font-body text-body text-[var(--color-ink-soft)]">
          {{ structure.description }}
        </p>

        <div v-if="structure.function">
          <SectionLabel tag="h4">What it does</SectionLabel>
          <p class="mt-1 font-body text-body text-[var(--color-ink-soft)]">
            {{ structure.function }}
          </p>
        </div>

        <div v-if="location">
          <SectionLabel tag="h4">Where it is</SectionLabel>
          <p class="mt-1 font-body text-body text-[var(--color-ink-soft)]">{{ location }}</p>
        </div>

        <div v-if="related.length > 0">
          <SectionLabel tag="h4">Connects to</SectionLabel>
          <ul class="mt-1.5 space-y-0.5">
            <li v-for="link in related" :key="link.id">
              <button
                v-if="selectableIds.includes(link.id)"
                type="button"
                class="w-full rounded-tile px-2 py-1.5 text-left text-ui transition-colors hover:bg-[var(--color-paper)]"
                @click="$emit('select-related', link.id)"
              >
                <span class="font-medium">{{ link.name }}</span>
                <span v-if="link.relationLabel" class="text-[var(--color-ink-muted)]">
                  &middot; {{ link.relationLabel }}
                </span>
              </button>
              <span v-else class="block px-2 py-1.5 text-ui text-[var(--color-ink-muted)]">
                {{ link.name }}
                <template v-if="link.relationLabel">&middot; {{ link.relationLabel }}</template>
              </span>
            </li>
          </ul>
        </div>

        <!--
          Non-fatal by construction: everything above came from the organ
          payload, so a failed detail request costs the related links and
          nothing else.
        -->
        <p v-if="detailFailed" class="text-ui text-[var(--color-ink-muted)]">
          Related structures could not be loaded.
        </p>
      </div>
    </section>
  </div>
</template>
