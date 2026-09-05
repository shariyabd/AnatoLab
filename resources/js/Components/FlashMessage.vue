<script setup lang="ts">
defineProps<{
  message: string
  tone: 'success' | 'error'
}>()
</script>

<template>
  <!--
    role="status" rather than "alert": these are outcome confirmations, and an
    assertive live region interrupts a screen reader mid-sentence for what is
    usually good news.
  -->
  <div
    role="status"
    class="rounded-md border px-4 py-3 text-sm"
    :class="
      tone === 'success'
        ? 'border-[var(--color-success)] text-[var(--color-success)]'
        : 'border-[var(--color-danger)] text-[var(--color-danger)]'
    "
  >
    {{ message }}
  </div>
</template>
