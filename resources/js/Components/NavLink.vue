<script setup lang="ts">
import { computed } from 'vue'
import { Link } from '@inertiajs/vue3'
import Icon from '@/Components/Atelier/Icon.vue'

/**
 * One navigation item — handover 15 Phase 1.
 *
 * Active state is a soft pill in `--color-accent-soft` with `--color-accent-ink`
 * text, not an underline and not a filled rectangle. The pair measures 4.70:1,
 * which is why the text token is accent-ink and never `--color-accent` itself
 * (2.93:1 — see resources/js/design/palette.ts).
 *
 * The icon is decorative: it sits beside its own label, so naming it again for
 * a screen reader would have every item read twice.
 */
const props = defineProps<{
  href: string
  active?: boolean
  icon?: string | null
}>()

const classes = computed(() =>
  props.active === true
    ? 'bg-[var(--color-accent-soft)] text-[var(--color-accent-ink)]'
    : 'text-[var(--color-ink-soft)] hover:bg-[var(--color-surface-sunk)] hover:text-[var(--color-ink)]',
)
</script>

<template>
  <Link
    :href="href"
    :aria-current="active === true ? 'page' : undefined"
    class="flex items-center gap-2 rounded-full px-3.5 py-2 text-ui font-medium transition-colors"
    :class="classes"
  >
    <Icon v-if="icon" :name="icon" class="size-4 shrink-0" />
    <slot />
  </Link>
</template>
