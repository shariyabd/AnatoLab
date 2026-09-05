<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3'
</script>

<template>
  <Head title="Learn anatomy in 3D" />

  <section class="mx-auto max-w-2xl py-16 text-center">
    <h1 class="text-4xl font-bold tracking-tight">AnatoLab</h1>
    <p class="mt-4 text-lg text-[var(--color-ink-muted)]">
      Explore the human body in 3D, take spatial quizzes, and ask a tutor that knows what you are
      looking at.
    </p>
    <div class="mt-8 flex justify-center gap-3">
      <Link
        href="/register"
        class="rounded-md bg-[var(--color-accent)] px-5 py-2.5 font-medium text-[var(--color-accent-ink)]"
      >
        Get started
      </Link>
      <Link
        href="/login"
        class="rounded-md border border-[var(--color-border-subtle)] px-5 py-2.5 font-medium"
      >
        Log in
      </Link>
    </div>
  </section>
</template>
