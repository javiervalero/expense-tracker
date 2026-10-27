<script setup lang="ts">
import { RouterLink, useRouter } from 'vue-router'

import FormAlert from '@/components/FormAlert.vue'
import FormField from '@/components/FormField.vue'
import PasswordField from '@/components/PasswordField.vue'
import { PASSWORD_MIN } from '@/config'
import { useAuthForm } from '@/composables/useAuthForm'
import { DEFAULT_REDIRECT } from '@/router/redirect'

const router = useRouter()
const { email, password, passwordVisible, pending, accountExists, errors, submit } =
  useAuthForm('register')

// A new account has nowhere to return to: always the default page.
function onSubmit(): Promise<void> {
  return submit(() => router.replace(DEFAULT_REDIRECT))
}
</script>

<template>
  <section aria-labelledby="register-heading">
    <h1 id="register-heading" tabindex="-1">Create account</h1>
    <form novalidate @submit.prevent="onSubmit">
      <FormAlert ref="formAlert" :message="errors.formMessage.value" />
      <p v-if="accountExists" data-test="account-exists">
        <RouterLink to="/login">Sign in instead</RouterLink>
      </p>
      <FormField
        id="register-email"
        v-model="email"
        label="Email"
        type="email"
        autocomplete="username"
        autocapitalize="off"
        spellcheck="false"
        :error="errors.fieldErrors.value.email"
      />
      <PasswordField
        id="register-password"
        v-model="password"
        v-model:visible="passwordVisible"
        label="Password"
        autocomplete="new-password"
        :hint="`At least ${PASSWORD_MIN} characters.`"
        :error="errors.fieldErrors.value.password"
      />
      <button type="submit" class="primary" :aria-disabled="pending ? 'true' : undefined">
        {{ pending ? 'Creating account…' : 'Create account' }}
      </button>
    </form>
    <p>Already have an account? <RouterLink to="/login">Sign in</RouterLink></p>
  </section>
</template>
