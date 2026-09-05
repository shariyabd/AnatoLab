<script setup lang="ts">
import type { TutorSource } from '@/types/tutor'

/**
 * Where an answer came from.
 *
 * Renders citations when there are any and the tutor's own note when there are
 * not. `sources` is empty until Handover 11 lands retrieval, so today this
 * component almost always shows the note — which is the honest behaviour, not a
 * placeholder: an uncited answer presented as if it were cited is the failure
 * mode PRD §24 is guarding against.
 */
defineProps<{
  sources: readonly TutorSource[]
  sourceNote: string | null
}>()
</script>

<template>
  <div class="mt-3 border-t border-[var(--color-border-subtle)] pt-3 text-xs">
    <template v-if="sources.length > 0">
      <p class="font-medium text-[var(--color-ink-muted)]">Sources</p>
      <ul class="mt-1 space-y-1">
        <li v-for="source in sources" :key="source.id">
          <span class="font-medium">{{ source.title }}</span>
          <span class="text-[var(--color-ink-muted)]"> — {{ source.excerpt }}</span>
        </li>
      </ul>
    </template>

    <p v-else-if="sourceNote" class="text-[var(--color-ink-muted)]">
      {{ sourceNote }}
    </p>
  </div>
</template>
