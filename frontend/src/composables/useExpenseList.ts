import { computed, ref } from 'vue'

import { deleteExpense, listExpenses } from '@/api/expenses'
import type { Expense } from '@/api/types'
import { MAX_AUTO_FOLLOW } from '@/config'
import { describeListError } from '@/lib/expenseMessages'
import { buildApiParams, checkFilters, sanitizeFilters } from '@/lib/expenseQuery'
import type { ExpenseFilters } from '@/lib/expenseQuery'

/** What one Load more click did: the number of rows added, 'restarted' after a 400, or null when nothing was added. */
export type MoreOutcome = number | 'restarted' | null
export type RemoveOutcome = 'deleted' | 'already' | 'failed' | null

type Status = 'idle' | 'loading' | 'ready' | 'failed'

/**
 * The expense list of one page (plan phase-4 C11, C13): the first page for a set of filters, "Load more" with the same
 * filters plus the cursor, and inline delete. The cursor is opaque and stays in here (never in the URL or the DOM).
 * Nothing here touches the DOM or the router, so it needs no component; the page calls `stop()` when it goes away.
 */
export function useExpenseList() {
  const items = ref<readonly Expense[]>([])
  const status = ref<Status>('idle')
  const message = ref('')
  const loadingMore = ref(false)
  const keepSearching = ref(false)
  const restarted = ref(false)
  const nextCursor = ref<string | null>(null)
  const deleting = ref<ReadonlySet<string>>(new Set())
  const rowErrors = ref<ReadonlyMap<string, string>>(new Map())
  // Bumped each time a search finishes (not when it is superseded or refused): the page announces from it.
  const settled = ref(0)
  const hasMore = computed(() => nextCursor.value !== null)

  let filters: ExpenseFilters = sanitizeFilters({ from: '', to: '', category: '', q: '' })
  // Bumped by every new search and by `stop`: an answer that finds another value belongs to a list that is gone.
  let generation = 0
  // Ids deleted since the last search: a later page that still lists one must not bring the row back.
  let deletedIds = new Set<string>()
  let controller: AbortController | null = null

  function cancelInFlight(): number {
    generation += 1
    controller?.abort()
    controller = null
    return generation
  }

  function reset(): void {
    items.value = []
    nextCursor.value = null
    keepSearching.value = false
    restarted.value = false
    message.value = ''
    loadingMore.value = false
    rowErrors.value = new Map()
  }

  // Adds a page to the list. A row deleted in this search stays gone; a repeated id (the list moved under the cursor)
  // replaces the older copy in place. Returns how many rows are new.
  function merge(page: readonly Expense[]): number {
    const fresh = page.filter((expense) => !deletedIds.has(expense.id))
    // One copy per id, the last one in the page.
    const newer = new Map(fresh.map((expense) => [expense.id, expense]))
    const known = new Set(items.value.map((expense) => expense.id))
    const kept = items.value.map((expense) => newer.get(expense.id) ?? expense)
    const added = [...newer.values()].filter((expense) => !known.has(expense.id))
    items.value = [...kept, ...added]
    return added.length
  }

  // A cursor of '' would send nothing, so it counts as the end.
  function cursorOf(value: string | null): string | null {
    return value === '' ? null : value
  }

  /**
   * Asks for pages until one adds rows, the cursor ends, or MAX_AUTO_FOLLOW extra requests were made (an empty page with
   * a cursor means "keep going", contract). A 400 on a cursor request restarts from page one, once per run.
   * Returns the rows added, 'restarted', or null (failed or superseded).
   */
  async function run(start: string | null, mine: number, first: boolean): Promise<MoreOutcome> {
    controller = new AbortController()
    const { signal } = controller
    let cursor = start
    let follows = 0
    let canRestart = start !== null
    let added = 0
    let didRestart = false
    for (;;) {
      const result = await listExpenses(buildApiParams(filters, cursor), signal)
      if (mine !== generation) {
        return null
      }
      if (!result.ok) {
        if (result.error.kind === 'bad-request' && cursor !== null && canRestart) {
          canRestart = false
          didRestart = true
          reset()
          restarted.value = true
          cursor = null
          follows = 0
          added = 0
          status.value = 'loading'
          continue
        }
        message.value = describeListError(result.error, false)
        if (first || didRestart) {
          status.value = 'failed'
        }
        return null
      }
      // What counts is what the page added, not its length: rows already known or deleted here add nothing.
      const fresh = merge(result.data.items)
      added += fresh
      nextCursor.value = cursorOf(result.data.nextCursor)
      if (fresh === 0 && nextCursor.value !== null && follows < MAX_AUTO_FOLLOW) {
        follows += 1
        cursor = nextCursor.value
        continue
      }
      keepSearching.value = fresh === 0 && nextCursor.value !== null
      status.value = 'ready'
      return didRestart ? 'restarted' : added
    }
  }

  /** A new search: the old request is aborted, the list starts again from page one. */
  async function search(next: ExpenseFilters): Promise<void> {
    const mine = cancelInFlight()
    reset()
    deletedIds = new Set()
    if (Object.keys(checkFilters(next)).length > 0) {
      status.value = 'idle'
      return
    }
    filters = sanitizeFilters(next)
    status.value = 'loading'
    await run(null, mine, true)
    if (mine === generation) {
      settled.value += 1
    }
  }

  /** Load more, and "Keep searching" after an empty stretch. Ignored while a request is on its way. */
  async function loadMore(): Promise<MoreOutcome> {
    const cursor = nextCursor.value
    if (cursor === null || loadingMore.value || status.value !== 'ready') {
      return null
    }
    const mine = generation
    loadingMore.value = true
    message.value = ''
    restarted.value = false
    try {
      return await run(cursor, mine, false)
    } finally {
      if (mine === generation) {
        loadingMore.value = false
      }
    }
  }

  function markDeleting(id: string, on: boolean): void {
    const next = new Set(deleting.value)
    if (on) {
      next.add(id)
    } else {
      next.delete(id)
    }
    deleting.value = next
  }

  /** Deletes one row. 204 and 404 both remove it (plan C13); any other failure keeps it and sets `rowErrors.get(id)`. */
  async function remove(id: string): Promise<RemoveOutcome> {
    if (deleting.value.has(id)) {
      return null
    }
    markDeleting(id, true)
    rowErrors.value = new Map([...rowErrors.value].filter(([key]) => key !== id))
    try {
      const result = await deleteExpense(id)
      if (result.ok || result.error.kind === 'not-found') {
        deletedIds.add(id)
        items.value = items.value.filter((expense) => expense.id !== id)
        return result.ok ? 'deleted' : 'already'
      }
      rowErrors.value = new Map(rowErrors.value).set(id, describeListError(result.error, true))
      return 'failed'
    } finally {
      markDeleting(id, false)
    }
  }

  /** The page is going away: abort what is in flight and drop its answer. */
  function stop(): void {
    cancelInFlight()
  }

  return {
    items,
    status,
    message,
    loadingMore,
    keepSearching,
    restarted,
    hasMore,
    deleting,
    rowErrors,
    settled,
    search,
    loadMore,
    remove,
    stop,
  }
}
