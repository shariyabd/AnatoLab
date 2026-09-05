<script setup lang="ts">
import type { KnowledgeCheckPayload, LessonStep, StructureDto } from '@/types/lessons'

/**
 * The knowledge check — PRD §8 step 5.
 *
 * A seat, not a question. F07 owns questions, their options and their grading;
 * this lane owns where a question sits in the sequence
 * (docs/handovers/06-lessons.md, "Out of scope"). `reference` is the stable key
 * F07 binds a real question to, and the drop-in is one element here.
 *
 * Nothing in this payload is or ever becomes an answer key (invariant 4).
 */
const props = defineProps<{ step: LessonStep; structures: readonly StructureDto[] }>()

const payload = props.step.payload as KnowledgeCheckPayload
</script>

<template>
  <div class="space-y-3">
    <p class="text-sm font-medium leading-relaxed">{{ payload.prompt }}</p>

    <div
      class="rounded-md border border-dashed border-[var(--color-border-subtle)] px-3 py-3"
      :data-question-reference="payload.reference"
    >
      <p class="text-xs leading-relaxed text-[var(--color-ink-muted)]">
        The graded version of this question arrives with the quiz engine. For now, answer it in your
        head and carry on — the explanation above has what you need.
      </p>
    </div>
  </div>
</template>
