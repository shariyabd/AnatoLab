<script setup lang="ts">
import type { SimulationActionDto } from '@/types/simulations'

/**
 * What the student can do to the simulation.
 *
 * Displays only (invariant 8): it renders labels it was given and emits an id.
 * It has no idea what any action does, because the page it lives on does not
 * either — the effects table never leaves the server.
 */
defineProps<{
  actions: readonly SimulationActionDto[]
  disabled: boolean
  accentColor: string
}>()

defineEmits<{
  (event: 'apply', actionId: string): void
}>()
</script>

<template>
  <section aria-labelledby="simulation-actions">
    <h2 id="simulation-actions" class="text-sm font-semibold">What happens if…</h2>

    <ul class="mt-3 space-y-2">
      <li v-for="action in actions" :key="action.id">
        <button
          type="button"
          :disabled="disabled"
          class="w-full rounded-md border border-[var(--color-border-subtle)] bg-[var(--color-surface-raised)] px-3 py-2 text-left transition-colors hover:border-[var(--color-accent)] disabled:cursor-not-allowed disabled:opacity-60"
          @click="$emit('apply', action.id)"
        >
          <span class="flex items-start gap-2">
            <span
              class="mt-1.5 h-1.5 w-1.5 shrink-0 rounded-full"
              :style="{ backgroundColor: accentColor }"
              aria-hidden="true"
            />
            <span>
              <span class="block text-sm font-medium">{{ action.label }}</span>
              <span
                v-if="action.description"
                class="mt-0.5 block text-xs leading-relaxed text-[var(--color-ink-muted)]"
              >
                {{ action.description }}
              </span>
            </span>
          </span>
        </button>
      </li>
    </ul>
  </section>
</template>
