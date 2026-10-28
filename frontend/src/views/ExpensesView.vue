<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, ref, useTemplateRef, watch } from 'vue'
import { RouterLink, useRoute, useRouter } from 'vue-router'

import ExpenseFilters from '@/components/ExpenseFilters.vue'
import ExpenseTable from '@/components/ExpenseTable.vue'
import FormAlert from '@/components/FormAlert.vue'
import { useExpenseList } from '@/composables/useExpenseList'
import { useCategoryLabels } from '@/composables/useCategoryLabels'
import { useFormErrors } from '@/composables/useFormErrors'
import {
  checkFilters,
  filtersKey,
  filtersToQuery,
  parseFilters,
  sanitizeFilters,
} from '@/lib/expenseQuery'
import type { ExpenseFilters as Filters } from '@/lib/expenseQuery'
import { useCategoriesStore } from '@/stores/categories'

const route = useRoute()
const router = useRouter()
const categories = useCategoriesStore()
const list = useExpenseList()
const errors = useFormErrors(['from', 'to', 'category', 'q'] as const, 'filter')
const heading = useTemplateRef<HTMLElement>('heading')
const root = useTemplateRef<HTMLElement>('root')

// The form's values. They follow the page URL; Apply (a form submit) is what sends them to it.
const from = ref('')
const to = ref('')
const category = ref('')
const q = ref('')

// The filters in the page URL, as a string: unrelated query parameters and a repeated visit to the same filters do not
// change it. `null` while the router shows another page (this component can outlive its route for a moment).
const appliedKey = computed(() =>
  route.name === 'expenses' ? filtersKey(parseFilters(route.query)) : null,
)
const hasFilters = computed(() => appliedKey.value !== null && appliedKey.value !== '')

function showInForm(filters: Filters): void {
  from.value = filters.from
  to.value = filters.to
  category.value = filters.category
  q.value = filters.q
}

// The page URL decides the list. A filter that fails its check (such as from after to) sends no request: the list
// refuses it and the form says why.
watch(
  appliedKey,
  (key) => {
    if (key === null) {
      return
    }
    const filters = parseFilters(route.query)
    showInForm(filters)
    errors.show(checkFilters(filters), '')
    void list.search(filters)
  },
  { immediate: true },
)

onBeforeUnmount(list.stop)

// The category column: names from the store, with the lookup rules of plan phase-4 C14.
const { categoryLabel } = useCategoryLabels(list.items)

// A message for the polite live region. It is emptied first, so the same text twice in a row is read again.
const notice = ref('')
async function announce(message: string): Promise<void> {
  notice.value = ''
  await nextTick()
  notice.value = message
}

async function focusResults(): Promise<void> {
  await nextTick()
  heading.value?.focus()
}

// The Load more button went away (the last page, a restart, a failed restart) and took the focus with it: focus fell to
// the page. It goes to row `firstNew` if there is one, else to the results heading. Nothing moves while the button is
// still there, or when focus is somewhere on purpose.
async function rescueFocus(button: HTMLElement, firstNew: number | null): Promise<void> {
  await nextTick()
  const active = document.activeElement
  if (button.isConnected || (active !== null && active !== document.body)) {
    return
  }
  const row =
    firstNew === null ? undefined : root.value?.querySelectorAll<HTMLElement>('tbody th')[firstNew]
  ;(row ?? heading.value)?.focus()
}

// What a search found, for the live region (Apply and Clear filters ask for it; the other searches stay quiet).
const wantSummary = ref(false)
function summary(): string {
  const count = list.items.value.length
  if (count > 0) {
    const shown = `${count} ${count === 1 ? 'expense' : 'expenses'} shown`
    return list.hasMore.value ? `${shown}. More can be loaded.` : shown
  }
  if (list.hasMore.value) {
    return 'No expenses found yet. Keep searching to look further.'
  }
  return hasFilters.value ? 'No expenses match these filters' : 'No expenses yet'
}
watch(
  () => list.settled.value,
  () => {
    if (!wantSummary.value) {
      return
    }
    wantSummary.value = false
    if (list.status.value === 'ready') {
      void announce(summary())
    }
  },
)

// Puts new filters in the page URL, which starts the search, and asks for its summary. A push that did not happen (a
// cancelled or failed navigation) starts no search, so the request for a summary is dropped with it.
async function goTo(query: Record<string, string>): Promise<void> {
  wantSummary.value = true
  const failure = await router.push({ query })
  if (failure) {
    wantSummary.value = false
  }
  await focusResults()
}

// Apply: the form values are checked, then put in the page URL (which starts the search). Unchanged filters search again.
async function apply(): Promise<void> {
  const draft = { from: from.value, to: to.value, category: category.value, q: q.value }
  const problems = checkFilters(draft)
  errors.show(problems, '')
  if (Object.keys(problems).length > 0) {
    return errors.focusFirst(null)
  }
  const clean = sanitizeFilters(draft)
  showInForm(clean)
  if (filtersKey(clean) === appliedKey.value) {
    wantSummary.value = true
    const again = list.search(clean)
    await focusResults()
    return again
  }
  await goTo(filtersToQuery(clean))
}

async function clearFilters(): Promise<void> {
  await goTo({})
}

async function retry(): Promise<void> {
  const again = list.search(parseFilters(route.query))
  await focusResults()
  await again
}

async function more(event: MouseEvent): Promise<void> {
  const before = list.items.value.length
  // Read now: `currentTarget` is empty once the handler has awaited.
  const button = event.currentTarget as HTMLElement
  const outcome = await list.loadMore()
  if (outcome === 'restarted') {
    await announce('The list was reloaded from the start.')
  } else if (outcome === 0) {
    await announce(
      list.hasMore.value ? 'No matches yet. Keep searching to look further.' : 'No more expenses.',
    )
  } else if (outcome !== null) {
    await announce(`${outcome} more loaded`)
  }
  await rescueFocus(button, typeof outcome === 'number' && outcome > 0 ? before : null)
}

async function remove(id: string): Promise<void> {
  const outcome = await list.remove(id)
  if (outcome === 'deleted' || outcome === 'already') {
    // The row, with the button that had focus, is gone.
    await announce(outcome === 'deleted' ? 'Expense deleted' : 'Already deleted')
    await focusResults()
  }
}

const moreLabel = computed(() => {
  if (list.loadingMore.value) {
    return 'Loading…'
  }
  return list.keepSearching.value ? 'Keep searching' : 'Load more'
})
</script>

<template>
  <section ref="root" aria-labelledby="expenses-heading">
    <h1 id="expenses-heading" tabindex="-1">Expenses</h1>
    <p><RouterLink to="/expenses/new">Add expense</RouterLink></p>

    <ExpenseFilters
      v-model:from="from"
      v-model:to="to"
      v-model:category="category"
      v-model:q="q"
      :categories="categories.items"
      :categories-loaded="categories.loaded"
      :errors="errors.fieldErrors.value"
      @apply="apply"
    />

    <h2 id="results-heading" ref="heading" tabindex="-1">Results</h2>

    <div v-if="list.status.value === 'failed'" class="load-failed">
      <p role="alert">{{ list.message.value }}</p>
      <button type="button" @click="retry">Retry</button>
    </div>
    <p v-else-if="list.status.value === 'loading'">Loading expenses…</p>
    <p v-else-if="list.status.value === 'idle'">Fix the filters above to see your expenses.</p>
    <template v-else>
      <ExpenseTable
        v-if="list.items.value.length > 0"
        :items="list.items.value"
        :category-label="categoryLabel"
        :deleting="list.deleting.value"
        :row-errors="list.rowErrors.value"
        @remove="remove"
      />
      <div v-else-if="!list.hasMore.value" class="empty">
        <template v-if="hasFilters">
          <p>No expenses match these filters.</p>
          <button type="button" @click="clearFilters">Clear filters</button>
        </template>
        <template v-else>
          <p>No expenses yet.</p>
          <RouterLink to="/expenses/new">Add your first expense</RouterLink>
        </template>
      </div>
      <p v-else-if="list.hasMore.value && !list.keepSearching.value">No expenses on this page.</p>
      <p v-if="list.keepSearching.value">
        No matches yet in the part of your expenses searched so far.
      </p>
      <FormAlert :message="list.message.value" />
      <button
        v-if="list.hasMore.value"
        type="button"
        :aria-disabled="list.loadingMore.value ? 'true' : undefined"
        @click="more"
      >
        {{ moreLabel }}
      </button>
    </template>
    <div class="status" aria-live="polite" aria-atomic="true">{{ notice }}</div>
  </section>
</template>

<style scoped>
/* The region stays in the page when empty: a live region that appears together with its text is often not read. It
   collapses by having no margin, never by display:none or visibility:hidden. */
.status {
  margin: 0;
}
.status:not(:empty) {
  margin-top: var(--space-3);
}
</style>
