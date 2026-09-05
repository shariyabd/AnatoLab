<script setup lang="ts">
import type { ExplanationPayload, LessonStep, StructureDto } from '@/types/lessons'

/** The guided explanation — PRD §8 step 3. Prose in short titled blocks. */
const props = defineProps<{ step: LessonStep; structures: readonly StructureDto[] }>()

const payload = props.step.payload as ExplanationPayload
</script>

<template>
  <div class="space-y-5">
    <section v-for="(block, index) in payload.blocks" :key="index" class="space-y-1.5">
      <h3 v-if="block.heading" class="text-sm font-semibold">{{ block.heading }}</h3>
      <p class="text-sm leading-relaxed">{{ block.body }}</p>
    </section>
  </div>
</template>
