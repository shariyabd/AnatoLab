<script setup lang="ts">
import { computed } from 'vue'
import { Head, router } from '@inertiajs/vue3'
import LessonCard from '@/Components/Lessons/LessonCard.vue'
import type { LessonCard as LessonCardDto, LessonDifficulty, LessonFilters } from '@/types/lessons'

/**
 * The lesson library — PRD §8, handover 06.
 *
 * Filtering is a partial reload asking for `lessons` alone, with the filter
 * state in the URL. That makes a filtered library deep-linkable and shareable,
 * and it keeps the server the only thing that decides which lessons a student
 * may see — a client-side filter over a full list would mean shipping every
 * lesson to hide most of them.
 *
 * `filterOptions` comes from the server too, derived from the lessons that
 * actually exist, so the picker never offers a filter that returns nothing.
 */
const props = defineProps<{
  lessons: LessonCardDto[]
  filters: LessonFilters
  filterOptions: {
    organs: { slug: string; name: string }[]
    systems: { slug: string; name: string }[]
    difficulties: LessonDifficulty[]
  }
}>()

const hasFilters = computed(
  () =>
    props.filters.organ !== null ||
    props.filters.system !== null ||
    props.filters.difficulty !== null,
)

const completedCount = computed(
  () => props.lessons.filter((lesson) => lesson.progress?.status === 'completed').length,
)

/**
 * One place builds the query string, so an empty select clears its parameter
 * instead of sending `?organ=`.
 */
function applyFilter(key: keyof LessonFilters, value: string): void {
  const next: Record<string, string> = {}

  const merged: LessonFilters = { ...props.filters, [key]: value === '' ? null : value }

  if (merged.organ !== null) next.organ = merged.organ
  if (merged.system !== null) next.system = merged.system
  if (merged.difficulty !== null) next.difficulty = merged.difficulty

  router.visit('/lessons', {
    data: next,
    only: ['lessons', 'filters'],
    preserveState: true,
    preserveScroll: true,
  })
}

function clearFilters(): void {
  router.visit('/lessons', {
    only: ['lessons', 'filters'],
    preserveState: true,
    preserveScroll: true,
  })
}
</script>

<template>
  <Head title="Lessons" />

  <div class="space-y-5">
    <header class="flex flex-wrap items-baseline gap-x-4 gap-y-1">
      <h1 class="text-xl font-semibold tracking-tight">Lessons</h1>

      <p class="text-xs text-[var(--color-ink-muted)]">
        {{ completedCount }} of {{ lessons.length }} completed
      </p>
    </header>

    <section
      aria-label="Filter lessons"
      class="flex flex-wrap items-end gap-3 rounded-lg border border-[var(--color-border-subtle)] bg-[var(--color-surface-raised)] p-3"
    >
      <div class="space-y-1">
        <label
          for="filter-system"
          class="block text-[0.6875rem] uppercase tracking-wide text-[var(--color-ink-muted)]"
        >
          Body system
        </label>
        <select
          id="filter-system"
          class="rounded-md border border-[var(--color-border-strong)] bg-[var(--color-surface)] px-2 py-1 text-sm"
          :value="filters.system ?? ''"
          @change="applyFilter('system', ($event.target as HTMLSelectElement).value)"
        >
          <option value="">All systems</option>
          <option v-for="system in filterOptions.systems" :key="system.slug" :value="system.slug">
            {{ system.name }}
          </option>
        </select>
      </div>

      <div class="space-y-1">
        <label
          for="filter-organ"
          class="block text-[0.6875rem] uppercase tracking-wide text-[var(--color-ink-muted)]"
        >
          Organ
        </label>
        <select
          id="filter-organ"
          class="rounded-md border border-[var(--color-border-strong)] bg-[var(--color-surface)] px-2 py-1 text-sm"
          :value="filters.organ ?? ''"
          @change="applyFilter('organ', ($event.target as HTMLSelectElement).value)"
        >
          <option value="">All organs</option>
          <option v-for="organ in filterOptions.organs" :key="organ.slug" :value="organ.slug">
            {{ organ.name }}
          </option>
        </select>
      </div>

      <div class="space-y-1">
        <label
          for="filter-difficulty"
          class="block text-[0.6875rem] uppercase tracking-wide text-[var(--color-ink-muted)]"
        >
          Difficulty
        </label>
        <select
          id="filter-difficulty"
          class="rounded-md border border-[var(--color-border-strong)] bg-[var(--color-surface)] px-2 py-1 text-sm capitalize"
          :value="filters.difficulty ?? ''"
          @change="applyFilter('difficulty', ($event.target as HTMLSelectElement).value)"
        >
          <option value="">Any difficulty</option>
          <option
            v-for="difficulty in filterOptions.difficulties"
            :key="difficulty"
            :value="difficulty"
            class="capitalize"
          >
            {{ difficulty }}
          </option>
        </select>
      </div>

      <button
        v-if="hasFilters"
        type="button"
        class="rounded-md border border-[var(--color-border-subtle)] px-2 py-1 text-xs hover:bg-[var(--color-surface)]"
        @click="clearFilters"
      >
        Clear filters
      </button>
    </section>

    <ul v-if="lessons.length > 0" class="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
      <li v-for="lesson in lessons" :key="lesson.id">
        <LessonCard :lesson="lesson" />
      </li>
    </ul>

    <p v-else class="text-sm text-[var(--color-ink-muted)]">
      <template v-if="hasFilters">No lessons match those filters.</template>
      <template v-else>No lessons have been published yet.</template>
    </p>
  </div>
</template>
