<script setup lang="ts">
import { ref } from 'vue'
import { RouterLink } from 'vue-router'

import { useSessionStore } from '@/stores/session'

const session = useSessionStore()
const signingOut = ref(false)
const signOutFailed = ref(false)

// The store shares overlapping calls, but the button also shows it is busy while the request runs. It uses
// aria-disabled, not disabled (a disabled button loses focus), so a click while busy is ignored here. On success the
// page is reloading (full load of /login); on failure the user stays signed in.
async function signOut(): Promise<void> {
  if (signingOut.value) {
    return
  }
  signingOut.value = true
  signOutFailed.value = false
  try {
    signOutFailed.value = (await session.logout()) !== null
  } catch {
    signOutFailed.value = true
  } finally {
    signingOut.value = false
  }
}
</script>

<template>
  <header class="app-header">
    <p class="brand">Expense tracker</p>
    <template v-if="session.status === 'signed-in' && session.user">
      <nav aria-label="Main">
        <RouterLink to="/expenses">Expenses</RouterLink>
        <RouterLink to="/categories">Categories</RouterLink>
      </nav>
      <div class="account">
        <!-- bdi: a right-to-left address must not reorder the text around it. Interpolation only, never v-html. -->
        <span class="email" :title="session.user.email"
          ><bdi>{{ session.user.email }}</bdi></span
        >
        <button type="button" :aria-disabled="signingOut ? 'true' : undefined" @click="signOut">
          Sign out
        </button>
      </div>
      <p v-if="signOutFailed" class="sign-out-failed" role="alert">
        Sign-out failed. Check your connection and try again.
      </p>
    </template>
  </header>
</template>

<style scoped>
.app-header {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: var(--space-2) var(--space-4);
  padding: var(--space-3);
  border-bottom: 1px solid var(--color-border);
}
.brand {
  margin: 0;
  font-weight: 700;
}
nav {
  display: flex;
  gap: var(--space-3);
}
.account {
  display: flex;
  align-items: center;
  gap: var(--space-2);
  margin-inline-start: auto;
  min-width: 0;
}
.email {
  overflow: hidden;
  overflow-wrap: anywhere;
  max-height: 3em;
  line-height: 1.5;
}
.sign-out-failed {
  flex-basis: 100%;
  margin: 0;
  color: var(--color-danger);
}
</style>
