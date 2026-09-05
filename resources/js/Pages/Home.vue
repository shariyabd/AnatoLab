<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3'

/**
 * The landing page — the first thing a judge sees (handover 14, acceptance
 * criterion 6: "a judge understands the product without a technical
 * explanation").
 *
 * So it describes what a student *does*, not what the platform *is*. There is
 * no feature list here and no technology named: "ask why the left ventricle's
 * wall is thicker and get an answer with its sources" says more about the
 * product than "RAG-grounded AI tutor" does, and it is the same sentence a
 * 15-year-old and a judge can both act on.
 *
 * Static copy and two links. It queries nothing (invariant 8) and is reachable
 * without an account, as is the attribution page it sits above.
 */
const steps = [
  {
    title: 'Handle the anatomy',
    body: 'Turn an organ over, pick out a chamber, isolate it, and read what it does and what it connects to. Every structure has a stable identity, so everything else in the app can talk about the one you are looking at.',
  },
  {
    title: 'Ask about what you selected',
    body: 'The tutor already knows which organ is open and which structure is highlighted. Ask why a wall is thicker and the answer comes back at your reading level, with the passages it was drawn from.',
  },
  {
    title: 'Be assessed in three dimensions',
    body: 'Not "which chamber receives oxygenated blood" from a list, but find it on the model. Trace a blood path in the right order. Change one thing and watch what happens downstream.',
  },
  {
    title: 'See what you actually know',
    body: 'Mastery is tracked per body system from how you answer, not from how much you clicked, and the next thing to study is chosen from where you are weakest.',
  },
]
</script>

<template>
  <Head title="Learn anatomy in 3D" />

  <div class="mx-auto max-w-3xl">
    <section class="py-12 text-center sm:py-16">
      <h1 class="text-4xl font-bold tracking-tight sm:text-5xl">AnatoLab</h1>

      <p class="mx-auto mt-4 max-w-xl text-lg leading-relaxed text-[var(--color-ink-muted)]">
        Learn the human body by taking it apart. Explore organs in 3D, ask a tutor that knows what
        you are looking at, and be tested on where things are — not just what they are called.
      </p>

      <div class="mt-8 flex flex-wrap justify-center gap-3">
        <Link
          href="/register"
          class="rounded-md bg-[var(--color-accent)] px-5 py-2.5 font-medium text-[var(--color-accent-ink)]"
        >
          Get started
        </Link>
        <Link
          href="/login"
          class="rounded-md border border-[var(--color-border-strong)] px-5 py-2.5 font-medium"
        >
          Log in
        </Link>
      </div>

      <p class="mt-4 text-xs text-[var(--color-ink-muted)]">
        Free, and built for students aged 13–18.
      </p>
    </section>

    <section
      aria-labelledby="how-it-works"
      class="border-t border-[var(--color-border-subtle)] py-12"
    >
      <h2
        id="how-it-works"
        class="text-center text-sm font-semibold uppercase tracking-wide text-[var(--color-ink-muted)]"
      >
        How it works
      </h2>

      <ol class="mt-8 grid gap-6 sm:grid-cols-2">
        <li v-for="(step, index) in steps" :key="step.title">
          <h3 class="flex items-baseline gap-2 text-base font-semibold">
            <span class="text-[var(--color-accent)]">{{ index + 1 }}</span>
            {{ step.title }}
          </h3>
          <p class="mt-2 text-sm leading-relaxed text-[var(--color-ink-muted)]">{{ step.body }}</p>
        </li>
      </ol>
    </section>

    <!--
      Said here rather than only on the attribution page. The licence question
      is the honest headline fact about this build, and a judge should meet it
      in the product rather than discover it (docs/licence-log.md §4, PRD §42).
    -->
    <section
      aria-labelledby="assets-note"
      class="border-t border-[var(--color-border-subtle)] py-8 text-center"
    >
      <h2 id="assets-note" class="sr-only">A note on the 3D assets</h2>

      <p class="mx-auto max-w-xl text-xs leading-relaxed text-[var(--color-ink-muted)]">
        The 3D models this build was designed around have no licence permitting redistribution, so
        none ship with it and the viewer falls back to its text description of every structure.
        Everything else — lessons, questions, missions, simulations and the knowledge base — is
        original.
        <Link href="/attribution" class="text-[var(--color-accent)] underline underline-offset-2"
          >Read the asset register</Link
        >.
      </p>
    </section>
  </div>
</template>
