<script setup lang="ts">
import { computed } from 'vue'
import { Link } from '@inertiajs/vue3'

const props = defineProps<{
  href: string
  active?: boolean
}>()

const classes = computed(() =>
  props.active
    ? 'bg-[var(--color-accent)] text-[var(--color-accent-ink)]'
    : 'text-[var(--color-ink-muted)] hover:bg-[var(--color-border-subtle)] hover:text-[var(--color-ink)]',
)
</script>

<template>
  <Link
    :href="href"
    :aria-current="active ? 'page' : undefined"
    class="rounded-md px-3 py-2 text-sm font-medium transition-colors"
    :class="classes"
  >
    <slot />
  </Link>
</template>
