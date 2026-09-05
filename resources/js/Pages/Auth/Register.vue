<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3'

interface Option {
  value: string
  label: string
}

const props = defineProps<{
  educationLevels: Option[]
  difficultyPreferences: Option[]
}>()

const form = useForm({
  name: '',
  email: '',
  password: '',
  password_confirmation: '',
  education_level: props.educationLevels[0]?.value ?? '',
  difficulty_preference: props.difficultyPreferences[0]?.value ?? '',
})

function submit(): void {
  form.post('/register', {
    onFinish: () => form.reset('password', 'password_confirmation'),
  })
}
</script>

<template>
  <Head title="Register" />

  <div class="mx-auto max-w-sm">
    <h1 class="text-2xl font-semibold tracking-tight">Create your account</h1>

    <form class="mt-6 space-y-4" novalidate @submit.prevent="submit">
      <div>
        <label for="name" class="block text-sm font-medium">Name</label>
        <input
          id="name"
          v-model="form.name"
          type="text"
          autocomplete="name"
          required
          :aria-invalid="Boolean(form.errors.name)"
          class="mt-1 w-full rounded-md border border-[var(--color-border-subtle)] bg-[var(--color-surface-raised)] px-3 py-2"
        />
        <p v-if="form.errors.name" class="mt-1 text-sm text-[var(--color-danger)]">
          {{ form.errors.name }}
        </p>
      </div>

      <div>
        <label for="email" class="block text-sm font-medium">Email</label>
        <input
          id="email"
          v-model="form.email"
          type="email"
          autocomplete="email"
          required
          :aria-invalid="Boolean(form.errors.email)"
          class="mt-1 w-full rounded-md border border-[var(--color-border-subtle)] bg-[var(--color-surface-raised)] px-3 py-2"
        />
        <p v-if="form.errors.email" class="mt-1 text-sm text-[var(--color-danger)]">
          {{ form.errors.email }}
        </p>
      </div>

      <div>
        <label for="education_level" class="block text-sm font-medium">Education level</label>
        <select
          id="education_level"
          v-model="form.education_level"
          class="mt-1 w-full rounded-md border border-[var(--color-border-subtle)] bg-[var(--color-surface-raised)] px-3 py-2"
        >
          <option v-for="option in educationLevels" :key="option.value" :value="option.value">
            {{ option.label }}
          </option>
        </select>
        <p v-if="form.errors.education_level" class="mt-1 text-sm text-[var(--color-danger)]">
          {{ form.errors.education_level }}
        </p>
      </div>

      <div>
        <label for="difficulty_preference" class="block text-sm font-medium">
          Question difficulty
        </label>
        <select
          id="difficulty_preference"
          v-model="form.difficulty_preference"
          class="mt-1 w-full rounded-md border border-[var(--color-border-subtle)] bg-[var(--color-surface-raised)] px-3 py-2"
        >
          <option v-for="option in difficultyPreferences" :key="option.value" :value="option.value">
            {{ option.label }}
          </option>
        </select>
        <p v-if="form.errors.difficulty_preference" class="mt-1 text-sm text-[var(--color-danger)]">
          {{ form.errors.difficulty_preference }}
        </p>
      </div>

      <div>
        <label for="password" class="block text-sm font-medium">Password</label>
        <input
          id="password"
          v-model="form.password"
          type="password"
          autocomplete="new-password"
          required
          :aria-invalid="Boolean(form.errors.password)"
          :aria-describedby="form.errors.password ? 'password-error' : 'password-hint'"
          class="mt-1 w-full rounded-md border border-[var(--color-border-subtle)] bg-[var(--color-surface-raised)] px-3 py-2"
        />
        <p id="password-hint" class="mt-1 text-sm text-[var(--color-ink-muted)]">
          At least 10 characters.
        </p>
        <p
          v-if="form.errors.password"
          id="password-error"
          class="mt-1 text-sm text-[var(--color-danger)]"
        >
          {{ form.errors.password }}
        </p>
      </div>

      <div>
        <label for="password_confirmation" class="block text-sm font-medium">
          Confirm password
        </label>
        <input
          id="password_confirmation"
          v-model="form.password_confirmation"
          type="password"
          autocomplete="new-password"
          required
          class="mt-1 w-full rounded-md border border-[var(--color-border-subtle)] bg-[var(--color-surface-raised)] px-3 py-2"
        />
      </div>

      <button
        type="submit"
        :disabled="form.processing"
        class="w-full rounded-md bg-[var(--color-accent)] px-4 py-2.5 font-medium text-[var(--color-accent-ink)] disabled:opacity-60"
      >
        {{ form.processing ? 'Creating account…' : 'Create account' }}
      </button>
    </form>

    <p class="mt-6 text-sm text-[var(--color-ink-muted)]">
      Already registered?
      <Link href="/login" class="underline">Log in</Link>
    </p>
  </div>
</template>
