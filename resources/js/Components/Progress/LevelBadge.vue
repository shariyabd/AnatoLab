<script setup lang="ts">
import { computed } from 'vue'
import type { GamificationDto } from '@/types/progress'

/**
 * XP, level and streak (PRD §16).
 *
 * The level curve is the server's rule; this renders `xpIntoLevel / xpForLevel`
 * rather than recomputing it, so a change to the curve does not need a matching
 * change here (App\Services\Progress\GamificationService).
 *
 * No leaderboard and no comparison to anyone else — PRD §16 rules one out for
 * the MVP, and there is no prop here that could carry another student's number.
 */
const props = defineProps<{ gamification: GamificationDto }>()

const percent = computed(() => {
  if (props.gamification.xpForLevel <= 0) return 0

  const ratio = props.gamification.xpIntoLevel / props.gamification.xpForLevel

  return Math.min(Math.max(Math.round(ratio * 100), 0), 100)
})
</script>

<template>
  <div class="space-y-2">
    <div class="flex items-baseline justify-between gap-3">
      <span class="text-sm font-medium">Level {{ gamification.level }}</span>
      <span class="text-xs tabular-nums text-[var(--color-ink-muted)]">
        {{ gamification.xp }} XP
      </span>
    </div>

    <div
      class="h-1.5 w-full overflow-hidden rounded-full bg-[var(--color-border-subtle)]"
      role="progressbar"
      aria-label="Progress to the next level"
      :aria-valuenow="percent"
      aria-valuemin="0"
      aria-valuemax="100"
    >
      <div
        class="h-full rounded-full bg-[var(--color-accent)] transition-[width]"
        :style="{ width: `${String(percent)}%` }"
      />
    </div>

    <p class="text-xs text-[var(--color-ink-muted)]">
      <template v-if="gamification.streakDays > 0">
        {{ gamification.streakDays }}-day streak ·
      </template>
      {{ gamification.xpIntoLevel }} / {{ gamification.xpForLevel }} XP this level
    </p>
  </div>
</template>
