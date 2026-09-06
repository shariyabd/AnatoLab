<script setup lang="ts">
/**
 * The letterspaced small-caps label — handover 15 Phase 0.
 *
 * `ORGAN LIBRARY`, `KEY FACTS`, `THE HEART`, `3D SPECIMEN · Heart`. It recurs
 * in the library panel header, the info panel's section heads, the canvas
 * specimen label and the callout, so it is built once here rather than
 * reassembled from four nearly-identical utility strings.
 *
 * The text is written in normal case and uppercased by CSS. Screen readers
 * announce the DOM text, so `Organ library` is read as a phrase while
 * `ORGAN LIBRARY` risks being spelled out letter by letter.
 *
 * `tag` exists because the same motif is a heading in the library and info
 * panels and a caption on the canvas. A caption marked up as an `h3` puts a
 * phantom entry in the document outline; a heading marked up as a `span` takes
 * one away.
 */
withDefaults(
  defineProps<{
    /** Semantic element. `span` for a caption, `h2`/`h3` where it heads a section. */
    tag?: string
    /** A body system's accent, drawn as the leading dot. Any CSS colour. */
    dotColor?: string | null
  }>(),
  {
    tag: 'span',
    dotColor: null,
  },
)
</script>

<template>
  <component
    :is="tag"
    class="flex items-center gap-2 text-label uppercase text-[var(--color-ink-soft)]"
  >
    <span
      v-if="dotColor !== null"
      class="size-1.5 shrink-0 rounded-full"
      :style="{ backgroundColor: dotColor }"
      aria-hidden="true"
    />
    <slot />
  </component>
</template>
