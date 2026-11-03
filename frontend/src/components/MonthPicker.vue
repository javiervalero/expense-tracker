<script setup lang="ts">
import { computed, ref, watch } from 'vue'

import { APP_LOCALE, MONTH_MAX, MONTH_MIN } from '@/config'
import { shiftMonth } from '@/lib/months'

// Picks a month (plan phase-6 D12): two selects and a Show button, plus the neighbouring months. It emits only a
// YYYY-MM built from its own options, so the value is always valid. The ends use aria-disabled, not disabled, so a
// focused button keeps its focus.
const props = defineProps<{ month: string }>()
const emit = defineEmits<{ change: [month: string] }>()

const FIRST_YEAR = Number(MONTH_MIN.slice(0, 4))
const LAST_YEAR = Number(MONTH_MAX.slice(0, 4))
const MONTHS_PER_YEAR = 12

const monthFormatter = new Intl.DateTimeFormat(APP_LOCALE, { month: 'long', timeZone: 'UTC' })
const monthOptions = Array.from({ length: MONTHS_PER_YEAR }, (_, index) => ({
  value: String(index + 1).padStart(2, '0'),
  label: monthFormatter.format(new Date(Date.UTC(2000, index, 1))),
}))
const yearOptions = Array.from({ length: LAST_YEAR - FIRST_YEAR + 1 }, (_, index) =>
  String(FIRST_YEAR + index),
)

const year = ref(props.month.slice(0, 4))
const monthPart = ref(props.month.slice(5, 7))
watch(
  () => props.month,
  (next) => {
    year.value = next.slice(0, 4)
    monthPart.value = next.slice(5, 7)
  },
)

const atStart = computed(() => props.month <= MONTH_MIN)
const atEnd = computed(() => props.month >= MONTH_MAX)

function show(): void {
  emit('change', `${year.value}-${monthPart.value}`)
}
function step(direction: -1 | 1): void {
  if (direction === -1 ? atStart.value : atEnd.value) {
    return
  }
  emit('change', shiftMonth(props.month, direction))
}
</script>

<template>
  <form class="month-picker" novalidate aria-label="Choose a month" @submit.prevent="show">
    <div class="field">
      <label for="summary-month">Month</label>
      <select id="summary-month" v-model="monthPart">
        <option v-for="option in monthOptions" :key="option.value" :value="option.value">
          {{ option.label }}
        </option>
      </select>
    </div>
    <div class="field">
      <label for="summary-year">Year</label>
      <select id="summary-year" v-model="year">
        <option v-for="option in yearOptions" :key="option" :value="option">{{ option }}</option>
      </select>
    </div>
    <button type="submit">Show</button>
    <button type="button" :aria-disabled="atStart ? 'true' : undefined" @click="step(-1)">
      Previous month
    </button>
    <button type="button" :aria-disabled="atEnd ? 'true' : undefined" @click="step(1)">
      Next month
    </button>
  </form>
</template>

<style scoped>
.month-picker {
  display: flex;
  flex-wrap: wrap;
  align-items: flex-end;
  gap: var(--space-2) var(--space-3);
  margin-block: var(--space-3);
}
.field {
  display: flex;
  flex-direction: column;
  gap: var(--space-1);
}
</style>
