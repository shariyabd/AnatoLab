<script setup lang="ts">
import { ref } from 'vue'
import type { ExploreOrganCard } from '@/types/explore'

/**
 * The organ picker.
 *
 * Hovering or focusing a card warms that organ's GLB through the viewer's
 * `prefetchOrgan`, so the model is usually already decoded by the time the
 * student commits to the click. That is why `ExploreOrganResource` carries a
 * `modelUrl` the card never displays.
 *
 * Prefetch fires on `focusin` as well as `pointerenter`: a keyboard user
 * arrives at the card the same way, and there is no reason for them to wait
 * longer for the model.
 */
defineProps<{
  organs: readonly ExploreOrganCard[]
  currentSlug: string | null
}>()

/**
 * Thumbnails that 404.
 *
 * The resource sends null rather than a URL it knows is broken, but it cannot
 * know: `thumbnail_path` is a placeholder on every seeded organ until the
 * licence gate closes (docs/licence-log.md §3), so today every one of these
 * requests fails. A broken-image glyph in the picker is worse than the accent
 * swatch the card falls back to, and this is view state, not data.
 */
const broken = ref(new Set<string>())

function onThumbnailError(id: string): void {
  broken.value = new Set(broken.value).add(id)
}

defineEmits<{
  (event: 'select', organ: ExploreOrganCard): void
  (event: 'prefetch', organ: ExploreOrganCard): void
}>()
</script>

<template>
  <ul class="space-y-1" aria-label="Organs">
    <li v-for="organ in organs" :key="organ.id">
      <button
        type="button"
        class="flex w-full items-center gap-3 rounded-lg border p-2 text-left transition-colors"
        :class="
          currentSlug === organ.slug
            ? 'border-[var(--color-accent)] bg-[var(--color-surface)]'
            : 'border-transparent hover:bg-[var(--color-surface)]'
        "
        :aria-current="currentSlug === organ.slug ? 'true' : undefined"
        @click="$emit('select', organ)"
        @pointerenter="$emit('prefetch', organ)"
        @focusin="$emit('prefetch', organ)"
      >
        <img
          v-if="organ.thumbnailUrl && !broken.has(organ.id)"
          :src="organ.thumbnailUrl"
          alt=""
          class="size-10 shrink-0 rounded-md object-cover"
          loading="lazy"
          decoding="async"
          @error="onThumbnailError(organ.id)"
        />
        <span
          v-else
          class="size-10 shrink-0 rounded-md"
          :style="{ backgroundColor: organ.accentColor }"
          aria-hidden="true"
        />

        <span class="min-w-0 flex-1">
          <span class="block truncate text-sm font-medium">{{ organ.name }}</span>
          <span class="block truncate text-xs text-[var(--color-ink-muted)]">
            <template v-if="organ.bodySystem">{{ organ.bodySystem.name }}</template>
            <template v-if="organ.bodySystem && organ.structureCount !== undefined">
              &middot;
            </template>
            <template v-if="organ.structureCount !== undefined">
              {{ organ.structureCount }} structures
            </template>
          </span>
        </span>
      </button>
    </li>
  </ul>
</template>
