<script setup lang="ts">
import { useTemplateRef } from 'vue'

defineProps<{ message?: string }>()

const alert = useTemplateRef<HTMLElement>('alert')

/** For a failed submit with no invalid field: focus goes to this message (plan phase-4 C15). */
function focus(): void {
  alert.value?.focus()
}
defineExpose({ focus })
</script>

<template>
  <p v-if="message" ref="alert" class="form-alert" role="alert" tabindex="-1">{{ message }}</p>
</template>

<style scoped>
.form-alert {
  margin: 0 0 var(--space-3);
  padding: var(--space-2) var(--space-3);
  border: 1px solid var(--color-danger);
  border-radius: var(--radius);
  background: var(--color-danger-bg);
  color: var(--color-danger);
  overflow-wrap: anywhere;
}
</style>
