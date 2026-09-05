<script setup lang="ts">
import { Head } from '@inertiajs/vue3'

/**
 * Credit and licence status — PRD §42, handover 02's Definition of Done §6.
 *
 * The body of this page is `docs/asset-register.md`, converted server-side by
 * `AssetRegisterDocument`. It is rendered rather than retyped on purpose: an
 * attribution list maintained separately from the register is a list that goes
 * stale, and going stale here means crediting the wrong person or claiming a
 * right nobody granted.
 *
 * The banner above it is not decoration either. Until the register's §6 is
 * signed, no asset in it may be deployed publicly, and the page says so in the
 * first thing a reader sees rather than leaving them to infer it from a table
 * of `BLOCKED` rows. `assetsCleared` comes from `public/models/manifest.json` —
 * the same file `scripts/verify-models.mjs --release` checks — so the page
 * cannot claim clearance the release script would refuse.
 *
 * `v-html` is safe here because the HTML was produced from a repository file
 * with raw HTML stripped and unsafe link schemes disabled, and no part of it
 * comes from a request (see AssetRegisterDocument). It queries nothing and
 * derives nothing (invariant 8).
 */
defineProps<{
  register: {
    html: string
    updatedAt: string | null
    assetsCleared: boolean
    modelCount: number
  }
}>()
</script>

<template>
  <Head title="Attribution and licences" />

  <div class="mx-auto max-w-3xl space-y-8">
    <header>
      <h1 class="text-2xl font-semibold tracking-tight">Attribution and licences</h1>

      <p class="mt-2 text-sm text-[var(--color-ink-muted)]">
        Every model, illustration and dependency AnatoLab ships is recorded below, with where it
        came from and what may legally be done with it. The page renders the project's asset
        register directly, so what you are reading is the register itself and not a summary of it.
      </p>

      <p v-if="register.updatedAt" class="mt-1 text-xs text-[var(--color-ink-muted)]">
        Register last updated {{ register.updatedAt }}.
      </p>
    </header>

    <div
      v-if="!register.assetsCleared"
      role="note"
      aria-labelledby="licence-gate-heading"
      class="rounded-lg border border-[var(--color-danger)] bg-[var(--color-surface-raised)] p-4"
    >
      <h2 id="licence-gate-heading" class="text-sm font-semibold text-[var(--color-danger)]">
        Asset licensing is unresolved
      </h2>

      <p class="mt-2 text-sm text-[var(--color-ink-muted)]">
        No 3D asset in this build has been cleared for public redistribution. The upstream anatomy
        repository the models came from carries no licence, which under default copyright means all
        rights are reserved, so this build ships
        {{ register.modelCount === 0 ? 'no organ models at all' : 'its models unresolved' }}
        and the viewer falls back to its text description of each structure.
      </p>

      <p class="mt-2 text-sm text-[var(--color-ink-muted)]">
        Everything else — the lessons, the questions, the missions, the simulations and the
        knowledge base — is original material written for this project. The register's sign-off
        section records what has to be true before any asset ships.
      </p>
    </div>

    <!-- eslint-disable-next-line vue/no-v-html -- sanitised server-side; see the docblock. -->
    <article class="prose-doc" v-html="register.html" />
  </div>
</template>
