<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3'
import type { MissionCard } from '@/types/missions'

/**
 * The mission picker.
 *
 * Every card here is a mission a student can actually run —
 * `MissionService::listPublished()` filters on the same conditions that serve
 * the run, including that every step resolves to a published structure, so a
 * card can never open onto a 404.
 */
defineProps<{
  missions: MissionCard[]
}>()
</script>

<template>
  <Head title="Missions" />

  <div class="space-y-6">
    <div>
      <h1 class="text-xl font-semibold tracking-tight">Missions</h1>
      <p class="mt-1 text-sm text-[var(--color-ink-muted)]">
        Multi-step challenges on the model: trace a pathway, or find a set of structures. The whole
        run is checked and recorded at the end.
      </p>
    </div>

    <ul v-if="missions.length > 0" class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
      <li v-for="mission in missions" :key="mission.slug">
        <Link
          :href="`/missions/${mission.slug}`"
          class="block h-full rounded-lg border border-[var(--color-border-subtle)] bg-[var(--color-surface-raised)] p-4 transition-colors hover:border-[var(--color-accent)]"
        >
          <span
            class="block h-1 w-10 rounded-full"
            :style="{ backgroundColor: mission.organ.accentColor }"
            aria-hidden="true"
          />

          <span class="mt-3 block text-sm font-semibold">{{ mission.title }}</span>

          <span class="block text-xs text-[var(--color-ink-muted)]">
            {{ mission.organ.name }} · {{ mission.typeLabel }}
          </span>

          <span
            v-if="mission.description"
            class="mt-2 block text-xs leading-relaxed text-[var(--color-ink-muted)]"
          >
            {{ mission.description }}
          </span>

          <span class="mt-2 block text-xs text-[var(--color-ink-muted)]">
            {{ mission.stepCount }} {{ mission.stepCount === 1 ? 'step' : 'steps' }} ·
            {{ mission.maxScore }} points · level {{ mission.difficulty }}
          </span>
        </Link>
      </li>
    </ul>

    <p v-else class="text-sm text-[var(--color-ink-muted)]">No missions have been published yet.</p>
  </div>
</template>
