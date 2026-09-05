<script setup lang="ts">
import { Link } from '@inertiajs/vue3'
import type { RecommendationDto } from '@/types/progress'

/**
 * "What should I do next?", answered (PRD §18).
 *
 * `reason` is rendered exactly as it arrives. It is templated server-side from
 * the mastery breakdown and is never written by the LLM
 * (docs/architecture.md §10); composing or paraphrasing it here would put the
 * wording in a second place and quietly make that guarantee harder to check.
 */
defineProps<{ recommendation: RecommendationDto }>()
</script>

<template>
  <article
    class="rounded-lg border border-[var(--color-accent)] bg-[var(--color-surface-raised)] p-4"
  >
    <p class="text-[0.6875rem] font-semibold uppercase tracking-wide text-[var(--color-ink-muted)]">
      Recommended next
    </p>

    <h3 class="mt-1 text-base font-semibold">{{ recommendation.title }}</h3>

    <p class="mt-0.5 text-xs text-[var(--color-ink-muted)]">
      {{ recommendation.activityLabel }} · {{ recommendation.organName }}
    </p>

    <p class="mt-3 text-sm">{{ recommendation.reason }}</p>

    <Link
      :href="recommendation.href"
      class="mt-4 inline-block rounded-md bg-[var(--color-accent)] px-3 py-2 text-sm font-medium text-[var(--color-accent-ink)]"
    >
      Start {{ recommendation.activityLabel.toLowerCase() }}
    </Link>
  </article>
</template>
