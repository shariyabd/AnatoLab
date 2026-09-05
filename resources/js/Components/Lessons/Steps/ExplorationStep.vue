<script setup lang="ts">
import { computed } from 'vue'
import type { ExplorationPayload, LessonStep, StructureDto, StructureId } from '@/types/lessons'

/**
 * The 3D exploration step — PRD §8 step 2.
 *
 * It does not mount a viewer. The page mounts one `ViewerStage` for the whole
 * lesson and never tears it down; this step asks it to look at things by
 * emitting a structure id (docs/architecture.md §5.4 rules 6 and 7,
 * resources/js/Components/Anatomy/ViewerStage.vue rule 5). A step that owned
 * its own viewer would drop the WebGL context on every "next".
 *
 * The list is a real list of buttons whether or not there is a canvas, so the
 * keyboard path and the no-WebGL path are the same path (rule 5).
 */
const props = defineProps<{ step: LessonStep; structures: readonly StructureDto[] }>()

const emit = defineEmits<{
  (event: 'focus', id: StructureId): void
  (event: 'highlight', id: StructureId | null): void
}>()

const payload = props.step.payload as ExplorationPayload

/**
 * Slugs are what the lesson author wrote; ids are what the viewer takes. The
 * resolution happens here rather than in the template, and an author's typo
 * drops the entry instead of rendering a button that does nothing.
 */
const targets = computed(() =>
  payload.structures
    .map((slug) => props.structures.find((structure) => structure.slug === slug))
    .filter((structure): structure is StructureDto => structure !== undefined),
)
</script>

<template>
  <div class="space-y-4">
    <p class="text-sm leading-relaxed">{{ payload.instruction }}</p>

    <ul v-if="targets.length > 0" class="space-y-1.5">
      <li v-for="target in targets" :key="target.id">
        <button
          type="button"
          class="w-full rounded-md border border-[var(--color-border-subtle)] px-3 py-2 text-left transition-colors hover:bg-[var(--color-surface-raised)] focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[var(--color-accent)]"
          @click="emit('focus', target.id)"
          @mouseenter="emit('highlight', target.id)"
          @mouseleave="emit('highlight', null)"
          @focus="emit('highlight', target.id)"
          @blur="emit('highlight', null)"
        >
          <span class="flex items-baseline gap-2">
            <span
              aria-hidden="true"
              class="size-2 shrink-0 self-center rounded-full"
              :style="{ backgroundColor: target.markerColor ?? 'var(--color-accent)' }"
            />
            <span class="text-sm font-medium">{{ target.name }}</span>
            <span v-if="target.taTerm" class="text-xs italic text-[var(--color-ink-muted)]">
              {{ target.taTerm }}
            </span>
          </span>

          <span
            v-if="target.description"
            class="mt-1 block text-xs leading-relaxed text-[var(--color-ink-muted)]"
          >
            {{ target.description }}
          </span>
        </button>
      </li>
    </ul>

    <p v-else class="text-xs text-[var(--color-ink-muted)]">
      This step has no structures to find on the model.
    </p>
  </div>
</template>
