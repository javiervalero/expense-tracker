<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'

import { fetchHealth } from '@/api/health'

type State = 'loading' | 'ok' | 'error'

const state = ref<State>('loading')
const controller = new AbortController()

const label = computed(() => {
  switch (state.value) {
    case 'loading':
      return 'API: checking...'
    case 'ok':
      return 'API: ok'
    default:
      return 'API: unavailable'
  }
})

// role="status" is already a polite live region; a failure is announced at once as an alert.
const role = computed(() => (state.value === 'error' ? 'alert' : 'status'))

onMounted(async () => {
  const result = await fetchHealth(controller.signal)
  // A request cancelled by unmounting says nothing about the API: do not show it as an error.
  if (controller.signal.aborted || (result.kind === 'error' && result.reason === 'aborted')) {
    return
  }
  state.value = result.kind === 'ok' ? 'ok' : 'error'
})

onBeforeUnmount(() => {
  controller.abort()
})
</script>

<template>
  <p :role="role" :data-state="state">{{ label }}</p>
</template>
