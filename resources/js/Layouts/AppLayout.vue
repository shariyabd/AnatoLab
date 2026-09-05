<script setup lang="ts">
import { computed } from 'vue'
import { Link, router, usePage } from '@inertiajs/vue3'
import NavLink from '@/Components/NavLink.vue'
import FlashMessage from '@/Components/FlashMessage.vue'
import { useTheme } from '@/composables/useTheme'

const page = usePage()
const { theme, toggle } = useTheme()

const user = computed(() => page.props.auth.user)

/**
 * Rendered straight from config/navigation.php. Features append an entry there
 * and never touch this file, which is what stops two lanes colliding in a Vue
 * component (docs/feature-plan.md §7.4).
 */
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
  <div class="min-h-screen">
    <!-- First tab stop on every page: skip the nav, reach the content. -->
    <a href="#main-content" class="skip-link">Skip to main content</a>

    <header class="border-b border-[var(--color-border-subtle)] bg-[var(--color-surface-raised)]">
      <nav class="mx-auto flex max-w-6xl items-center gap-4 px-4 py-3" aria-label="Main">
        <Link href="/" class="text-lg font-semibold tracking-tight"> AnatoLab </Link>

        <ul v-if="mainNav.length > 0" class="flex items-center gap-1">
          <li v-for="item in mainNav" :key="item.key">
            <NavLink :href="item.href" :active="isActive(item.href)">
              {{ item.label }}
            </NavLink>
          </li>
        </ul>

        <ul v-if="adminNav.length > 0" class="flex items-center gap-1" aria-label="Admin">
          <li v-for="item in adminNav" :key="item.key">
            <NavLink :href="item.href" :active="isActive(item.href)">
              {{ item.label }}
            </NavLink>
          </li>
        </ul>

        <div class="ml-auto flex items-center gap-3">
          <button
            type="button"
            class="rounded-md px-3 py-2 text-sm text-[var(--color-ink-muted)] hover:text-[var(--color-ink)]"
            :aria-label="theme === 'dark' ? 'Switch to light theme' : 'Switch to dark theme'"
            @click="toggle"
          >
            {{ theme === 'dark' ? 'Light' : 'Dark' }}
          </button>

          <template v-if="user">
            <span class="text-sm text-[var(--color-ink-muted)]">
              {{ user.name }}
            </span>
            <button
              type="button"
              class="rounded-md px-3 py-2 text-sm font-medium text-[var(--color-ink-muted)] hover:text-[var(--color-ink)]"
              @click="logout"
            >
              Log out
            </button>
          </template>

          <template v-else>
            <NavLink href="/login" :active="isActive('/login')">Log in</NavLink>
            <NavLink href="/register" :active="isActive('/register')">Register</NavLink>
          </template>
        </div>
      </nav>
    </header>

    <main id="main-content" tabindex="-1" class="mx-auto max-w-6xl px-4 py-8">
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
  </div>
</template>
