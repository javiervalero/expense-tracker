<script setup lang="ts">
import { computed } from 'vue'

import type { Category } from '@/api/types'
import FormField from '@/components/FormField.vue'
import { DATE_MAX, DATE_MIN } from '@/config'
import { CATEGORY_NONE } from '@/lib/expenseQuery'
import type { FilterProblems } from '@/lib/expenseQuery'

// The filter form of the expense list (plan phase-4 C11). It only edits values; the page decides what Apply does.
// Field ids are `filter-<name>`, which is what `useFormErrors` in the page expects.
const props = defineProps<{
  categories: readonly Category[]
  /** True once the categories were fetched: before that a category id from the URL is not "unknown", just not loaded. */
  categoriesLoaded?: boolean
  errors: FilterProblems
}>()
const emit = defineEmits<{ apply: []; clear: [] }>()

const from = defineModel<string>('from', { default: '' })
const to = defineModel<string>('to', { default: '' })
const category = defineModel<string>('category', { default: '' })
const q = defineModel<string>('q', { default: '' })

// A category id from the page URL that is not in the user's list (yet) stays selectable, so the form shows what is applied.
const orphan = computed(() => {
  const value = category.value
  if (!props.categoriesLoaded) {
    return null
  }
  const known =
    value === '' || value === CATEGORY_NONE || props.categories.some((c) => c.id === value)
  return known ? null : value
})
</script>

<template>
  <form class="filters" novalidate aria-label="Filter expenses" @submit.prevent="emit('apply')">
    <FormField
      id="filter-from"
      v-model="from"
      label="From"
      type="date"
      :min="DATE_MIN"
      :max="DATE_MAX"
      :error="errors.from"
    />
    <FormField
      id="filter-to"
      v-model="to"
      label="To"
      type="date"
      :min="DATE_MIN"
      :max="DATE_MAX"
      :error="errors.to"
    />
    <FormField id="filter-category" label="Category" :error="errors.category">
      <template #default="{ id, describedby, invalid }">
        <select
          :id="id"
          v-model="category"
          :aria-describedby="describedby"
          :aria-invalid="invalid === 'true' ? 'true' : undefined"
        >
          <option value="">Any category</option>
          <option :value="CATEGORY_NONE">Uncategorized</option>
          <option v-for="item in categories" :key="item.id" :value="item.id">
            {{ item.name }}
          </option>
          <option v-if="orphan !== null" :value="orphan">Unknown category</option>
        </select>
      </template>
    </FormField>
    <FormField
      id="filter-q"
      v-model="q"
      label="Search description"
      type="search"
      autocomplete="off"
      :error="errors.q"
    />
    <button type="submit" class="primary">Apply</button>
    <button type="button" @click="emit('clear')">Clear filters</button>
  </form>
</template>

<style scoped>
.filters {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(12rem, 1fr));
  gap: 0 var(--space-3);
  align-items: end;
}
.filters button {
  margin-bottom: var(--space-3);
}
</style>
