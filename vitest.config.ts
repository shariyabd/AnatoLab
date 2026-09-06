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
    // Added by handover 17. `scripts/` was untested, and the per-structure node
    // naming convention is the contract three branches join on — a regex that
    // nothing checks is a regex that silently stops matching.
    include: ['resources/js/**/*.test.ts', 'scripts/**/*.test.mjs'],

    // Added by Handover 15. Vitest stubs every CSS import to an empty string,
    // including `?raw`, so theme.contrast.test.ts cannot read the token layer
    // it exists to check. Scoped to theme.css rather than turned on globally:
    // no other spec imports a stylesheet, and processing app.css would put a
    // full Tailwind build inside the unit suite.
    css: { include: [/theme\.css/] },
    globals: true,
  },
})
