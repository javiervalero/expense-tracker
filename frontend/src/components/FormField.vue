<script setup lang="ts">
import { computed, useAttrs } from 'vue'

// Attributes such as required, maxlength and name belong to the control, not to the wrapper div.
defineOptions({ inheritAttrs: false })
const attrs = useAttrs()

const props = defineProps<{
  /** Unique on the page; the message ids are derived from it. */
  id: string
  label: string
  hint?: string
  error?: string
  type?: string
  autocomplete?: string
  inputmode?: 'decimal' | 'text' | 'search'
}>()
const value = defineModel<string>({ default: '' })

const hintId = computed(() => `${props.id}-hint`)
const errorId = computed(() => `${props.id}-error`)
// A message is linked only while it is shown, so the attribute never points at nothing.
const describedby = computed(() => {
  const ids = [props.hint ? hintId.value : '', props.error ? errorId.value : ''].filter(Boolean)
  return ids.length > 0 ? ids.join(' ') : undefined
})
const invalid = computed(() => (props.error ? 'true' : undefined))
</script>

<template>
  <div class="form-field">
    <label :for="id">{{ label }}</label>
    <!-- The slot replaces the input (a select, a date); it gets the same wiring. -->
    <slot :id="id" :describedby="describedby" :invalid="invalid" :attrs="attrs">
      <input
        :id="id"
        v-model="value"
        :type="type ?? 'text'"
        :autocomplete="autocomplete"
        :inputmode="inputmode"
        :aria-describedby="describedby"
        :aria-invalid="invalid"
        v-bind="attrs"
      />
    </slot>
    <p v-if="hint" :id="hintId" class="form-field-hint">{{ hint }}</p>
    <p v-if="error" :id="errorId" class="form-field-error">{{ error }}</p>
  </div>
</template>

<style scoped>
.form-field {
  display: flex;
  flex-direction: column;
  gap: var(--space-1);
  margin-bottom: var(--space-3);
}
.form-field-hint,
.form-field-error {
  margin: 0;
  font-size: 0.875rem;
  overflow-wrap: anywhere;
}
.form-field-hint {
  color: var(--color-muted);
}
.form-field-error {
  color: var(--color-danger);
}
</style>
