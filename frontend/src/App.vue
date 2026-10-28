<script setup lang="ts">
import { inject, ref, useTemplateRef, watch } from 'vue'
import { RouterView, useRoute, useRouter } from 'vue-router'

import ApiStatus from '@/components/ApiStatus.vue'
import AppHeader from '@/components/AppHeader.vue'
import { appErrorKey } from '@/lib/appError'
import { clearFlash, flashMessage } from '@/lib/flash'
import { redirectFor } from '@/router/guards'
import { useSessionStore } from '@/stores/session'
import ServiceUnavailableView from '@/views/ServiceUnavailableView.vue'

const session = useSessionStore()
const route = useRoute()
const router = useRouter()
const main = useTemplateRef<HTMLElement>('main')
// Provided by main.ts (installErrorHandler); without it there is simply never a banner.
const failed = inject(appErrorKey, ref(false))

// The button is about to disappear: focus goes to the page, not to the top of the document.
function dismiss(): void {
  failed.value = false
  main.value?.focus()
}

// A plain `#main` link would add a hash to the address, which the router would then carry around. Focus does the job.
function skipToMain(): void {
  main.value?.focus()
}

// A message that survived a navigation (such as "Expense saved") is dropped at the next one.
watch(() => route.path, clearFlash)
// ...and so is one that outlives the user it was meant for (a sign-out, or another account signing in).
watch(() => session.user?.id, clearFlash, { flush: 'sync' })

// The guard redirects on navigation. This covers the other case: the session changes while the user stays on the page
// (a sign-in just succeeded on /login, or a sign-out is under way). Hiding the page below is not enough, since nothing
// else would leave it blank. It cannot fight the guard or loop: it skips while the status is not known, and while the
// first navigation has not resolved a route yet (the guard owns that one); after a redirect the new route needs none.
watch([() => route.fullPath, () => session.status], () => {
  if (
    session.status === 'unknown' ||
    session.status === 'unreachable' ||
    route.matched.length === 0
  ) {
    return
  }
  const target = redirectFor(route, session.status)
  if (target !== true) {
    void router.replace(target)
  }
})
</script>

<template>
  <a class="skip-link" href="#main" @click.prevent="skipToMain">Skip to main content</a>
  <AppHeader />
  <p v-if="failed" class="error-banner" role="alert">
    Something went wrong. Reload the page and try again.
    <button type="button" @click="dismiss">Dismiss</button>
  </p>
  <div class="flash" aria-live="polite">{{ flashMessage }}</div>
  <main id="main" ref="main" tabindex="-1">
    <ServiceUnavailableView v-if="session.status === 'unreachable'" />
    <!-- Nothing is drawn for a route the guard would redirect: e.g. right after Retry finds the user signed out, a
         private view must not mount for a moment (it would call the API and turn a clean redirect into "session
         expired"). -->
    <RouterView v-else-if="redirectFor(route, session.status) === true" />
  </main>
  <footer class="app-footer"><ApiStatus /></footer>
</template>
