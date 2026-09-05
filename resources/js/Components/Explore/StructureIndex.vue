<script setup lang="ts">
import type { StructureDto, StructureId } from '@/types/explore'

/**
 * The hotspot dots, mirrored as a real list of buttons.
 *
 * This is how every structure is reachable and selectable without a pointer,
 * and it is the same list that carries the page when there is no 3D at all
 * (docs/architecture.md §5.4 rule 5). It is not a fallback that appears on
 * failure — it is always on screen, because a keyboard user should not have to
 * trigger an error to get an equivalent.
 *
 * The viewer builds its own mirror of the markers inside its container for
 * hosts that do not provide one. This page does provide one, so that mirror is
 * hidden by ViewerStage: two lists of the same structures would be two tab
 * stops per structure and two things for a screen reader to read out.
 */
defineProps<{
  structures: readonly StructureDto[]
  selectedId: StructureId | null
  accentColor: string
}>()

defineEmits<{
  (event: 'select', id: StructureId): void
  (event: 'hover', id: StructureId | null): void
}>()
</script>

<template>
  <ul class="space-y-0.5" aria-label="Structures in this organ">
    <li v-for="structure in structures" :key="structure.id">
      <button
        type="button"
        class="flex w-full items-start gap-2 rounded-md px-2 py-1.5 text-left text-xs hover:bg-[var(--color-surface)]"
        :class="
          selectedId === structure.id
            ? 'bg-[var(--color-surface)] font-semibold text-[var(--color-ink)]'
            : 'text-[var(--color-ink-muted)]'
        "
        :aria-pressed="selectedId === structure.id"
        @click="$emit('select', structure.id)"
        @mouseenter="$emit('hover', structure.id)"
        @mouseleave="$emit('hover', null)"
        @focus="$emit('hover', structure.id)"
        @blur="$emit('hover', null)"
      >
        <span
          class="mt-1 size-2 shrink-0 rounded-full"
          :style="{ backgroundColor: structure.markerColor ?? accentColor }"
          aria-hidden="true"
        />
        <!--
          Stacked rather than side by side: a TA term is often longer than the
          English name, and sharing one line clips both.
        -->
        <span class="min-w-0 flex-1">
          <span class="block truncate">{{ structure.name }}</span>
          <span v-if="structure.taTerm" class="block truncate text-[0.6875rem] italic opacity-70">
            {{ structure.taTerm }}
          </span>
        </span>
      </button>
    </li>
  </ul>
</template>
