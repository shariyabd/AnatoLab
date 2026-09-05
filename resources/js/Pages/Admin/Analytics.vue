<script setup lang="ts">
import { computed } from 'vue'
import { router } from '@inertiajs/vue3'
import AdminPage from '@/Components/Admin/AdminPage.vue'
import type { AnalyticsOverview } from '@/types/admin'

/**
 * Basic usage analytics — PRD §19, §29, §30.
 *
 * Aggregate only: nothing here identifies a student. Per-student breakdowns are
 * teacher functionality, which PRD §19 defers to Phase 2.
 *
 * Read-only. It reads `learning_events` and `learning_mastery`, which F10 owns
 * and writes; handover 13 creates no tables.
 *
 * The activity chart is inline SVG rather than a charting dependency: it is one
 * polyline over at most 90 points, and adding a library for that would need
 * approval and a licence check (docs/engineering.md §5).
 */
const props = defineProps<{ days: number; overview: AnalyticsOverview }>()

const peak = computed(() =>
  Math.max(1, ...props.overview.dailyActivity.map((point) => point.events)),
)

/** The polyline for the activity sparkline, in a 100×30 viewBox. */
const activityPath = computed(() => {
  const points = props.overview.dailyActivity

  if (points.length === 0) return ''

  return points
    .map((point, index) => {
      const x = points.length === 1 ? 0 : (index / (points.length - 1)) * 100
      const y = 30 - (point.events / peak.value) * 28

      return `${x.toFixed(2)},${y.toFixed(2)}`
    })
    .join(' ')
})

function setWindow(days: string): void {
  router.visit('/admin/analytics', {
    data: { days },
    preserveState: true,
    preserveScroll: true,
  })
}
</script>

<template>
  <AdminPage
    title="Analytics"
    :description="`Learning activity from ${overview.since} to ${overview.until}.`"
  >
    <template #actions>
      <label class="text-sm">
        <span class="text-[var(--color-ink-muted)]">Window</span>
        <select
          class="ml-2 rounded-md border border-[var(--color-border-strong)] bg-transparent px-2 py-1"
          :value="String(days)"
          @change="setWindow(($event.target as HTMLSelectElement).value)"
        >
          <option value="7">7 days</option>
          <option value="30">30 days</option>
          <option value="90">90 days</option>
        </select>
      </label>
    </template>

    <div class="grid gap-4 sm:grid-cols-3">
      <div
        class="rounded-lg border border-[var(--color-border-subtle)] bg-[var(--color-surface-raised)] p-4"
      >
        <p class="text-xs text-[var(--color-ink-muted)]">Active learners</p>
        <p class="mt-1 text-2xl font-semibold">{{ overview.activeLearners }}</p>
      </div>
      <div
        class="rounded-lg border border-[var(--color-border-subtle)] bg-[var(--color-surface-raised)] p-4"
      >
        <p class="text-xs text-[var(--color-ink-muted)]">Learning events</p>
        <p class="mt-1 text-2xl font-semibold">{{ overview.totalEvents }}</p>
      </div>
      <div
        class="rounded-lg border border-[var(--color-border-subtle)] bg-[var(--color-surface-raised)] p-4"
      >
        <p class="text-xs text-[var(--color-ink-muted)]">Questions awaiting review</p>
        <p class="mt-1 text-2xl font-semibold">{{ overview.questionsAwaitingReview }}</p>
      </div>
    </div>

    <section
      class="mt-6 rounded-lg border border-[var(--color-border-subtle)] bg-[var(--color-surface-raised)] p-4"
    >
      <h2 class="text-sm font-medium">Daily activity</h2>

      <svg
        v-if="activityPath !== ''"
        class="mt-3 h-24 w-full"
        viewBox="0 0 100 30"
        preserveAspectRatio="none"
        role="img"
        :aria-label="`Learning events per day, peaking at ${peak}`"
      >
        <polyline
          :points="activityPath"
          fill="none"
          stroke="var(--color-accent)"
          stroke-width="0.8"
          vector-effect="non-scaling-stroke"
        />
      </svg>

      <p v-else class="mt-2 text-sm text-[var(--color-ink-muted)]">No activity in this window.</p>

      <!-- The accessible equivalent of the chart above (PRD §31). -->
      <details class="mt-2">
        <summary class="cursor-pointer text-xs text-[var(--color-ink-muted)]">
          Activity as a table
        </summary>
        <ul class="mt-2 space-y-0.5 text-xs">
          <li v-for="point in overview.dailyActivity" :key="point.date">
            {{ point.date }}: {{ point.events }}
          </li>
        </ul>
      </details>
    </section>

    <div class="mt-6 grid gap-6 lg:grid-cols-2">
      <section
        class="rounded-lg border border-[var(--color-border-subtle)] bg-[var(--color-surface-raised)] p-4"
      >
        <h2 class="text-sm font-medium">Events by type</h2>

        <ul class="mt-3 space-y-1 text-sm">
          <li
            v-for="(count, type) in overview.eventCounts"
            :key="type"
            class="flex justify-between gap-4"
          >
            <span class="text-[var(--color-ink-muted)]">{{ type }}</span>
            <span class="font-medium">{{ count }}</span>
          </li>
        </ul>

        <p
          v-if="Object.keys(overview.eventCounts).length === 0"
          class="mt-2 text-sm text-[var(--color-ink-muted)]"
        >
          Nothing recorded yet.
        </p>
      </section>

      <section
        class="rounded-lg border border-[var(--color-border-subtle)] bg-[var(--color-surface-raised)] p-4"
      >
        <h2 class="text-sm font-medium">Mastery by organ</h2>
        <p class="mt-1 text-xs text-[var(--color-ink-muted)]">
          Averaged across learners who have a mastery row. The count is shown because 0.9 across two
          students and 0.9 across two hundred are different facts.
        </p>

        <ul class="mt-3 space-y-2 text-sm">
          <li v-for="row in overview.masteryByOrgan" :key="row.organ">
            <div class="flex justify-between gap-4">
              <span>{{ row.organ }}</span>
              <span class="text-[var(--color-ink-muted)]">
                {{ (row.mastery * 100).toFixed(0) }}% · {{ row.learners }}
                {{ row.learners === 1 ? 'learner' : 'learners' }}
              </span>
            </div>
            <div class="mt-1 h-1.5 rounded-full bg-[var(--color-surface-sunken)]">
              <div
                class="h-full rounded-full bg-[var(--color-accent)]"
                :style="{ width: `${Math.min(100, row.mastery * 100)}%` }"
              />
            </div>
          </li>
        </ul>

        <p
          v-if="overview.masteryByOrgan.length === 0"
          class="mt-2 text-sm text-[var(--color-ink-muted)]"
        >
          No mastery recorded yet.
        </p>
      </section>
    </div>
  </AdminPage>
</template>
