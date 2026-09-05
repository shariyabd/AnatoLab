<script setup lang="ts">
import { computed } from 'vue'
import { Head, usePage } from '@inertiajs/vue3'

const page = usePage()
const user = computed(() => page.props.auth.user)
</script>

<template>
  <Head title="Dashboard" />

  <h1 class="text-2xl font-semibold tracking-tight">
    Welcome back{{ user ? `, ${user.name}` : '' }}
  </h1>

  <!--
    Deliberately empty. Handover 10 owns mastery, recommendations, and activity;
    inventing placeholder statistics here would be harder to replace than an
    honest empty state, and a fake number in a demo is worse than no number.
  -->
  <p class="mt-4 text-[var(--color-ink-muted)]">
    Your progress, recommendations, and recent activity will appear here.
  </p>
</template>
