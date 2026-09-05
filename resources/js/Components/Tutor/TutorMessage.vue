<script setup lang="ts">
import TutorSources from '@/Components/Tutor/TutorSources.vue'
import type { TutorTurn } from '@/types/tutor'

/**
 * One turn in the transcript.
 *
 * Display only — it queries nothing and decides nothing (invariant 8). The
 * follow-up chips emit rather than sending, so the panel stays the single place
 * that talks to the server.
 */
defineProps<{ turn: TutorTurn }>()

defineEmits<{ (event: 'followUp', question: string): void }>()
</script>

<template>
  <li
    class="rounded-lg px-3 py-2"
    :class="
      turn.role === 'user'
        ? 'bg-[var(--color-accent)] text-[var(--color-accent-ink)]'
        : 'bg-[var(--color-surface-raised)] text-[var(--color-ink)]'
    "
  >
    <p class="text-xs font-medium opacity-70">
      {{ turn.role === 'user' ? 'You' : 'Tutor' }}
    </p>

    <p class="mt-1 text-sm whitespace-pre-line">{{ turn.content }}</p>

    <TutorSources
      v-if="turn.role === 'assistant'"
      :sources="turn.sources ?? []"
      :source-note="turn.sourceNote ?? null"
    />

    <div v-if="turn.followUps && turn.followUps.length > 0" class="mt-3 flex flex-wrap gap-2">
      <button
        v-for="question in turn.followUps"
        :key="question"
        type="button"
        class="rounded-full border border-[var(--color-border-subtle)] px-3 py-1 text-xs text-[var(--color-ink-muted)] hover:text-[var(--color-ink)]"
        @click="$emit('followUp', question)"
      >
        {{ question }}
      </button>
    </div>
  </li>
</template>
