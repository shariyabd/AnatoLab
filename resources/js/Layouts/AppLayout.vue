<script setup lang="ts">
import { computed } from 'vue'
import { Link, router, usePage } from '@inertiajs/vue3'
import NavLink from '@/Components/NavLink.vue'
import FlashMessage from '@/Components/FlashMessage.vue'

/**
 * The application shell — handover 15 Phase 1.
 *
 * Restyled, not rewired. The data flow is handover 01's and is untouched: the
 * nav still renders straight from `config/navigation.php`, so a feature adds an
 * entry there and never edits this file (docs/feature-plan.md §7.4). What
 * changed is the visual language — warm paper, a serif wordmark, pill
 * navigation — and one removal.
 *
 * **The theme toggle is gone.** The atelier language is light-first and its
 * tokens have no dark values, so the control could not have done what it said.
 * Retiring it was an explicit decision, not an oversight: `useTheme`, the
 * pre-paint script in app.blade.php and the `.dark` palette went with it.
 *
 * **There is no search field**, though the visual language calls for one. No
 * search endpoint exists anywhere in the application, and a search box that
 * searches nothing is the same failure as a tool rail button wired to nothing.
 * It goes in the moment there is something behind it.
 */
const page = usePage()

const user = computed(() => page.props.auth.user)

const mainNav = computed(() => page.props.navigation.main ?? [])
const adminNav = computed(() => page.props.navigation.admin ?? [])

const currentPath = computed(() => new URL(page.url, 'http://localhost').pathname)

function isActive(href: string): boolean {
  const path = new URL(href, 'http://localhost').pathname

  return currentPath.value === path || currentPath.value.startsWith(`${path}/`)
}

function logout(): void {
  router.post('/logout')
}
</script>

<template>
  <div class="flex min-h-screen flex-col bg-[var(--color-paper)] text-[var(--color-ink)]">
    <!-- First tab stop on every page: skip the nav, reach the content. -->
    <a href="#main-content" class="skip-link">Skip to main content</a>

    <header class="border-b border-[var(--color-hairline)] bg-[var(--color-surface)]">
      <nav
        class="mx-auto flex max-w-7xl flex-wrap items-center gap-x-5 gap-y-3 px-5 py-4"
        aria-label="Main"
      >
        <!--
          The tagline is part of the identity rather than decoration, so it sits
          on the baseline beside the wordmark instead of under it. Hidden below
          `sm`, where it would wrap and push the nav onto a third row.
        -->
        <Link href="/" class="flex items-baseline gap-2.5">
          <span class="font-display text-[1.5rem] leading-none font-semibold">AnatoLab</span>
          <span
            class="hidden font-body text-[0.9375rem] italic text-[var(--color-ink-muted)] sm:inline"
          >
            anatomy, in three dimensions
          </span>
        </Link>

        <ul v-if="mainNav.length > 0" class="flex flex-wrap items-center gap-0.5">
          <li v-for="item in mainNav" :key="item.key">
            <NavLink :href="item.href" :active="isActive(item.href)" :icon="item.icon">
              {{ item.label }}
            </NavLink>
          </li>
        </ul>

        <ul
          v-if="adminNav.length > 0"
          class="flex flex-wrap items-center gap-0.5 border-l border-[var(--color-hairline)] pl-4"
          aria-label="Admin"
        >
          <li v-for="item in adminNav" :key="item.key">
            <NavLink :href="item.href" :active="isActive(item.href)" :icon="item.icon">
              {{ item.label }}
            </NavLink>
          </li>
        </ul>

        <div class="ml-auto flex items-center gap-2">
          <template v-if="user">
            <span class="hidden text-ui text-[var(--color-ink-soft)] sm:inline">
              {{ user.name }}
            </span>
            <button
              type="button"
              class="rounded-full px-3.5 py-2 text-ui font-medium text-[var(--color-ink-soft)] transition-colors hover:bg-[var(--color-surface-sunk)] hover:text-[var(--color-ink)]"
              @click="logout"
            >
              Log out
            </button>
          </template>

          <template v-else>
            <NavLink href="/login" :active="isActive('/login')">Log in</NavLink>
            <Link
              href="/register"
              class="rounded-full bg-[var(--color-accent)] px-4 py-2 text-ui font-medium text-[var(--color-ink)] transition-opacity hover:opacity-90"
            >
              Register
            </Link>
          </template>
        </div>
      </nav>
    </header>

    <main id="main-content" tabindex="-1" class="mx-auto w-full max-w-7xl flex-1 px-5 py-8">
      <div
        v-if="page.props.flash.success || page.props.flash.error"
        class="mb-6"
        aria-live="polite"
      >
        <FlashMessage
          v-if="page.props.flash.success"
          :message="page.props.flash.success"
          tone="success"
        />
        <FlashMessage
          v-if="page.props.flash.error"
          :message="page.props.flash.error"
          tone="error"
        />
      </div>

      <slot />
    </main>

    <!--
      Attribution has to be reachable from every page and without an account:
      it is where the licence position is stated, and a credit a reader cannot
      find is not a credit (PRD §42, docs/asset-register.md §6).
    -->
    <footer class="border-t border-[var(--color-hairline)]">
      <div
        class="mx-auto flex max-w-7xl flex-wrap items-center gap-x-4 gap-y-2 px-5 py-6 text-ui text-[var(--color-ink-muted)]"
      >
        <p>AnatoLab — an interactive 3D anatomy learning platform.</p>

        <Link
          href="/attribution"
          class="underline underline-offset-2 hover:text-[var(--color-ink)]"
        >
          Attribution and licences
        </Link>
      </div>
    </footer>
  </div>
</template>
