<script setup lang="ts">
/**
 * What a student sees before they have done anything (handover 14, PRD §36
 * phase 7 "onboarding").
 *
 * The dashboard is a page of numbers about work you have done. On the first
 * visit there is no work, so it is a page of zeroes — accurate, and useless as
 * a place to start. This replaces the zeroes with the three things worth doing
 * first, in the order PRD §2.3 walks them, so a new student and a judge both
 * arrive somewhere rather than at a report about nothing.
 *
 * It is presentation only: it takes no props it has to compute anything from,
 * links to routes that already exist, and adds no feature. The one rule it
 * follows is that each step says what the student will *do*, not what the
 * platform *has* — "rotate the heart and click a chamber" rather than
 * "3D viewer with structure selection".
 */
import { Link } from '@inertiajs/vue3'

defineProps<{ name: string }>()

const steps = [
  {
    href: '/explore/heart',
    kicker: 'Start here',
    title: 'Open the heart and click a chamber',
    body: 'Every structure has its own explanation — what it is, what it does, and what it connects to. Ask the tutor about whatever you have selected and it answers about that, with its sources.',
  },
  {
    href: '/lessons/blood-circulation',
    kicker: 'Then',
    title: 'Follow blood through the heart',
    body: 'A short lesson that walks the whole circuit, one step at a time, with the model beside it.',
  },
  {
    href: '/missions/trace-the-blood',
    kicker: 'Then test it',
    title: 'Trace the blood yourself',
    body: 'Three structures, in the right order, from memory. Nothing is marked right or wrong until you finish the run.',
  },
]
</script>

<template>
  <section
    aria-labelledby="first-run-heading"
    class="rounded-lg border border-[var(--color-accent)] bg-[var(--color-surface-raised)] p-5"
  >
    <h2 id="first-run-heading" class="text-lg font-semibold tracking-tight">
      Welcome, {{ name }} — here is where to start
    </h2>

    <p class="mt-1 max-w-2xl text-sm text-[var(--color-ink-muted)]">
      AnatoLab teaches anatomy by letting you handle it. Everything below takes about ten minutes
      together, and your mastery scores fill in as you go.
    </p>

    <ol class="mt-4 grid gap-3 sm:grid-cols-3">
      <li v-for="(step, index) in steps" :key="step.href">
        <Link
          :href="step.href"
          class="flex h-full flex-col rounded-md border border-[var(--color-border-subtle)] bg-[var(--color-surface)] p-4 transition-colors hover:border-[var(--color-accent)]"
        >
          <span
            class="text-[0.6875rem] font-semibold uppercase tracking-wide text-[var(--color-ink-muted)]"
          >
            {{ index + 1 }} · {{ step.kicker }}
          </span>

          <span class="mt-1 text-sm font-semibold">{{ step.title }}</span>

          <span class="mt-2 text-xs leading-relaxed text-[var(--color-ink-muted)]">
            {{ step.body }}
          </span>
        </Link>
      </li>
    </ol>
  </section>
</template>
