<script setup lang="ts">
import { computed } from 'vue'
import { Head, Link } from '@inertiajs/vue3'

const props = defineProps<{
  status: number
}>()

/**
 * Plain, non-alarming copy for a 14-year-old. No stack trace, no upstream
 * status, no provider name ever reaches this page — the handler in
 * bootstrap/app.php strips all of it (docs/architecture.md §14).
 */
const content = computed(() => {
  const map: Record<number, { title: string; body: string }> = {
    403: {
      title: 'You cannot open this page',
      body: 'Your account does not have access to it. If you think it should, ask your teacher.',
    },
    404: {
      title: 'That page is not here',
      body: 'The link may be out of date, or the page may have moved.',
    },
    419: {
      title: 'Your session expired',
      body: 'You were away a while and we signed you out to keep your account safe. Log in and try again.',
    },
    500: {
      title: 'Something went wrong on our side',
      body: 'This is not your fault. Try again in a moment.',
    },
    503: {
      title: 'AnatoLab is having a short break',
      body: 'We are doing a quick update. Check back shortly.',
    },
  }

  return (
    map[props.status] ?? {
      title: 'Something went wrong',
      body: 'Try again, or head back to your dashboard.',
    }
  )
})
</script>

<template>
  <Head :title="content.title" />

  <section class="mx-auto max-w-md py-16 text-center">
    <p class="text-sm font-medium text-[var(--color-ink-muted)]">Error {{ status }}</p>
    <h1 class="mt-2 text-2xl font-semibold tracking-tight">{{ content.title }}</h1>
    <p class="mt-3 text-[var(--color-ink-muted)]">{{ content.body }}</p>
    <Link
      href="/"
      class="mt-8 inline-block rounded-md border border-[var(--color-border-subtle)] px-5 py-2.5 font-medium"
    >
      Back to home
    </Link>
  </section>
</template>
