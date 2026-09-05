<script setup lang="ts">
import { ref } from 'vue'
import type { LessonStep, ReflectionPayload, StructureDto } from '@/types/lessons'

/**
 * The reflection question — PRD §8 step 6.
 *
 * Deliberately local and unsaved. There is nothing to grade here (this lane
 * grades nothing at all — docs/handovers/06-lessons.md) and no table this lane
 * owns to store free text in, so the answer lives in the textarea for as long
 * as the page does. Saying so in the hint is better than implying a save that
 * never happens.
 */
const props = defineProps<{ step: LessonStep; structures: readonly StructureDto[] }>()

const payload = props.step.payload as ReflectionPayload

const answer = ref('')
const fieldId = `reflection-${String(props.step.index)}`
</script>

<template>
  <div class="space-y-3">
    <label :for="fieldId" class="block text-sm leading-relaxed">{{ payload.prompt }}</label>

    <textarea
      :id="fieldId"
      v-model="answer"
      rows="4"
      :placeholder="payload.placeholder"
      class="w-full rounded-md border border-[var(--color-border-strong)] bg-[var(--color-surface)] px-3 py-2 text-sm leading-relaxed"
    />

    <p class="text-xs text-[var(--color-ink-muted)]">
      This one is for you — nothing here is marked or stored.
    </p>
  </div>
</template>
