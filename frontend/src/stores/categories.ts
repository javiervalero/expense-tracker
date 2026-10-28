import { defineStore } from 'pinia'
import { ref, watch } from 'vue'

import * as categoriesApi from '@/api/categories'
import type { ApiResult } from '@/api/http'
import type { ApiError } from '@/api/problem'
import type { Category } from '@/api/types'
import { useSessionStore } from '@/stores/session'

type Outcome = ApiError | null | 'stale'

// The categories of the signed-in user (plan phase-4 C14). Only the actions below write `items`; the list is always in
// the order the server gave. The page that uses it keeps the form state.
export const useCategoriesStore = defineStore('categories', () => {
  const session = useSessionStore()
  const items = ref<Category[]>([])
  const loaded = ref(false)
  // The first load in flight or done, shared by every caller; dropped after a failure so the next call asks again.
  let loading: Promise<ApiError | null> | null = null
  // Bumped by every `reset`: an answer that was asked for before it belongs to the previous user and is dropped.
  let epoch = 0
  // Bumped by every `refresh`: only the newest answer is used, so a slow old one cannot overwrite a newer list.
  let newest = 0

  // The newest refresh in flight or done: a superseded one waits for it, so its caller learns how the list really ended.
  let latest: Promise<Outcome> | null = null

  function reset(): void {
    epoch += 1
    items.value = []
    loaded.value = false
    loading = null
    latest = null
  }

  // A sign-out (the id goes to undefined) or a different user empties the store. The full page load that sign-out does
  // already forgets everything; this keeps the guarantee even where the page is not reloaded (tests, a future change).
  watch(() => session.user?.id, reset, { flush: 'sync' })

  async function fetchList(): Promise<Outcome> {
    const mine = { epoch, newest: (newest += 1) }
    const result = await categoriesApi.listCategories()
    if (mine.epoch !== epoch || mine.newest !== newest) {
      return 'stale'
    }
    if (!result.ok) {
      return result.error
    }
    items.value = result.data.items
    loaded.value = true
    return null
  }

  /**
   * Replaces the items with the server list. Returns the error, or null. An answer that a newer refresh superseded
   * reports what that newer refresh reports; after a `reset` there is nothing to report, so it is null.
   */
  async function refresh(): Promise<ApiError | null> {
    const own = fetchList()
    latest = own
    let outcome = await own
    while (outcome === 'stale' && latest !== null) {
      outcome = await latest
    }
    return outcome === 'stale' ? null : outcome
  }

  /** Loads the list once per session, however many callers; asks again only after a failure. */
  function load(): Promise<ApiError | null> {
    if (loading !== null) {
      return loading
    }
    const started: Promise<ApiError | null> = refresh().then((error) => {
      if (error !== null && loading === started) {
        loading = null
      }
      return error
    })
    loading = started
    return started
  }

  /** Creates a category, then refreshes so it appears in the server's order (it is kept at the end if that fails). */
  async function create(name: string): Promise<ApiResult<Category>> {
    const mine = epoch
    const result = await categoriesApi.createCategory({ name })
    if (result.ok && mine === epoch) {
      items.value = [...items.value, result.data]
      await refresh()
    }
    return result
  }

  function without(id: string): Category[] {
    return items.value.filter((category) => category.id !== id)
  }

  /**
   * Renames with the version the caller last read. A 404 means the category is gone, so it leaves the list (the caller
   * still gets the error to explain it).
   */
  async function rename(category: Category, name: string): Promise<ApiResult<Category>> {
    const result = await categoriesApi.renameCategory(category.id, {
      name,
      version: category.version,
    })
    if (result.ok) {
      items.value = items.value.map((c) => (c.id === category.id ? result.data : c))
    } else if (result.error.kind === 'not-found') {
      items.value = without(category.id)
    }
    return result
  }

  /** Deletes. A 404 counts as done (plan phase-4 C13): the row leaves either way; the caller can tell them apart. */
  async function remove(id: string): Promise<ApiResult<void>> {
    const result = await categoriesApi.deleteCategory(id)
    if (result.ok || result.error.kind === 'not-found') {
      items.value = without(id)
    }
    return result
  }

  return { items, loaded, load, refresh, create, rename, remove, reset }
})
