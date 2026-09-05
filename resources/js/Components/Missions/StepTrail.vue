<script setup lang="ts">
import { computed } from 'vue'
import type { MissionOutcome, MissionPick, StructureDto } from '@/types/missions'

/**
 * The pathway, step by step — the thing acceptance criterion 2 is about.
 *
 * It has two lives. **During the run** it shows what was picked and nothing
 * else: no ticks, no colour, because the run has not been graded and the page
 * has no way of grading it. **After the run** it shows each step's outcome and
 * lets the student walk back through the pathway a step at a time, which is
 * what the per-step feedback exists for (docs/architecture.md §9).
 *
 * Selecting a step is what drives the viewer during review — the page turns it
 * into `focusStructure` on what was picked, and on the revealed target when
 * there is one. The outcome wording comes from the server (`outcomeLabel`), so
 * a new outcome added to `App\Enums\MissionStepOutcome` needs no change here.
 */
const props = defineProps<{
  prompts: readonly string[]
  picks: readonly (MissionPick | null)[]
  structures: readonly StructureDto[]
  outcome: MissionOutcome | null
  activeIndex: number | null
}>()

const emit = defineEmits<{ (event: 'select', index: number): void }>()

const byId = computed(() => new Map(props.structures.map((structure) => [structure.id, structure])))

function pickedName(index: number): string | null {
  const id = props.picks[index]?.structureId ?? null
  if (id === null) return null
  return byId.value.get(id)?.name ?? null
}

function revealedName(index: number): string | null {
  const id = props.outcome?.perStep[index]?.revealedStructureId ?? null
  if (id === null) return null
  return byId.value.get(id)?.name ?? null
}

function toneFor(index: number): string {
  const step = props.outcome?.perStep[index]
  if (step === undefined) return 'border-[var(--color-border-subtle)]'

  return step.outcome === 'wrong' ? 'border-[var(--color-danger)]' : 'border-[var(--color-success)]'
}
</script>

<template>
  <ol class="space-y-2">
    <li v-for="(prompt, stepIndex) in prompts" :key="stepIndex">
      <component
        :is="outcome === null ? 'div' : 'button'"
        :type="outcome === null ? undefined : 'button'"
        class="w-full rounded-md border-l-4 border border-[var(--color-border-subtle)] px-3 py-2 text-left"
        :class="[
          toneFor(stepIndex),
          activeIndex === stepIndex ? 'bg-[var(--color-accent)]/10' : '',
          outcome === null ? '' : 'transition-colors hover:bg-[var(--color-accent)]/5',
        ]"
        :aria-current="activeIndex === stepIndex ? 'step' : undefined"
        @click="outcome === null ? undefined : emit('select', stepIndex)"
      >
        <p class="text-[0.6875rem] uppercase tracking-wide text-[var(--color-ink-muted)]">
          Step {{ stepIndex + 1 }}
          <template v-if="outcome?.perStep[stepIndex]">
            · {{ outcome.perStep[stepIndex]?.outcomeLabel }} ·
            {{ outcome.perStep[stepIndex]?.points }} pts
          </template>
        </p>

        <p class="mt-0.5 text-sm leading-snug">{{ prompt }}</p>

        <p class="mt-1 text-xs text-[var(--color-ink-muted)]">
          <template v-if="pickedName(stepIndex)">
            You chose the {{ pickedName(stepIndex) }}.
          </template>
          <template v-else-if="picks[stepIndex] !== null"> You skipped this one. </template>
          <template v-else>Not answered yet.</template>
        </p>

        <!--
          Named in text, not only ringed on the model: the ring is invisible to
          a screen reader and to anyone whose model never loaded
          (docs/architecture.md §5.4 rule 5).
        -->
        <p v-if="revealedName(stepIndex)" class="mt-1 text-xs text-[var(--color-success)]">
          It was the {{ revealedName(stepIndex) }}.
        </p>

        <p
          v-if="outcome?.perStep[stepIndex]?.explanation"
          class="mt-1 text-xs leading-relaxed text-[var(--color-ink-muted)]"
        >
          {{ outcome.perStep[stepIndex]?.explanation }}
        </p>
      </component>
    </li>
  </ol>
</template>
