<script setup lang="ts">
import { onBeforeUnmount, onMounted, ref, useTemplateRef } from 'vue'
import { useRoute, useRouter } from 'vue-router'

import { redirectFor } from '@/router/guards'
import { useSessionStore } from '@/stores/session'

const TITLE = "Can't reach the server · Expense tracker"

const session = useSessionStore()
const route = useRoute()
const router = useRouter()
const heading = useTemplateRef<HTMLHeadingElement>('heading')
const retrying = ref(false)
const stillDown = ref(false)
let previousTitle = ''

onMounted(() => {
  previousTitle = document.title
  document.title = TITLE
  heading.value?.focus()
})
onBeforeUnmount(() => {
  document.title = previousTitle
})

// Asks the server again. The route was kept while the server was down, so once the session is known the same rules as
// the guard decide: stay (signed in), go to sign-in (signed out), or stay on this page (still down). The button is
// aria-disabled, not disabled (a disabled button loses focus), so a second click is ignored here.
async function retry(): Promise<void> {
  if (retrying.value) {
    return
  }
  retrying.value = true
  stillDown.value = false
  try {
    await session.retry()
  } finally {
    retrying.value = false
  }
  stillDown.value = session.status === 'unreachable'
  const target = redirectFor(route, session.status)
  if (target !== true) {
    await router.replace(target)
  }
}
</script>

<template>
  <section>
    <h1 ref="heading" tabindex="-1">Can't reach the server</h1>
    <p>Check your connection and try again. Your data is safe.</p>
    <p role="status">
      {{ stillDown ? "Still can't reach the server. Try again in a moment." : '' }}
    </p>
    <button type="button" :aria-disabled="retrying ? 'true' : undefined" @click="retry">
      Retry
    </button>
  </section>
</template>
