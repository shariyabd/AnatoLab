<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3'
import type { SimulationCard } from '@/types/simulations'

/**
 * The simulation picker (PRD §14).
 *
 * Every card is a published simulation. Nothing here parses a configuration —
 * `SimulationSummaryResource` does not send one — so one malformed simulation
 * breaks its own run and not this list.
 */
defineProps<{
  simulations: SimulationCard[]
}>()
</script>

<template>
  <Head title="Simulations" />

  <div class="space-y-6">
    <div>
      <h1 class="text-xl font-semibold tracking-tight">What happens if…</h1>
      <p class="mt-1 max-w-2xl text-sm text-[var(--color-ink-muted)]">
        Change one thing about how the body works and watch what follows. Every result is worked out
        from the simulation’s own rules, the same way every time — these are simplified teaching
        models, not medical tools.
      </p>
    </div>

    <ul v-if="simulations.length > 0" class="grid gap-3 sm:grid-cols-2">
      <li v-for="simulation in simulations" :key="simulation.slug">
        <Link
          :href="`/simulations/${simulation.slug}`"
          class="block h-full rounded-lg border border-[var(--color-border-subtle)] bg-[var(--color-surface-raised)] p-4 transition-colors hover:border-[var(--color-accent)]"
        >
          <span
            class="block h-1 w-10 rounded-full"
            :style="{ backgroundColor: simulation.organ.accentColor }"
            aria-hidden="true"
          />

          <span class="mt-3 block text-sm font-semibold">{{ simulation.title }}</span>

          <span class="mt-0.5 block text-xs text-[var(--color-ink-muted)]">
            {{ simulation.organ.name }}
          </span>

          <span
            v-if="simulation.description"
            class="mt-2 block text-xs leading-relaxed text-[var(--color-ink-muted)]"
          >
            {{ simulation.description }}
          </span>
        </Link>
      </li>
    </ul>

    <p v-else class="text-sm text-[var(--color-ink-muted)]">
      No simulations have been published yet.
    </p>
  </div>
</template>
