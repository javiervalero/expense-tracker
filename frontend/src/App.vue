<script setup lang="ts">
import { watch } from 'vue'
import { RouterView, useRoute, useRouter } from 'vue-router'

import ApiStatus from '@/components/ApiStatus.vue'
import { redirectFor } from '@/router/guards'
import { useSessionStore } from '@/stores/session'
import ServiceUnavailableView from '@/views/ServiceUnavailableView.vue'

const session = useSessionStore()
const route = useRoute()
const router = useRouter()

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
  <ServiceUnavailableView v-if="session.status === 'unreachable'" />
  <!-- Nothing is drawn for a route the guard would redirect: e.g. right after Retry finds the user signed out, a private
       view must not mount for a moment (it would call the API and turn a clean redirect into "session expired"). -->
  <RouterView v-else-if="redirectFor(route, session.status) === true" />
  <ApiStatus />
</template>
