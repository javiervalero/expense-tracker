<script setup lang="ts">
import { computed, useId } from 'vue'
import { RouterLink } from 'vue-router'
import type { RouteLocationRaw } from 'vue-router'

import type { CategoryTotal } from '@/api/types'
import { APP_LOCALE } from '@/config'
import { UNCATEGORIZED } from '@/lib/categoryMessages'
import { CATEGORY_NONE, filtersToQuery, isUuidV4 } from '@/lib/expenseQuery'
import { formatTotalCents } from '@/lib/money'
import { formatMonth, monthRange } from '@/lib/months'

// The rows of the monthly report. User text (a category name) is only interpolated, inside <bdi>; the only link is a
// router link to the expense list, built from the month and an id that passed the uuid check (or null: no category).
const props = defineProps<{
  month: string
  categories: readonly CategoryTotal[]
  totalCents: number
  expenseCount: number
}>()

const countFormatter = new Intl.NumberFormat(APP_LOCALE)
const formatCount = (count: number): string => countFormatter.format(count)

const captionId = useId()
const caption = computed(() => `Spending by category, ${formatMonth(props.month)}`)

const rows = computed(() => {
  const range = monthRange(props.month)
  return props.categories.map((entry) => {
    const linkable = range !== null && (entry.categoryId === null || isUuidV4(entry.categoryId))
    const to: RouteLocationRaw | null = linkable
      ? {
          name: 'expenses',
          query: filtersToQuery({
            from: range.from,
            to: range.to,
            category: entry.categoryId ?? CATEGORY_NONE,
            q: '',
          }),
        }
      : null
    return {
      key: entry.categoryId ?? CATEGORY_NONE,
      label: entry.name ?? UNCATEGORIZED,
      count: formatCount(entry.expenseCount),
      total: formatTotalCents(entry.totalCents),
      to,
    }
  })
})
</script>

<template>
  <div class="table-wrap" role="region" :aria-labelledby="captionId" tabindex="0">
    <table>
      <!-- prettier-ignore -->
      <caption :id="captionId">{{ caption }}</caption>
      <thead>
        <tr>
          <th scope="col">Category</th>
          <th scope="col">Expenses</th>
          <th scope="col" class="amount">Total</th>
        </tr>
      </thead>
      <tbody>
        <tr v-for="row in rows" :key="row.key">
          <th scope="row">
            <div class="clip">
              <RouterLink v-if="row.to" :to="row.to">
                <bdi>{{ row.label }}</bdi>
              </RouterLink>
              <bdi v-else>{{ row.label }}</bdi>
            </div>
          </th>
          <td>{{ row.count }}</td>
          <td class="amount">{{ row.total }}</td>
        </tr>
      </tbody>
      <tfoot>
        <tr>
          <th scope="row">Total</th>
          <td>{{ formatCount(expenseCount) }}</td>
          <td class="amount">{{ formatTotalCents(totalCents) }}</td>
        </tr>
      </tfoot>
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
}
tfoot th,
tfoot td {
  font-weight: 700;
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
</style>
