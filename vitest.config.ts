import { defineConfig } from 'vitest/config'
import { fileURLToPath, URL } from 'node:url'

export default defineConfig({
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
