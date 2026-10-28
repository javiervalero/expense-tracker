import { ref, watch } from 'vue'
import type { Ref } from 'vue'

import type { Expense } from '@/api/types'
import { useCategoriesStore } from '@/stores/categories'

const UNCATEGORIZED = 'Uncategorized'
// Shown for a category id that is not in the cached list yet, until the lookup has been tried.
const LOOKING_UP = '…'
// Shown when the lookup of a category failed (not the same as "no category").
const UNAVAILABLE = 'Category unavailable'

/**
 * The category column of the expense list (plan phase-4 C14). `categoryLabel(id)` is the category's name from the store.
 * An id that is not in the store triggers one refresh per batch of new ids (it may have been created elsewhere): if the
 * lookup worked and the id is still missing it reads "Uncategorized", and if the lookup failed "Category unavailable".
 * Failed ids are tried again when the list grows (a later page) or when the store loads successfully elsewhere, never on
 * a delete, and never while a lookup for them is already pending.
 */
export function useCategoryLabels(items: Readonly<Ref<readonly Expense[]>>) {
  const categories = useCategoriesStore()
  const tried = ref<ReadonlySet<string>>(new Set())
  const unavailable = ref<ReadonlySet<string>>(new Set())
  // Ids with a lookup on its way. Not reactive: nothing is drawn from it.
  const inflight = new Set<string>()
  let lastCount = 0

  function categoryLabel(id: string | null): string {
    if (id === null) {
      return UNCATEGORIZED
    }
    const found = categories.items.find((item) => item.id === id)
    if (found !== undefined) {
      return found.name
    }
    if (tried.value.has(id)) {
      return UNCATEGORIZED
    }
    return unavailable.value.has(id) ? UNAVAILABLE : LOOKING_UP
  }

  function record(ids: readonly string[], failed: boolean): void {
    const done = new Set(ids)
    if (failed) {
      unavailable.value = new Set([...unavailable.value, ...done])
      return
    }
    tried.value = new Set([...tried.value, ...done])
    unavailable.value = new Set([...unavailable.value].filter((id) => !done.has(id)))
  }

  async function reconcile(retryFailed: boolean): Promise<void> {
    const grew = items.value.length > lastCount
    lastCount = items.value.length
    const wanted = [
      ...new Set(
        items.value
          .map((expense) => expense.categoryId)
          .filter((id): id is string => id !== null && !tried.value.has(id) && !inflight.has(id)),
      ),
    ].filter((id) => grew || retryFailed || !unavailable.value.has(id))
    wanted.forEach((id) => inflight.add(id))
    try {
      // A failed first load is asked again only by the callers above; `loaded` skips the request when it worked.
      const loadError = categories.loaded ? null : await categories.load()
      const unknown = wanted.filter((id) => !categories.items.some((item) => item.id === id))
      if (unknown.length > 0) {
        record(unknown, (loadError ?? (await categories.refresh())) !== null)
      }
    } finally {
      wanted.forEach((id) => inflight.delete(id))
    }
  }

  watch(
    () => items.value,
    () => reconcile(false),
    { immediate: true },
  )
  // The store loaded after a failure: the ids that could not be looked up get one more try.
  watch(
    () => categories.loaded,
    (loaded) => (loaded ? reconcile(true) : undefined),
  )

  return { categoryLabel }
}
