<script setup lang="ts">
import { nextTick, onMounted, ref, useTemplateRef } from 'vue'

import CategoryRow from '@/components/CategoryRow.vue'
import FormAlert from '@/components/FormAlert.vue'
import FormField from '@/components/FormField.vue'
import { useFormErrors } from '@/composables/useFormErrors'
import {
  DELETE_HINT,
  DELETE_HINT_ID,
  describeCategoryError,
  nameProblem,
} from '@/lib/categoryMessages'
import { checkName } from '@/lib/text'
import { useCategoriesStore } from '@/stores/categories'

const categories = useCategoriesStore()
const name = ref('')
const pending = ref(false)
const notice = ref('')
const loadMessage = ref('')
const errors = useFormErrors(['name'] as const, 'category')
const formAlert = useTemplateRef<{ focus: () => void }>('formAlert')
const heading = useTemplateRef<HTMLElement>('heading')

const retrying = ref(false)

async function load(): Promise<void> {
  const error = await categories.load()
  loadMessage.value = error === null ? '' : describeCategoryError(error, false).form
}
onMounted(load)

async function retry(): Promise<void> {
  if (retrying.value) {
    return
  }
  retrying.value = true
  try {
    await load()
  } finally {
    retrying.value = false
  }
}

// A row that removed itself took the focus with it: it goes to the heading, and the outcome is announced. The text is
// emptied first, so the same message twice in a row is a change a screen reader reads again.
async function announce(message: string): Promise<void> {
  notice.value = ''
  await nextTick()
  notice.value = message
  await nextTick()
  heading.value?.focus()
}

async function add(): Promise<void> {
  if (pending.value) {
    return
  }
  errors.clear()
  notice.value = ''
  if (!categories.loaded) {
    errors.show({}, 'Wait for the list to load.')
    return errors.focusFirst(formAlert.value)
  }
  const checked = checkName(name.value)
  if (!checked.ok) {
    errors.show({ name: nameProblem(checked.reason) }, '')
    return errors.focusFirst(formAlert.value)
  }
  pending.value = true
  try {
    const result = await categories.create(checked.value)
    if (result.ok) {
      name.value = ''
      notice.value = 'Category added.'
      return
    }
    const failure = describeCategoryError(result.error, true)
    errors.show(failure.fields, failure.form)
  } finally {
    pending.value = false
  }
  await errors.focusFirst(formAlert.value)
}
</script>

<template>
  <section aria-labelledby="categories-heading">
    <h1 id="categories-heading" ref="heading" tabindex="-1">Categories</h1>
    <form novalidate @submit.prevent="add">
      <FormAlert ref="formAlert" :message="errors.formMessage.value" />
      <FormField
        id="category-name"
        v-model="name"
        label="New category name"
        autocomplete="off"
        :error="errors.fieldErrors.value.name"
      />
      <button
        type="submit"
        class="primary"
        :aria-disabled="pending || !categories.loaded ? 'true' : undefined"
      >
        {{ pending ? 'Adding…' : 'Add category' }}
      </button>
    </form>
    <div role="status">
      <p v-if="notice" class="notice">{{ notice }}</p>
    </div>

    <div v-if="loadMessage" class="load-failed">
      <p role="alert">{{ loadMessage }}</p>
      <button type="button" :aria-disabled="retrying ? 'true' : undefined" @click="retry">
        {{ retrying ? 'Retry…' : 'Retry' }}
      </button>
    </div>
    <p v-else-if="!categories.loaded">Loading categories…</p>
    <p v-else-if="categories.items.length === 0" class="empty">No categories yet</p>
    <template v-else>
      <p :id="DELETE_HINT_ID" class="hint">{{ DELETE_HINT }}</p>
      <ul class="category-list">
        <CategoryRow
          v-for="category in categories.items"
          :key="category.id"
          :category="category"
          :announce="announce"
        />
      </ul>
    </template>
  </section>
</template>

<style scoped>
.category-list {
  margin: 0;
  padding: 0;
  list-style: none;
}
.hint {
  color: var(--color-muted);
}
.notice {
  margin: 0 0 var(--space-3);
}
</style>
