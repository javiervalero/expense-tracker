<script setup lang="ts">
import { nextTick, ref, useTemplateRef } from 'vue'

// `subject` (what is deleted) is added to the accessible names of Delete and Confirm, after the visible text, so that
// several of these on one page are told apart. `describedby` is the id of an element that explains the consequence.
const props = withDefaults(
  defineProps<{
    label?: string
    confirmLabel?: string
    question?: string
    disabled?: boolean
    subject?: string
    describedby?: string
  }>(),
  {
    label: 'Delete',
    confirmLabel: 'Confirm delete',
    question: 'Delete this item?',
    disabled: false,
    subject: undefined,
    describedby: undefined,
  },
)
const emit = defineEmits<{ confirm: [] }>()

function nameWith(text: string): string | undefined {
  return props.subject === undefined ? undefined : `${text} ${props.subject}`
}

// Two steps in place (plan phase-4 C13): focus goes to the safe choice when asking, and back to Delete on cancel.
const asking = ref(false)
const deleteButton = useTemplateRef<HTMLButtonElement>('deleteButton')
const cancelButton = useTemplateRef<HTMLButtonElement>('cancelButton')

// "Disabled" is `aria-disabled` plus these guards, never the native attribute: a natively disabled button cannot take
// focus, so focus restored to Delete right after a failed request would be lost (the same rule as the other buttons).
async function ask(): Promise<void> {
  if (props.disabled) {
    return
  }
  asking.value = true
  await nextTick()
  cancelButton.value?.focus()
}

async function cancel(): Promise<void> {
  if (props.disabled) {
    return
  }
  asking.value = false
  await restoreFocus()
}

// Two clicks in the same tick (before the button is replaced) must not delete twice: only the first one counts.
function confirm(): void {
  if (!asking.value || props.disabled) {
    return
  }
  asking.value = false
  emit('confirm')
  void restoreFocus()
}

// The pressed button is gone; focus would fall to the top of the page. Only then is it put back on Delete: a parent that
// moved focus elsewhere keeps it. If the parent removed this component, the ref is empty and there is nothing to do.
async function restoreFocus(): Promise<void> {
  await nextTick()
  const active = document.activeElement
  if (active === null || active === document.body) {
    deleteButton.value?.focus()
  }
}
</script>

<template>
  <span class="confirm-delete">
    <button
      v-if="!asking"
      ref="deleteButton"
      type="button"
      :aria-disabled="disabled ? 'true' : undefined"
      :aria-label="nameWith(label)"
      :aria-describedby="describedby"
      @click="ask"
    >
      {{ label }}
    </button>
    <!-- The group name is the question a screen reader hears when focus lands on Cancel. Escape cancels. -->
    <span
      v-else
      role="group"
      class="confirm-delete"
      :aria-label="question"
      @keydown.esc.stop="cancel"
    >
      <button
        type="button"
        class="danger"
        :aria-disabled="disabled ? 'true' : undefined"
        :aria-label="nameWith(confirmLabel)"
        :aria-describedby="describedby"
        @click="confirm"
      >
        {{ confirmLabel }}
      </button>
      <button
        ref="cancelButton"
        type="button"
        :aria-disabled="disabled ? 'true' : undefined"
        @click="cancel"
      >
        Cancel
      </button>
    </span>
  </span>
</template>

<style scoped>
.confirm-delete {
  display: inline-flex;
  flex-wrap: wrap;
  gap: var(--space-2);
}
</style>
