export interface AuthUser {
  id: number
  name: string
  email: string
  role: 'student' | 'admin'
  educationLevel: string
  difficultyPreference: string
  xp: number
  level: number
}

export interface NavItem {
  key: string
  label: string
  href: string
  icon: string | null
}

/**
 * Props HandleInertiaRequests shares with every page.
 *
 * Mirrors app/Http/Middleware/HandleInertiaRequests::share(). Adding a shared
 * prop means changing both — the two-way contract rule applies here as it does
 * to the viewer DTOs (docs/feature-plan.md §7.8).
 *
 * Declared as an augmentation of Inertia's own PageProps so `usePage()` is
 * typed everywhere without each call site repeating a generic. Note this does
 * NOT extend PageProps: PageProps is what we are adding to, and extending it
 * here would be circular.
 */
declare module '@inertiajs/core' {
  interface PageProps {
    auth: { user: AuthUser | null }
    navigation: Record<string, NavItem[]>
    flash: { success: string | null; error: string | null }
    /** Kept in step with the meta tag by app.ts; see HandleInertiaRequests. */
    csrfToken: string
  }
}
