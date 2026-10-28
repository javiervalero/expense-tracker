<script setup lang="ts">
import { computed } from 'vue'
import { RouterLink, useRoute, useRouter } from 'vue-router'

import FormAlert from '@/components/FormAlert.vue'
import FormField from '@/components/FormField.vue'
import PasswordField from '@/components/PasswordField.vue'
import { useAuthForm } from '@/composables/useAuthForm'
import { safeRedirect } from '@/router/redirect'

const route = useRoute()
const router = useRouter()
const { email, password, passwordVisible, pending, errors, submit } = useAuthForm('login')

// The guard sends this value only after a 401 from a secured call, and it is fixed text: the query is read for an exact
// match and never shown. Any other value (or a repeated parameter, which is an array) shows nothing.
const sessionEnded = computed(() => route.query.reason === 'expired')

// The place to go is worked out now, before anything is awaited, from the address the user submitted from. Only the
// router's own resolved path of a private route is used, never the raw value (plan phase-4 C7). No "is the view still
// here" check after the await, on purpose: the session store signs the user in first, and App then unmounts this guest
// page, so the view is always gone by then and such a check would drop the redirect.
function onSubmit(): Promise<void> {
  const target = safeRedirect(route.query.redirect, router)
  return submit(() => router.replace(target))
}
</script>

<template>
  <section aria-labelledby="login-heading">
    <h1 id="login-heading" tabindex="-1">Sign in</h1>
    <p v-if="sessionEnded" class="notice" role="status">Your session ended. Sign in again.</p>
    <form novalidate @submit.prevent="onSubmit">
      <FormAlert ref="formAlert" :message="errors.formMessage.value" />
      <FormField
        id="login-email"
        v-model="email"
        label="Email"
        type="email"
        autocomplete="username"
        autocapitalize="off"
        spellcheck="false"
        :error="errors.fieldErrors.value.email"
      />
      <PasswordField
        id="login-password"
        v-model="password"
        v-model:visible="passwordVisible"
        label="Password"
        autocomplete="current-password"
        :error="errors.fieldErrors.value.password"
      />
      <button type="submit" class="primary" :aria-disabled="pending ? 'true' : undefined">
        {{ pending ? 'Signing in…' : 'Sign in' }}
      </button>
    </form>
    <p>No account yet? <RouterLink to="/register">Create one</RouterLink></p>
  </section>
</template>

<style scoped>
.notice {
  margin: 0 0 var(--space-3);
  padding: var(--space-2) var(--space-3);
  border: 1px solid var(--color-border);
  border-radius: var(--radius);
}
</style>
