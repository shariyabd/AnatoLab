<script setup lang="ts">
import { computed } from 'vue'
import { Head, usePage } from '@inertiajs/vue3'
import FirstRun from '@/Components/Onboarding/FirstRun.vue'
import AchievementList from '@/Components/Progress/AchievementList.vue'
import ActivityFeed from '@/Components/Progress/ActivityFeed.vue'
import LevelBadge from '@/Components/Progress/LevelBadge.vue'
import MasteryBar from '@/Components/Progress/MasteryBar.vue'
import RecommendationCard from '@/Components/Progress/RecommendationCard.vue'
import type { ProgressDto } from '@/types/progress'

/**
 * The progress dashboard — PRD §18, handover 10.
 *
 * Everything on this page is a number the server already computed. Mastery is
 * written by the `RecalculateMastery` queued job after an attempt, never during
 * a request (docs/architecture.md §10), and the recommendation's reason is
 * templated server-side. This component reads props and draws them; it derives
 * no score, fetches nothing, and asks no question (invariant 8).
 *
 * The systems table lists every published body system, including the ones at
 * 0%. A dashboard that hides what you have not started is a dashboard that
 * cannot tell you where to go next, which is exactly what PRD §15's table is
 * for.
 */
const props = defineProps<{ progress: ProgressDto }>()

const page = usePage()
const user = computed(() => page.props.auth.user)

/**
 * Nothing done yet, so there is nothing for this page to report.
 *
 * Chosen from the three counters the payload already carries rather than from
 * a new server field: "has this student done anything" is which version of the
 * page to draw, not a fact about their learning, and the page is allowed to
 * decide how to render what it was given (invariant 8).
 */
const isFirstRun = computed(
  () =>
    props.progress.quiz.attempts === 0 &&
    props.progress.lessonsCompleted === 0 &&
    props.progress.recentActivity.length === 0,
)
</script>

<template>
  <Head title="Dashboard" />

  <div class="space-y-8">
    <FirstRun v-if="isFirstRun" :name="user?.name ?? 'and welcome'" />

    <header>
      <!--
        "Welcome back" is wrong on the visit where FirstRun is showing — the
        student has not been anywhere yet, and the panel above has already
        greeted them.
      -->
      <h1 class="text-2xl font-semibold tracking-tight">
        <template v-if="isFirstRun">Your progress</template>
        <template v-else>Welcome back{{ user ? `, ${user.name}` : '' }}</template>
      </h1>

      <p class="mt-1 text-sm text-[var(--color-ink-muted)]">
        Overall mastery
        <span class="font-medium text-[var(--color-ink)]"
          >{{ Math.round(progress.overallScore) }}%</span
        >
        · {{ progress.lessonsCompleted }} lessons completed · {{ progress.quiz.correctAttempts }} of
        {{ progress.quiz.attempts }} answers correct
      </p>
    </header>

    <div class="grid gap-8 lg:grid-cols-[minmax(0,1fr)_18rem]">
      <div class="space-y-8">
        <section aria-labelledby="system-mastery-heading">
          <h2 id="system-mastery-heading" class="mb-3 text-sm font-semibold">System mastery</h2>

          <div v-if="progress.systems.length > 0" class="space-y-4">
            <MasteryBar v-for="system in progress.systems" :key="system.slug" :system="system" />
          </div>

          <p v-else class="text-sm text-[var(--color-ink-muted)]">
            No body systems have been published yet.
          </p>
        </section>

        <section
          v-if="progress.strongest || progress.needsPractice"
          aria-labelledby="highlights-heading"
          class="grid gap-4 sm:grid-cols-2"
        >
          <h2 id="highlights-heading" class="sr-only">Strengths and gaps</h2>

          <div
            v-if="progress.strongest"
            class="rounded-lg border border-[var(--color-border-subtle)] p-4"
          >
            <p
              class="text-[0.6875rem] font-semibold uppercase tracking-wide text-[var(--color-ink-muted)]"
            >
              Strongest
            </p>
            <p class="mt-1 text-sm font-medium">{{ progress.strongest.name }}</p>
            <p class="text-sm text-[var(--color-ink-muted)]">
              {{ Math.round(progress.strongest.score) }}%
            </p>
          </div>

          <div
            v-if="progress.needsPractice"
            class="rounded-lg border border-[var(--color-border-subtle)] p-4"
          >
            <p
              class="text-[0.6875rem] font-semibold uppercase tracking-wide text-[var(--color-ink-muted)]"
            >
              Needs practice
            </p>
            <p class="mt-1 text-sm font-medium">{{ progress.needsPractice.name }}</p>
            <p class="text-sm text-[var(--color-ink-muted)]">
              {{ Math.round(progress.needsPractice.score) }}%
            </p>
          </div>
        </section>

        <section aria-labelledby="activity-heading">
          <h2 id="activity-heading" class="mb-3 text-sm font-semibold">Recent activity</h2>
          <ActivityFeed :entries="progress.recentActivity" />
        </section>
      </div>

      <aside class="space-y-8">
        <RecommendationCard
          v-if="progress.recommendation"
          :recommendation="progress.recommendation"
        />

        <section aria-labelledby="level-heading">
          <h2 id="level-heading" class="mb-3 text-sm font-semibold">Your level</h2>
          <LevelBadge :gamification="progress.gamification" />
        </section>

        <section aria-labelledby="badges-heading">
          <h2 id="badges-heading" class="mb-3 text-sm font-semibold">Badges</h2>
          <AchievementList :achievements="progress.gamification.achievements" />
        </section>
      </aside>
    </div>
  </div>
</template>
