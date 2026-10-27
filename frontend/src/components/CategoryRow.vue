<script setup lang="ts">
import { computed, nextTick, ref, useTemplateRef } from 'vue'

import type { Category } from '@/api/types'
import ConfirmDeleteButton from '@/components/ConfirmDeleteButton.vue'
import FormAlert from '@/components/FormAlert.vue'
import FormField from '@/components/FormField.vue'
import { useFormErrors } from '@/composables/useFormErrors'
import { DELETE_HINT_ID, describeCategoryError, nameProblem } from '@/lib/categoryMessages'
import { checkName } from '@/lib/text'
import { useCategoriesStore } from '@/stores/categories'

// `announce` puts a message on the page for outcomes that remove this row. It is a function prop and not an emitted
// event on purpose: by the time the store call returns this row is already unmounted, and Vue drops events emitted by an
// unmounted component.
const props = defineProps<{ category: Category; announce: (message: string) => void }>()

const categories = useCategoriesStore()
const editing = ref(false)
const draft = ref('')
const pending = ref(false)
const deleting = ref(false)
const conflict = ref(false)
// Read politely after Reload, which removes the button that had focus.
const reloaded = ref('')
// A failed delete is shown in the row, apart from the rename form's own messages.
const deleteMessage = ref('')
const busy = computed(() => pending.value || deleting.value)
const idPrefix = `rename-${props.category.id}`
const errors = useFormErrors(['name'] as const, idPrefix)
const root = useTemplateRef<HTMLElement>('root')
const renameButton = useTemplateRef<HTMLButtonElement>('renameButton')
const formAlert = useTemplateRef<{ focus: () => void }>('formAlert')

async function startEditing(): Promise<void> {
  if (busy.value) {
    return
  }
  errors.clear()
  conflict.value = false
  reloaded.value = ''
  deleteMessage.value = ''
  draft.value = props.category.name
  editing.value = true
  await nextTick()
  focusInput()
}

function focusInput(): void {
  root.value?.querySelector('input')?.focus()
}

// While a save is pending the form stays: closing it would let the late answer set messages on a closed form and take
// the focus from wherever the user went.
async function stopEditing(): Promise<void> {
  if (pending.value) {
    return
  }
  editing.value = false
  conflict.value = false
  reloaded.value = ''
  errors.clear()
  await nextTick()
  renameButton.value?.focus()
}

async function showFailure(fields: { name?: string }, form: string): Promise<void> {
  errors.show(fields, form)
  await errors.focusFirst(formAlert.value)
}

async function save(): Promise<void> {
  if (busy.value) {
    return
  }
  errors.clear()
  conflict.value = false
  reloaded.value = ''
  const checked = checkName(draft.value)
  if (!checked.ok) {
    return showFailure({ name: nameProblem(checked.reason) }, '')
  }
  if (checked.value === props.category.name) {
    return stopEditing()
  }
  pending.value = true
  let failure: ReturnType<typeof describeCategoryError>
  try {
    const result = await categories.rename(props.category, checked.value)
    if (result.ok) {
      pending.value = false
      return await stopEditing()
    }
    if (result.error.kind === 'not-found') {
      // The store has removed this row; the page says why.
      props.announce(describeCategoryError(result.error, true).form)
      return
    }
    failure = describeCategoryError(result.error, true)
  } finally {
    pending.value = false
  }
  conflict.value = failure.conflict
  await showFailure(failure.fields, failure.form)
}

// Loads the latest list, and starts the form again from the stored name and version (the form reads the version from the
// store's copy, so the next save sends the new one). What the user typed is lost, which the button's hint says. The
// Reload button disappears, so focus goes to the field.
async function reload(): Promise<void> {
  if (busy.value) {
    return
  }
  pending.value = true
  let error
  try {
    error = await categories.refresh()
  } finally {
    pending.value = false
  }
  if (error !== null) {
    await showFailure({}, describeCategoryError(error, false).form)
    return
  }
  const latest = categories.items.find((c) => c.id === props.category.id)
  if (latest === undefined) {
    props.announce(describeCategoryError({ kind: 'not-found', status: 404 }, true).form)
    return
  }
  draft.value = latest.name
  conflict.value = false
  errors.clear()
  reloaded.value = 'Reloaded the latest name.'
  await nextTick()
  focusInput()
}

async function remove(): Promise<void> {
  if (busy.value) {
    return
  }
  deleting.value = true
  deleteMessage.value = ''
  try {
    const result = await categories.remove(props.category.id)
    if (result.ok) {
      props.announce('Category deleted.')
    } else if (result.error.kind === 'not-found') {
      props.announce('Already deleted')
    } else {
      deleteMessage.value = describeCategoryError(result.error, true).form
    }
  } finally {
    deleting.value = false
  }
}
</script>

<template>
  <li ref="root" class="category-row">
    <form
      v-if="editing"
      novalidate
      class="rename"
      @submit.prevent="save"
      @keydown.esc.stop="stopEditing"
    >
      <FormAlert ref="formAlert" :message="errors.formMessage.value" />
      <p v-if="conflict">
        <button
          type="button"
          :aria-describedby="`${idPrefix}-lost`"
          :aria-disabled="busy ? 'true' : undefined"
          @click="reload"
        >
          Reload
        </button>
        <span :id="`${idPrefix}-lost`">Your unsaved changes will be lost.</span>
      </p>
      <p role="status" class="reloaded">{{ reloaded }}</p>
      <FormField
        :id="`${idPrefix}-name`"
        v-model="draft"
        label="New name"
        autocomplete="off"
        :aria-label="`New name for ${category.name}`"
        :readonly="pending"
        :error="errors.fieldErrors.value.name"
      />
      <button type="submit" class="primary" :aria-disabled="busy ? 'true' : undefined">Save</button>
      <button type="button" :aria-disabled="pending ? 'true' : undefined" @click="stopEditing">
        Cancel
      </button>
    </form>
    <template v-else>
      <span class="name"
        ><bdi>{{ category.name }}</bdi></span
      >
      <span class="actions">
        <button
          ref="renameButton"
          type="button"
          :aria-label="`Rename ${category.name}`"
          :aria-disabled="busy ? 'true' : undefined"
          @click="startEditing"
        >
          Rename
        </button>
        <ConfirmDeleteButton
          :question="`Delete category ${category.name}? Its expenses become Uncategorized.`"
          :subject="category.name"
          :describedby="DELETE_HINT_ID"
          :disabled="busy"
          @confirm="remove"
        />
      </span>
      <FormAlert :message="deleteMessage" />
    </template>
  </li>
</template>

<style scoped>
.category-row {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  justify-content: space-between;
  gap: var(--space-2);
  padding: var(--space-2) 0;
  border-bottom: 1px solid var(--color-border);
}
/* User text: clipped, never allowed to widen the page or grow tall (a flood of combining marks). */
.name {
  flex: 1 1 12rem;
  min-width: 0;
  max-height: 3em;
  line-height: 1.5;
  overflow: hidden;
  overflow-wrap: anywhere;
}
.actions {
  display: inline-flex;
  flex-wrap: wrap;
  gap: var(--space-2);
}
.rename {
  flex: 1 1 100%;
}
.reloaded {
  margin: 0;
}
</style>
