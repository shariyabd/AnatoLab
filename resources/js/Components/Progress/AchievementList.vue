<script setup lang="ts">
import type { AchievementDto } from '@/types/progress'

/**
 * The badges a student holds (PRD §16).
 *
 * Earned badges only. There is no locked-badge strip and no "3 more to go":
 * the criteria are the server's marking scheme, and showing a student the
 * threshold for a badge they have not earned is the same instinct invariant 4
 * rules out for questions (App\Http\Resources\Progress\AchievementResource).
 */
defineProps<{ achievements: readonly AchievementDto[] }>()
</script>

<template>
  <ul v-if="achievements.length > 0" class="space-y-2">
    <li
      v-for="achievement in achievements"
      :key="achievement.slug"
      class="rounded-md border border-[var(--color-border-subtle)] px-3 py-2"
    >
      <span class="block text-sm font-medium">{{ achievement.name }}</span>
      <span class="block text-xs text-[var(--color-ink-muted)]">
        {{ achievement.description }}
      </span>
    </li>
  </ul>

  <p v-else class="text-sm text-[var(--color-ink-muted)]">
    No badges yet. Finish a lesson or answer a few questions to earn your first.
  </p>
</template>
