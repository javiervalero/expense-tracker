<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, useTemplateRef, watch } from 'vue'
import { RouterLink, useRoute, useRouter } from 'vue-router'

import FormAlert from '@/components/FormAlert.vue'
import MonthPicker from '@/components/MonthPicker.vue'
import SummaryTable from '@/components/SummaryTable.vue'
import { useMonthlyReport } from '@/composables/useMonthlyReport'
import { currentMonthLocal, formatMonth, isIsoMonth } from '@/lib/months'
import { INCOMPLETE_NOTICE } from '@/lib/summaryMessages'

const route = useRoute()
const router = useRouter()
const report = useMonthlyReport()
const { status, data, message } = report
const heading = useTemplateRef<HTMLElement>('heading')
const alert = useTemplateRef<InstanceType<typeof FormAlert>>('alert')

// The month in the page URL (plan phase-6 D13), or null when it is missing, repeated or not a valid month.
const monthParam = computed<string | null>(() => {
  const value = route.query.month
  return typeof value === 'string' && isIsoMonth(value) ? value : null
})
const shownMonth = computed(() => monthParam.value ?? currentMonthLocal())

// The page URL decides the report. A month that is not valid is replaced by the current one: no request, no message.
// `route.name` is checked because this component can outlive its route for a moment.
watch(
  monthParam,
  (month) => {
    if (route.name !== 'summary') {
      return
    }
    if (month === null) {
      // `month` is the page's only query parameter: other parameters and the hash are dropped on purpose.
      void router.replace({ query: { month: currentMonthLocal() } })
      return
    }
    void report.load(month)
  },
  { immediate: true },
)

onBeforeUnmount(report.stop)

// A failed report puts focus on its alert, so a screen reader reads it.
watch(status, async (current) => {
  if (current === 'error') {
    await nextTick()
    alert.value?.focus()
  }
})

// Fixed text, never amounts: it names the month that is now on screen.
const announcement = computed(() =>
  data.value === null ? '' : `Showing ${formatMonth(report.month.value)}`,
)

function choose(month: string): void {
  if (month === shownMonth.value) {
    // Same month in any state but error: nothing to do on purpose (a duplicate navigation would be dropped anyway).
    if (status.value === 'error') {
      void report.retry()
    }
    return
  }
  // `month` is the page's only query parameter: other parameters and the hash are dropped on purpose.
  void router.push({ query: { month } })
}

// The Retry button goes away with the error, taking the focus with it: it goes to the heading.
async function retry(): Promise<void> {
  const again = report.retry()
  await nextTick()
  heading.value?.focus()
  await again
}
</script>

<template>
  <section aria-labelledby="summary-heading">
    <h1 id="summary-heading" ref="heading" tabindex="-1">Monthly summary</h1>

    <MonthPicker :month="shownMonth" @change="choose" />

    <!-- Always in the page: a live region that appears together with its text is often not read. -->
    <div class="status" aria-live="polite" aria-atomic="true">{{ announcement }}</div>

    <div :aria-busy="status === 'loading' ? 'true' : undefined">
      <div v-if="status === 'error'" class="load-failed">
        <FormAlert ref="alert" :message="message" />
        <button type="button" @click="retry">Retry</button>
      </div>
      <template v-else-if="data">
        <div v-if="data.expenseCount === 0" class="empty">
          <p>No expenses in {{ formatMonth(report.month.value) }}.</p>
          <RouterLink to="/expenses/new">Add an expense</RouterLink>
        </div>
        <template v-else>
          <p v-if="!data.complete" class="incomplete">{{ INCOMPLETE_NOTICE }}</p>
          <SummaryTable
            :month="report.month.value"
            :categories="data.categories"
            :total-cents="data.totalCents"
            :expense-count="data.expenseCount"
          />
        </template>
      </template>
      <p v-else>Loading summary…</p>
    </div>
  </section>
</template>

<style scoped>
/* The region stays in the page when empty; it collapses by having no margin, never by display:none. */
.status {
  margin: 0;
}
.status:not(:empty) {
  margin-top: var(--space-3);
}
</style>
