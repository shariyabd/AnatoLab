<script setup lang="ts">
import { computed, type Component } from 'vue'
import ActivityStep from '@/Components/Lessons/Steps/ActivityStep.vue'
import ExplanationStep from '@/Components/Lessons/Steps/ExplanationStep.vue'
import ExplorationStep from '@/Components/Lessons/Steps/ExplorationStep.vue'
import KnowledgeCheckStep from '@/Components/Lessons/Steps/KnowledgeCheckStep.vue'
import ObjectiveStep from '@/Components/Lessons/Steps/ObjectiveStep.vue'
import ReflectionStep from '@/Components/Lessons/Steps/ReflectionStep.vue'
import type { LessonStep, LessonStepType, StructureDto, StructureId } from '@/types/lessons'

/**
 * Renders one step by looking its type up in a table.
 *
 * This is what "step rendering is data-driven" means in
 * docs/handovers/06-lessons.md: adding a seventh step type is a new component
 * and a new line in the map below, with no `v-if` chain to extend and no
 * branching in a template (invariant 8, docs/engineering.md §4).
 *
 * Every step component takes the same two props — `step` and `structures` —
 * and may emit `focus` and `highlight`. The uniform signature is what makes
 * the map possible; components that need neither prop still declare both.
 *
 * `LessonResource` drops any step whose type it does not recognise, so a
 * missing entry here cannot reach the page. The fallback exists anyway,
 * because a lesson silently one step shorter is worse than a visible gap.
 */
const props = defineProps<{ step: LessonStep; structures: readonly StructureDto[] }>()

const emit = defineEmits<{
  (event: 'focus', id: StructureId): void
  (event: 'highlight', id: StructureId | null): void
}>()

const COMPONENTS: Record<LessonStepType, Component> = {
  objective: ObjectiveStep,
  exploration: ExplorationStep,
  explanation: ExplanationStep,
  activity: ActivityStep,
  knowledge_check: KnowledgeCheckStep,
  reflection: ReflectionStep,
}

const stepComponent = computed<Component | null>(() => COMPONENTS[props.step.type] ?? null)
</script>

<template>
  <component
    :is="stepComponent"
    v-if="stepComponent"
    :step="step"
    :structures="structures"
    @focus="(id: StructureId) => emit('focus', id)"
    @highlight="(id: StructureId | null) => emit('highlight', id)"
  />

  <p v-else class="text-xs text-[var(--color-ink-muted)]">
    This step cannot be shown in this version of the app.
  </p>
</template>
