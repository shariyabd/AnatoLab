<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import TutorMessage from '@/Components/Tutor/TutorMessage.vue'
import { useTutor } from '@/composables/useTutor'
import type { TutorSubject } from '@/types/tutor'

/**
 * The AI tutor, as a self-contained component.
 *
 * Handover 05 owns Explore.vue and is building it in parallel, so this ships
 * mounted on its own route and drops into the named slot Explore leaves for it
 * (docs/handovers/parallel-execution-plan.md D3). It takes its subject entirely
 * through props: give it the selected organ and structure and it works
 * anywhere, with no knowledge of the viewer, Explore, or a lesson.
 *
 * It never renders a status code, a provider name, or a stack trace. An outage
 * shows a sentence and leaves everything around it working (PRD §40).
 */
const props = withDefaults(
  defineProps<{
    organSlug?: string | null
    organName?: string | null
    structureId?: number | null
    structureName?: string | null
    /** Rendered in the header; the host page usually knows a better one. */
    heading?: string
  }>(),
  {
    organSlug: null,
    organName: null,
    structureId: null,
    structureName: null,
    heading: 'Anatomy tutor',
  },
)

const subject = (): TutorSubject => ({
  organSlug: props.organSlug,
  organName: props.organName,
  structureId: props.structureId,
  structureName: props.structureName,
})

const { turns, isThinking, error, reset, ask, explain, hint } = useTutor(subject)

const draft = ref('')

const focus = computed(() => props.structureName ?? props.organName ?? null)
const canExplain = computed(() => props.structureId !== null || props.organSlug !== null)
const canSend = computed(() => draft.value.trim().length >= 3 && !isThinking.value)

// A thread is about one structure. Carrying the transcript across a change of
// selection would hand the model history about the wrong thing.
watch(
  () => [props.organSlug, props.structureId],
  () => reset(),
)

async function submit(): Promise<void> {
  if (!canSend.value) return

  const question = draft.value.trim()
  draft.value = ''
  await ask(question)
}
</script>

<template>
  <section
    class="flex h-full flex-col rounded-lg border border-[var(--color-border-subtle)] bg-[var(--color-surface)]"
    aria-label="Anatomy tutor"
  >
    <header class="border-b border-[var(--color-border-subtle)] px-4 py-3">
      <h2 class="text-sm font-semibold tracking-tight">{{ heading }}</h2>
      <p class="mt-0.5 text-xs text-[var(--color-ink-muted)]">
        <template v-if="focus">Asking about {{ focus }}</template>
        <template v-else>Select a structure to give the tutor context</template>
      </p>
    </header>

    <div class="flex-1 overflow-y-auto px-4 py-3">
      <p v-if="turns.length === 0" class="text-sm text-[var(--color-ink-muted)]">
        Ask why something is shaped the way it is, what it does, or how it connects to the rest of
        the organ. The tutor explains how the body works — it is not a doctor and cannot answer
        questions about your own health.
      </p>

      <ul v-else class="space-y-3">
        <TutorMessage
          v-for="turn in turns"
          :key="turn.key"
          :turn="turn"
          @follow-up="(question) => ask(question)"
        />
      </ul>

      <p v-if="isThinking" class="mt-3 text-sm text-[var(--color-ink-muted)]" aria-live="polite">
        Thinking…
      </p>

      <p
        v-if="error"
        class="mt-3 rounded-md border border-[var(--color-danger)] px-3 py-2 text-sm text-[var(--color-danger)]"
        role="alert"
      >
        {{ error }}
      </p>
    </div>

    <footer class="border-t border-[var(--color-border-subtle)] px-4 py-3">
      <div class="mb-2 flex flex-wrap gap-2">
        <button
          type="button"
          class="rounded-md border border-[var(--color-border-subtle)] px-3 py-1.5 text-xs disabled:opacity-50"
          :disabled="!canExplain || isThinking"
          @click="explain()"
        >
          Explain this
        </button>
        <button
          type="button"
          class="rounded-md border border-[var(--color-border-subtle)] px-3 py-1.5 text-xs disabled:opacity-50"
          :disabled="!canExplain || isThinking"
          @click="hint(draft.trim())"
        >
          Give me a hint
        </button>
      </div>

      <form class="flex gap-2" @submit.prevent="submit">
        <label class="sr-only" for="tutor-question">Ask the tutor a question</label>
        <input
          id="tutor-question"
          v-model="draft"
          type="text"
          maxlength="500"
          autocomplete="off"
          placeholder="Why is its wall thicker?"
          class="min-w-0 flex-1 rounded-md border border-[var(--color-border-strong)] bg-[var(--color-surface-raised)] px-3 py-2 text-sm"
        />
        <button
          type="submit"
          class="rounded-md bg-[var(--color-accent)] px-3 py-2 text-sm font-medium text-[var(--color-accent-ink)] disabled:opacity-50"
          :disabled="!canSend"
        >
          Ask
        </button>
      </form>
    </footer>
  </section>
</template>
