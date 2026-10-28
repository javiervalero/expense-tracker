<script setup lang="ts">
import { computed, onBeforeUnmount, ref, useTemplateRef, watch } from 'vue'
import { RouterLink, useRoute, useRouter } from 'vue-router'

import { createExpense, deleteExpense, getExpense, updateExpense } from '@/api/expenses'
import type { ApiResult } from '@/api/http'
import type { Expense } from '@/api/types'
import ConfirmDeleteButton from '@/components/ConfirmDeleteButton.vue'
import FormAlert from '@/components/FormAlert.vue'
import FormField from '@/components/FormField.vue'
import { useFormErrors } from '@/composables/useFormErrors'
import { APP_CURRENCY, DATE_MAX, DATE_MIN } from '@/config'
import { todayLocal } from '@/lib/dates'
import { validateExpenseForm } from '@/lib/expenseForm'
import type { ExpenseField } from '@/lib/expenseForm'
import { describeExpenseError } from '@/lib/expenseMessages'
import { buildExpensePatch } from '@/lib/expensePatch'
import type { ExpenseValues } from '@/lib/expensePatch'
import { setFlash } from '@/lib/flash'
import { centsToInput } from '@/lib/money'
import { useCategoriesStore } from '@/stores/categories'

const FIELDS: readonly ExpenseField[] = ['date', 'amount', 'description', 'categoryId']

const route = useRoute()
const router = useRouter()
const categories = useCategoriesStore()

// The id of the expense being edited ('' on the new route). `null` while the router shows another page: this component
// can outlive its route for a moment, and then it must not load or navigate anything.
const routeId = computed<string | null>(() => {
  if (route.name !== 'expense-new' && route.name !== 'expense-edit') {
    return null
  }
  return typeof route.params.id === 'string' ? route.params.id : ''
})
const id = computed(() => routeId.value ?? '')
const isEdit = computed(() => id.value !== '')

const date = ref(todayLocal())
const amount = ref('')
const description = ref('')
// '' is Uncategorized: it is the value of the first option.
const categoryId = ref('')

// The expense as last read, with a category that is no longer in the list treated as none (plan phase-4 C14).
const original = ref<Expense | null>(null)
const status = ref<'loading' | 'failed' | 'ready'>('ready')
const loadMessage = ref('')
const loadGone = ref(false)

const pending = ref(false)
const deleting = ref(false)
const reloading = ref(false)
const conflict = ref(false)
const gone = ref(false)
const notice = ref('')
const busy = computed(() => pending.value || deleting.value || reloading.value)

const errors = useFormErrors(FIELDS, 'expense')
const formAlert = useTemplateRef<{ focus: () => void }>('formAlert')
const heading = useTemplateRef<HTMLElement>('heading')

// Bumped whenever the form starts showing something else (another expense, the new form, another page, unmount). Every
// request remembers the value it started with; an answer that finds a different one belongs to a form that is gone, so
// it changes nothing on screen and never navigates.
let generation = 0

function knownCategory(candidate: string): boolean {
  return categories.items.some((category) => category.id === candidate)
}

// Puts a loaded expense into the form. A category missing from the cached list is looked up once more (it may have been
// created elsewhere); if it is still missing the expense shows as Uncategorized. Returns false when the form moved on.
async function adopt(expense: Expense, mine: number): Promise<boolean> {
  const wanted = expense.categoryId
  if (wanted !== null && !knownCategory(wanted)) {
    await categories.refresh()
  }
  if (mine !== generation) {
    return false
  }
  const shown = wanted !== null && knownCategory(wanted) ? wanted : null
  original.value = { ...expense, categoryId: shown }
  date.value = expense.date
  amount.value = centsToInput(expense.amountCents)
  description.value = expense.description
  categoryId.value = shown ?? ''
  return true
}

async function loadExpense(): Promise<void> {
  const mine = generation
  status.value = 'loading'
  const waiting = categories.load()
  const result = await getExpense(id.value)
  await waiting
  if (mine !== generation) {
    return
  }
  if (!result.ok) {
    const view = describeExpenseError(result.error, false)
    loadMessage.value = view.form
    loadGone.value = view.gone
    status.value = 'failed'
    return
  }
  if (await adopt(result.data, mine)) {
    status.value = 'ready'
  }
}

// Starts from a blank form for whatever the route shows now.
function resetForm(): void {
  generation += 1
  date.value = todayLocal()
  amount.value = ''
  description.value = ''
  categoryId.value = ''
  original.value = null
  errors.clear()
  conflict.value = false
  gone.value = false
  notice.value = ''
  pending.value = false
  deleting.value = false
  reloading.value = false
  status.value = isEdit.value ? 'loading' : 'ready'
}

watch(
  routeId,
  (current) => {
    if (current === null) {
      generation += 1
      return
    }
    resetForm()
    if (isEdit.value) {
      void loadExpense()
    } else {
      void categories.load()
    }
  },
  { immediate: true },
)

onBeforeUnmount(() => {
  generation += 1
})

// Leaving: the message is announced once the list page is there.
async function leave(message: string): Promise<void> {
  await router.push('/expenses')
  await setFlash(message)
}

async function showFailure(view: ReturnType<typeof describeExpenseError>): Promise<void> {
  errors.show(view.fields, view.form)
  gone.value = view.gone
  if (view.fields.categoryId !== undefined) {
    // The category was deleted elsewhere: look again, and fall back to Uncategorized.
    categoryId.value = ''
    await categories.refresh()
  }
  await errors.focusFirst(formAlert.value)
}

async function send(values: ExpenseValues): Promise<ApiResult<Expense> | null> {
  const current = original.value
  if (current === null) {
    return createExpense({
      date: values.date,
      amountCents: values.amountCents,
      description: values.description,
      ...(values.categoryId === null ? {} : { categoryId: values.categoryId }),
    })
  }
  const patch = buildExpensePatch(current, values)
  return patch === null ? null : updateExpense(current.id, patch)
}

function readForm(): ExpenseValues | null {
  const checked = validateExpenseForm({
    date: date.value,
    amount: amount.value,
    description: description.value,
    categoryId: categoryId.value,
  })
  if (!checked.ok) {
    errors.show(checked.fields, '')
    return null
  }
  return checked.value
}

async function submit(): Promise<void> {
  if (busy.value) {
    return
  }
  errors.clear()
  conflict.value = false
  gone.value = false
  notice.value = ''
  const values = readForm()
  if (values === null) {
    return errors.focusFirst(formAlert.value)
  }
  const mine = generation
  pending.value = true
  try {
    const result = await send(values)
    if (result === null) {
      notice.value = 'Nothing to save'
      return
    }
    if (mine !== generation) {
      // The user went elsewhere: no navigation, but the outcome is still announced.
      return result.ok ? await setFlash('Expense saved') : undefined
    }
    if (result.ok) {
      return await leave('Expense saved')
    }
    const view = describeExpenseError(result.error, true)
    conflict.value = view.conflict
    await showFailure(view)
  } finally {
    if (mine === generation) {
      pending.value = false
    }
  }
}

// Loads the latest version and resets the form; the unsaved changes are dropped (plan phase-4 C12).
async function reload(): Promise<void> {
  if (busy.value) {
    return
  }
  const mine = generation
  reloading.value = true
  try {
    const result = await getExpense(id.value)
    if (mine !== generation) {
      return
    }
    errors.clear()
    if (result.ok && (await adopt(result.data, mine))) {
      conflict.value = false
      notice.value = 'Reloaded the latest version.'
      heading.value?.focus()
      return
    }
    // A failed Reload keeps the conflict (and its Reload button) so the user can try again.
    if (!result.ok) {
      await showFailure(describeExpenseError(result.error, false))
    }
  } finally {
    if (mine === generation) {
      reloading.value = false
    }
  }
}

async function remove(): Promise<void> {
  if (busy.value) {
    return
  }
  errors.clear()
  notice.value = ''
  const mine = generation
  deleting.value = true
  try {
    const result = await deleteExpense(id.value)
    const deletedMessage = result.ok ? 'Expense deleted' : 'Already deleted'
    const done = result.ok || result.error.kind === 'not-found'
    if (mine !== generation) {
      return done ? await setFlash(deletedMessage) : undefined
    }
    if (done) {
      return await leave(deletedMessage)
    }
    await showFailure(describeExpenseError(result.error, true))
  } finally {
    if (mine === generation) {
      deleting.value = false
    }
  }
}
</script>

<template>
  <section aria-labelledby="expense-heading">
    <h1 id="expense-heading" ref="heading" tabindex="-1">
      {{ isEdit ? 'Edit expense' : 'New expense' }}
    </h1>

    <p v-if="status === 'loading'">Loading expense…</p>
    <div v-else-if="status === 'failed'" class="load-failed">
      <p role="alert">{{ loadMessage }}</p>
      <RouterLink v-if="loadGone" to="/expenses">Back to expenses</RouterLink>
      <button v-else type="button" @click="loadExpense">Retry</button>
    </div>
    <form v-else novalidate @submit.prevent="submit">
      <FormAlert ref="formAlert" :message="errors.formMessage.value" />
      <p v-if="gone"><RouterLink to="/expenses">Back to expenses</RouterLink></p>
      <div v-if="conflict" class="conflict">
        <p>Reloading shows the latest version; your unsaved changes will be lost.</p>
        <button
          type="button"
          class="reload"
          :aria-disabled="busy ? 'true' : undefined"
          @click="reload"
        >
          Reload
        </button>
      </div>

      <FormField
        id="expense-date"
        v-model="date"
        label="Date"
        type="date"
        :min="DATE_MIN"
        :max="DATE_MAX"
        :error="errors.fieldErrors.value.date"
      />
      <FormField
        id="expense-amount"
        v-model="amount"
        :label="`Amount (${APP_CURRENCY})`"
        hint="For example 12.50"
        inputmode="decimal"
        autocomplete="off"
        :error="errors.fieldErrors.value.amount"
      />
      <FormField
        id="expense-description"
        v-model="description"
        label="Description"
        autocomplete="off"
        :error="errors.fieldErrors.value.description"
      />
      <FormField
        id="expense-categoryId"
        label="Category"
        :error="errors.fieldErrors.value.categoryId"
      >
        <template #default="{ id: fieldId, describedby, invalid }">
          <select
            :id="fieldId"
            v-model="categoryId"
            :aria-describedby="describedby"
            :aria-invalid="invalid === 'true' ? 'true' : undefined"
          >
            <option value="">Uncategorized</option>
            <option v-for="category in categories.items" :key="category.id" :value="category.id">
              {{ category.name }}
            </option>
          </select>
        </template>
      </FormField>
      <p v-if="categories.loaded && categories.items.length === 0" class="hint">
        <RouterLink to="/categories">Add a category</RouterLink>
      </p>

      <div class="actions">
        <button type="submit" class="primary" :aria-disabled="busy ? 'true' : undefined">
          {{ pending ? 'Saving…' : 'Save' }}
        </button>
        <RouterLink to="/expenses">Cancel</RouterLink>
        <ConfirmDeleteButton
          v-if="isEdit"
          question="Delete this expense?"
          subject="expense"
          :disabled="busy"
          @confirm="remove"
        />
      </div>
      <div role="status">
        <p v-if="notice" class="notice">{{ notice }}</p>
      </div>
    </form>
  </section>
</template>

<style scoped>
.actions {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: var(--space-3);
}
.hint,
.notice {
  margin: 0 0 var(--space-3);
}
</style>
