<script setup lang="ts">
import { computed } from 'vue'
import type { AttemptVerdict, StructureDto } from '@/types/quiz'

/**
 * What the server said, placed where it does not cover what it is talking about.
 *
 * Reproduces the audited feedback behaviour (docs/project-context.md §2.4):
 * the card sits on the **opposite half of the screen** from the structure being
 * revealed, so it never lands on the dot it is pointing at. How long it stays
 * is `useQuiz`'s decision — 2.4 s for a miss against 1.2 s for a hit, because
 * there is an explanation to read.
 *
 * The side is derived from the revealed structure's `anchorPosition.x`. Every
 * model is normalised into the same origin-centred cube and the quiz camera
 * faces it head-on (docs/architecture.md §5.4 rule 1), so the sign of x is the
 * screen half. That is an approximation while the model is rotated, and a
 * deliberate one: the exact answer is a projection, and `ViewerStage` — which
 * Handover 05 owns — does not expose one. If it ever does, this becomes exact
 * with no change to the rest of the lane.
 *
 * It carries correctness on purpose, and this is the one place that is allowed
 * to: the attempt has already been graded and written server-side, and the
 * reveal is docs/architecture.md §9's specified response.
 */
const props = defineProps<{
  verdict: AttemptVerdict
  /** The structure the answer turned out to be, when the question was spatial. */
  correctStructure: StructureDto | null
}>()

/**
 * Left when the structure is on the right, and vice versa. Centre-left by
 * default: an MCQ has no structure to avoid.
 */
const side = computed<'left' | 'right'>(() => {
  const x = props.correctStructure?.anchorPosition[0]
  if (x === undefined) return 'left'
  return x >= 0 ? 'left' : 'right'
})
</script>

<template>
  <!--
    role="status": an outcome confirmation, announced when the reader reaches a
    natural break. "alert" would interrupt mid-sentence on every question.
  -->
  <div
    role="status"
    class="pointer-events-none absolute bottom-4 z-20 max-w-xs"
    :class="side === 'left' ? 'left-4' : 'right-4'"
  >
    <div
      class="pointer-events-auto rounded-lg border-2 bg-[var(--color-surface-raised)] px-4 py-3 shadow-lg"
      :class="verdict.isCorrect ? 'border-[var(--color-success)]' : 'border-[var(--color-danger)]'"
    >
      <p
        class="text-sm font-semibold"
        :class="verdict.isCorrect ? 'text-[var(--color-success)]' : 'text-[var(--color-danger)]'"
      >
        <template v-if="verdict.isCorrect">Correct</template>
        <template v-else>Not quite</template>
      </p>

      <!--
        Naming the structure, not only ringing it green. The ring is invisible
        to a screen reader and to anyone whose model failed to load
        (docs/architecture.md §5.4 rule 5).
      -->
      <p v-if="!verdict.isCorrect && correctStructure" class="mt-1 text-sm">
        The answer is the {{ correctStructure.name }}.
      </p>

      <p
        v-if="verdict.explanation"
        class="mt-2 text-xs leading-relaxed text-[var(--color-ink-muted)]"
      >
        {{ verdict.explanation }}
      </p>
    </div>
  </div>
</template>
