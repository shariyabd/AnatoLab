<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3'

const form = useForm({
  email: '',
  password: '',
  remember: false,
})

function submit(): void {
  form.post('/login', {
    onFinish: () => form.reset('password'),
  })
}
</script>

<template>
  <Head title="Log in" />

  <div class="mx-auto max-w-sm">
    <h1 class="text-2xl font-semibold tracking-tight">Log in</h1>

    <form class="mt-6 space-y-4" novalidate @submit.prevent="submit">
      <div>
        <label for="email" class="block text-sm font-medium">Email</label>
        <input
          id="email"
          v-model="form.email"
          type="email"
          autocomplete="email"
          required
          :aria-invalid="Boolean(form.errors.email)"
          :aria-describedby="form.errors.email ? 'email-error' : undefined"
          class="mt-1 w-full rounded-md border border-[var(--color-border-subtle)] bg-[var(--color-surface-raised)] px-3 py-2"
        />
        <p
          v-if="form.errors.email"
          id="email-error"
          class="mt-1 text-sm text-[var(--color-danger)]"
        >
          {{ form.errors.email }}
        </p>
      </div>

      <div>
        <label for="password" class="block text-sm font-medium">Password</label>
        <input
          id="password"
          v-model="form.password"
          type="password"
          autocomplete="current-password"
          required
          :aria-invalid="Boolean(form.errors.password)"
          :aria-describedby="form.errors.password ? 'password-error' : undefined"
          class="mt-1 w-full rounded-md border border-[var(--color-border-subtle)] bg-[var(--color-surface-raised)] px-3 py-2"
        />
        <p
          v-if="form.errors.password"
          id="password-error"
          class="mt-1 text-sm text-[var(--color-danger)]"
        >
          {{ form.errors.password }}
        </p>
      </div>

      <label class="flex items-center gap-2 text-sm">
        <input v-model="form.remember" type="checkbox" />
        Remember me
      </label>

      <button
        type="submit"
        :disabled="form.processing"
        class="w-full rounded-md bg-[var(--color-accent)] px-4 py-2.5 font-medium text-[var(--color-accent-ink)] disabled:opacity-60"
      >
        {{ form.processing ? 'Logging in…' : 'Log in' }}
      </button>
    </form>

    <p class="mt-6 text-sm text-[var(--color-ink-muted)]">
      No account?
      <Link href="/register" class="underline">Register</Link>
    </p>
  </div>
</template>
