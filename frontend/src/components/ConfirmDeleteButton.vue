<script setup lang="ts">
import { nextTick, ref, useTemplateRef } from 'vue'

withDefaults(
  defineProps<{ label?: string; confirmLabel?: string; question?: string; disabled?: boolean }>(),
  {
    label: 'Delete',
    confirmLabel: 'Confirm delete',
    question: 'Delete this item?',
    disabled: false,
  },
)
const emit = defineEmits<{ confirm: [] }>()

// Two steps in place (plan phase-4 C13): focus goes to the safe choice when asking, and back to Delete on cancel.
const asking = ref(false)
const deleteButton = useTemplateRef<HTMLButtonElement>('deleteButton')
const cancelButton = useTemplateRef<HTMLButtonElement>('cancelButton')

async function ask(): Promise<void> {
  asking.value = true
  await nextTick()
  cancelButton.value?.focus()
}

async function cancel(): Promise<void> {
  asking.value = false
  await restoreFocus()
}

// Two clicks in the same tick (before the button is replaced) must not delete twice: only the first one counts.
function confirm(): void {
  if (!asking.value) {
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
    <button v-if="!asking" ref="deleteButton" type="button" :disabled="disabled" @click="ask">
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
      <button type="button" class="danger" :disabled="disabled" @click="confirm">
        {{ confirmLabel }}
      </button>
      <button ref="cancelButton" type="button" :disabled="disabled" @click="cancel">Cancel</button>
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
