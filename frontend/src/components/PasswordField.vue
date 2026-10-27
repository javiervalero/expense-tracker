<script setup lang="ts">
import FormField from './FormField.vue'

// A password input with a "Show password" toggle (plan phase-4 C15). Nothing here touches paste, so a password manager or
// a pasted value always works (WCAG 3.3.8). The value only ever lives in the model the parent passes in. The parent can
// also own `visible` (v-model:visible) to hide the password again, for example on every submit. The browser is told not
// to spell-check, capitalise or autocorrect it, which keeps it out of the keyboard's learned words.
defineProps<{
  id: string
  label: string
  hint?: string
  error?: string
  autocomplete: 'current-password' | 'new-password'
}>()
const value = defineModel<string>({ default: '' })
const visible = defineModel<boolean>('visible', { default: false })
</script>

<template>
  <div>
    <FormField
      :id="id"
      v-model="value"
      :label="label"
      :hint="hint"
      :error="error"
      :type="visible ? 'text' : 'password'"
      :autocomplete="autocomplete"
      spellcheck="false"
      autocapitalize="none"
      autocorrect="off"
    />
    <!-- One constant label with aria-pressed: a screen reader says "Show password, toggle button, pressed". -->
    <button
      type="button"
      class="password-toggle"
      :aria-pressed="visible ? 'true' : 'false'"
      :aria-controls="id"
      @click="visible = !visible"
    >
      Show password
    </button>
  </div>
</template>

<style scoped>
.password-toggle {
  margin-bottom: var(--space-3);
}
</style>
