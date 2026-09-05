import { defineConfig } from 'vitest/config'
import vue from '@vitejs/plugin-vue'
import { fileURLToPath, URL } from 'node:url'

export default defineConfig({
  // Added by Handover 05. Without it Vitest cannot parse a .vue file at all,
  // so no page or component in resources/js/Pages or Components is testable —
  // including the assertion that unmounting a page disposes the viewer
  // (docs/architecture.md §5.4 rule 6).
  plugins: [vue()],

  resolve: {
    alias: {
      '@': fileURLToPath(new URL('./resources/js', import.meta.url)),
    },
  },
  test: {
    // jsdom rather than node: the viewer touches document and window even in
    // the unit tests that never create a WebGL context.
    environment: 'jsdom',
    include: ['resources/js/**/*.test.ts'],
    globals: true,
  },
})
