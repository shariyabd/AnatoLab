<script setup lang="ts">
import { computed, ref } from 'vue'
import type { ActivityPayload, LessonStep, StructureDto, StructureId } from '@/types/lessons'

/**
 * The interactive activity — PRD §8 step 4.
 *
 * An ordered walk: the student works down a path, selecting each structure as
 * they reach it. Ticking one off is a reading aid, not an assessment — this
 * lane grades nothing (docs/handovers/06-lessons.md), so the ticks are local,
 * unsaved, and the whole list is visible from the start. Hiding the next entry
 * until the current one is picked would make this a quiz, which is F07's.
 */
const props = defineProps<{ step: LessonStep; structures: readonly StructureDto[] }>()

const emit = defineEmits<{
  (event: 'focus', id: StructureId): void
  (event: 'highlight', id: StructureId | null): void
}>()

const payload = props.step.payload as ActivityPayload

/** Positions, not ids: a path may legitimately revisit the same structure. */
const visited = ref<Set<number>>(new Set())

const steps = computed(() =>
  payload.structures
    .map((slug) => props.structures.find((structure) => structure.slug === slug))
    .filter((structure): structure is StructureDto => structure !== undefined),
)

function visit(position: number, id: StructureId): void {
  visited.value = new Set(visited.value).add(position)
  emit('focus', id)
}
</script>

<template>
  <div class="space-y-4">
    <p class="text-sm leading-relaxed">{{ payload.instruction }}</p>

    <ol v-if="steps.length > 0" class="space-y-1.5">
      <li v-for="(target, position) in steps" :key="`${target.id}-${position}`">
        <button
          type="button"
          class="flex w-full items-center gap-3 rounded-md border px-3 py-2 text-left transition-colors focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[var(--color-accent)]"
          :class="
            visited.has(position)
              ? 'border-[var(--color-accent)] bg-[var(--color-surface-raised)]'
              : 'border-[var(--color-border-subtle)] hover:bg-[var(--color-surface-raised)]'
          "
          :aria-pressed="visited.has(position)"
          @click="visit(position, target.id)"
          @mouseenter="emit('highlight', target.id)"
          @mouseleave="emit('highlight', null)"
          @focus="emit('highlight', target.id)"
          @blur="emit('highlight', null)"
        >
          <span
            aria-hidden="true"
            class="flex size-6 shrink-0 items-center justify-center rounded-full border border-[var(--color-border-subtle)] text-xs tabular-nums"
          >
            {{ position + 1 }}
          </span>

          <span class="text-sm font-medium">{{ target.name }}</span>

          <span v-if="visited.has(position)" class="ml-auto text-xs text-[var(--color-ink-muted)]">
            Seen
          </span>
        </button>
      </li>
    </ol>

    <p v-else class="text-xs text-[var(--color-ink-muted)]">
      This activity has no structures to walk through.
    </p>
  </div>
</template>
