<script setup lang="ts">
/**
 * The auto-rotate switch — handover 15 Phase 3.
 *
 * A real switch rather than a button that reads as one, so assistive technology
 * announces its state without being told to.
 *
 * Under `prefers-reduced-motion` the viewer refuses auto-rotate outright, so
 * the switch is off *and* inert, with the reason written next to it. Leaving it
 * live would let a student turn something on that the viewer has already
 * declined — a control disagreeing with itself, which is the one thing the
 * handover's honesty rules are about.
 */
defineProps<{
  modelValue: boolean
  reducedMotion: boolean
}>()

defineEmits<{
  (event: 'update:modelValue', value: boolean): void
}>()
</script>

<template>
  <div
    class="absolute bottom-3 right-3 z-10 flex items-center gap-2.5 rounded-full bg-[var(--color-surface)] py-1.5 pl-3.5 pr-2 shadow-card"
  >
    <span class="text-ui text-[var(--color-ink-soft)]">
      {{ reducedMotion ? 'Motion reduced' : 'Auto-rotate' }}
    </span>

    <button
      type="button"
      role="switch"
      :aria-checked="modelValue"
      :aria-label="
        reducedMotion ? 'Auto-rotate, unavailable while your system reduces motion' : 'Auto-rotate'
      "
      :disabled="reducedMotion"
      class="relative h-6 w-11 shrink-0 rounded-full border transition-colors disabled:opacity-50"
      :class="
        modelValue
          ? 'border-[var(--color-accent)] bg-[var(--color-accent)]'
          : 'border-[var(--color-hairline-strong)] bg-[var(--color-surface-sunk)]'
      "
      @click="$emit('update:modelValue', !modelValue)"
    >
      <span
        class="absolute top-1/2 size-4 -translate-y-1/2 rounded-full bg-[var(--color-surface)] shadow-card transition-[left]"
        :class="modelValue ? 'left-[1.5rem]' : 'left-[0.1875rem]'"
        aria-hidden="true"
      />
    </button>
  </div>
</template>
