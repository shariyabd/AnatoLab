<script setup lang="ts">
import { computed } from 'vue'
import type { OrganDto, StructureDetail, StructureDto, StructureId } from '@/types/explore'

/**
 * Organ metadata, the selected structure, and what it connects to.
 *
 * Two sources, on purpose. Everything except `location` and
 * `relatedStructures` comes from the `OrganDto` the page already holds, so the
 * panel is populated the instant a structure is selected — before, and whether
 * or not, the detail request lands. `detail` only ever adds.
 *
 * Display only: no fetching, no branching on business rules
 * (docs/engineering.md §4).
 */
const props = defineProps<{
  organ: OrganDto | null
  structure: StructureDto | null
  detail: StructureDetail | null
  detailFailed: boolean
  /** Related structures outside the loaded organ cannot be selected in it. */
  selectableIds: readonly StructureId[]
}>()

defineEmits<{
  (event: 'select-related', id: StructureId): void
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
</script>

<template>
  <div class="space-y-6">
    <section v-if="organ" aria-labelledby="info-organ-heading">
      <h2 id="info-organ-heading" class="text-sm font-semibold tracking-tight">
        {{ organ.name }}
      </h2>
      <p v-if="organ.scientificName" class="mt-0.5 text-xs italic text-[var(--color-ink-muted)]">
        {{ organ.scientificName }}
      </p>
      <p
        v-if="organ.description"
        class="mt-2 text-xs leading-relaxed text-[var(--color-ink-muted)]"
      >
        {{ organ.description }}
      </p>
    </section>

    <section aria-labelledby="info-structure-heading">
      <h2
        id="info-structure-heading"
        class="text-[0.6875rem] font-semibold uppercase tracking-wide text-[var(--color-ink-muted)]"
      >
        Selected structure
      </h2>

      <p v-if="structure === null" class="mt-2 text-xs text-[var(--color-ink-muted)]">
        Choose a structure from the list, or click a marker on the model.
      </p>

      <div v-else class="mt-2 space-y-3">
        <div>
          <h3 class="text-sm font-semibold tracking-tight">{{ structure.name }}</h3>
          <p v-if="structure.taTerm" class="mt-0.5 text-xs italic text-[var(--color-ink-muted)]">
            {{ structure.taTerm }}
          </p>
        </div>

        <p v-if="structure.description" class="text-xs leading-relaxed">
          {{ structure.description }}
        </p>

        <div v-if="structure.function">
          <h4
            class="text-[0.6875rem] font-semibold uppercase tracking-wide text-[var(--color-ink-muted)]"
          >
            What it does
          </h4>
          <p class="mt-1 text-xs leading-relaxed">{{ structure.function }}</p>
        </div>

        <div v-if="location">
          <h4
            class="text-[0.6875rem] font-semibold uppercase tracking-wide text-[var(--color-ink-muted)]"
          >
            Where it is
          </h4>
          <p class="mt-1 text-xs leading-relaxed">{{ location }}</p>
        </div>

        <div v-if="related.length > 0">
          <h4
            class="text-[0.6875rem] font-semibold uppercase tracking-wide text-[var(--color-ink-muted)]"
          >
            Connects to
          </h4>
          <ul class="mt-1 space-y-0.5">
            <li v-for="link in related" :key="link.id">
              <button
                v-if="selectableIds.includes(link.id)"
                type="button"
                class="w-full rounded-md px-1.5 py-1 text-left text-xs hover:bg-[var(--color-surface)]"
                @click="$emit('select-related', link.id)"
              >
                <span class="font-medium">{{ link.name }}</span>
                <span v-if="link.relationLabel" class="text-[var(--color-ink-muted)]">
                  &middot; {{ link.relationLabel }}
                </span>
              </button>
              <span v-else class="block px-1.5 py-1 text-xs text-[var(--color-ink-muted)]">
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
        <p v-if="detailFailed" class="text-xs text-[var(--color-ink-muted)]">
          Related structures could not be loaded.
        </p>
      </div>
    </section>
  </div>
</template>
