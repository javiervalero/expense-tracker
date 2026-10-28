<script setup lang="ts">
import { RouterLink } from 'vue-router'

import type { Expense } from '@/api/types'
import ConfirmDeleteButton from '@/components/ConfirmDeleteButton.vue'
import FormAlert from '@/components/FormAlert.vue'
import { formatDate, isIsoDate } from '@/lib/dates'
import { isUuidV4 } from '@/lib/expenseQuery'
import { formatCents } from '@/lib/money'

// The rows of the expense list. User text (description, category name) is only interpolated, inside <bdi>; the only
// link is a router link built from an id that passed the uuid check. Deleting is the page's job: this emits `remove`.
defineProps<{
  items: readonly Expense[]
  categoryLabel: (categoryId: string | null) => string
  deleting: ReadonlySet<string>
  rowErrors: ReadonlyMap<string, string>
}>()
const emit = defineEmits<{ remove: [id: string] }>()
</script>

<template>
  <div class="table-wrap" role="region" aria-labelledby="results-heading" tabindex="0">
    <table>
      <caption>
        Expenses, newest first
      </caption>
      <thead>
        <tr>
          <th scope="col">Date</th>
          <th scope="col">Description</th>
          <th scope="col">Category</th>
          <th scope="col" class="amount">Amount</th>
          <th scope="col">Actions</th>
        </tr>
      </thead>
      <tbody>
        <tr v-for="expense in items" :key="expense.id">
          <th scope="row" tabindex="-1">
            <time :datetime="isIsoDate(expense.date) ? expense.date : undefined">{{
              formatDate(expense.date)
            }}</time>
          </th>
          <td>
            <div class="clip">
              <bdi>{{ expense.description }}</bdi>
            </div>
          </td>
          <td>
            <div class="clip">
              <bdi>{{ categoryLabel(expense.categoryId) }}</bdi>
            </div>
          </td>
          <td class="amount">{{ formatCents(expense.amountCents) }}</td>
          <td>
            <span v-if="isUuidV4(expense.id)" class="actions">
              <RouterLink
                :to="{ name: 'expense-edit', params: { id: expense.id } }"
                :aria-label="`Edit ${expense.description}`"
                >Edit</RouterLink
              >
              <ConfirmDeleteButton
                question="Delete this expense?"
                :subject="expense.description"
                :disabled="deleting.has(expense.id)"
                @confirm="emit('remove', expense.id)"
              />
            </span>
            <FormAlert :message="rowErrors.get(expense.id)" />
          </td>
        </tr>
      </tbody>
    </table>
  </div>
</template>

<style scoped>
.table-wrap {
  overflow-x: auto;
}
caption {
  padding: var(--space-2) 0;
  color: var(--color-muted);
  text-align: start;
}
th,
td {
  padding: var(--space-2);
  border-bottom: 1px solid var(--color-border);
  text-align: start;
  vertical-align: top;
}
th[scope='row'] {
  font-weight: normal;
  white-space: nowrap;
}
.amount {
  text-align: end;
  white-space: nowrap;
}
/* User text: clipped, never allowed to widen the page or grow tall (a flood of combining marks). */
.clip {
  min-width: 8rem;
  max-width: 24rem;
  max-height: 3em;
  line-height: 1.5;
  overflow: hidden;
  overflow-wrap: anywhere;
}
.actions {
  display: inline-flex;
  flex-wrap: wrap;
  align-items: center;
  gap: var(--space-2);
}
</style>
