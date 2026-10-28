import { createPinia, setActivePinia } from 'pinia'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import type { ApiResult } from '@/api/http'
import type { ApiError } from '@/api/problem'
import type { Category } from '@/api/types'
import { useSessionStore } from '@/stores/session'
import { useCategoriesStore } from '../categories'

const api = vi.hoisted(() => ({
  listCategories: vi.fn(),
  createCategory: vi.fn(),
  renameCategory: vi.fn(),
  deleteCategory: vi.fn(),
}))
const authApi = vi.hoisted(() => ({
  me: vi.fn(),
  login: vi.fn(),
  register: vi.fn(),
  logout: vi.fn(),
}))

vi.mock('@/api/categories', () => api)
vi.mock('@/api/auth', () => authApi)
vi.mock('@/lib/navigation', () => ({ navigateTo: vi.fn() }))

function cat(id: string, name: string, version = 1): Category {
  return { id, name, version, createdAt: '2026-10-01T10:00:00Z', updatedAt: '2026-10-01T10:00:00Z' }
}
function ok<T>(data: T, status = 200): ApiResult<T> {
  return { ok: true, status, data }
}
function failure(error: ApiError): ApiResult<never> {
  return { ok: false, error }
}
const listOf = (...items: Category[]) => ok({ items })

beforeEach(() => {
  setActivePinia(createPinia())
})
afterEach(() => {
  Object.values(api).forEach((mock) => mock.mockReset())
  Object.values(authApi).forEach((mock) => mock.mockReset())
})

describe('categories store: load and refresh', () => {
  it('starts empty and not loaded', () => {
    const store = useCategoriesStore()

    expect(store.items).toEqual([])
    expect(store.loaded).toBe(false)
  })

  it('refresh() replaces the items with the server list, in the server order', async () => {
    api.listCategories.mockResolvedValueOnce(listOf(cat('a', 'Apples'), cat('z', 'Zoo')))
    const store = useCategoriesStore()
    await store.refresh()
    api.listCategories.mockResolvedValueOnce(listOf(cat('b', 'Bread')))

    const error = await store.refresh()

    expect(error).toBeNull()
    expect(store.items.map((c) => c.name)).toEqual(['Bread'])
  })

  it('refresh() keeps the items and returns the error when the call fails', async () => {
    api.listCategories.mockResolvedValueOnce(listOf(cat('a', 'Apples')))
    const store = useCategoriesStore()
    await store.refresh()
    api.listCategories.mockResolvedValueOnce(failure({ kind: 'network' }))

    const error = await store.refresh()

    expect(error).toEqual({ kind: 'network' })
    expect(store.items).toHaveLength(1)
  })

  it('load() asks the server once, however many callers', async () => {
    api.listCategories.mockResolvedValue(listOf(cat('a', 'Apples')))
    const store = useCategoriesStore()

    await Promise.all([store.load(), store.load()])
    await store.load()

    expect(api.listCategories).toHaveBeenCalledTimes(1)
    expect(store.loaded).toBe(true)
  })

  it('load() asks again after a failure', async () => {
    api.listCategories.mockResolvedValueOnce(failure({ kind: 'server', status: 500 }))
    api.listCategories.mockResolvedValueOnce(listOf(cat('a', 'Apples')))
    const store = useCategoriesStore()

    const first = await store.load()
    expect(store.loaded).toBe(false)
    const second = await store.load()

    expect(first).toEqual({ kind: 'server', status: 500 })
    expect(second).toBeNull()
    expect(store.items).toHaveLength(1)
  })

  it('drops an older refresh that answers after a newer one', async () => {
    let releaseOld!: (value: ApiResult<unknown>) => void
    api.listCategories.mockReturnValueOnce(new Promise((resolve) => (releaseOld = resolve)))
    api.listCategories.mockResolvedValueOnce(listOf(cat('n', 'New')))
    const store = useCategoriesStore()

    const old = store.refresh()
    await store.refresh()
    releaseOld(listOf(cat('o', 'Old')))
    await old

    expect(store.items.map((c) => c.name)).toEqual(['New'])
  })
})

describe('categories store: a superseded refresh', () => {
  it('load() follows the newest refresh and reports its failure instead of success', async () => {
    let releaseFirst!: (value: ApiResult<unknown>) => void
    api.listCategories.mockReturnValueOnce(new Promise((resolve) => (releaseFirst = resolve)))
    const store = useCategoriesStore()
    const loading = store.load()
    api.createCategory.mockResolvedValue(ok(cat('b', 'Bread'), 201))
    api.listCategories.mockResolvedValueOnce(failure({ kind: 'network' }))

    await store.create('Bread')
    releaseFirst(listOf(cat('o', 'Old')))

    expect(await loading).toEqual({ kind: 'network' })
    expect(store.loaded).toBe(false)
    api.listCategories.mockResolvedValueOnce(listOf(cat('b', 'Bread')))
    expect(await store.load()).toBeNull()
    expect(store.loaded).toBe(true)
  })

  it('load() reports success when the newest refresh succeeds', async () => {
    let releaseFirst!: (value: ApiResult<unknown>) => void
    api.listCategories.mockReturnValueOnce(new Promise((resolve) => (releaseFirst = resolve)))
    const store = useCategoriesStore()
    const loading = store.load()
    api.listCategories.mockResolvedValueOnce(listOf(cat('n', 'New')))
    await store.refresh()
    releaseFirst(listOf(cat('o', 'Old')))

    expect(await loading).toBeNull()
    expect(store.loaded).toBe(true)
    expect(store.items.map((c) => c.name)).toEqual(['New'])
  })
})

describe('categories store: reset', () => {
  it('reset() empties the items and allows a new load', async () => {
    api.listCategories.mockResolvedValue(listOf(cat('a', 'Apples')))
    const store = useCategoriesStore()
    await store.load()

    store.reset()

    expect(store.items).toEqual([])
    expect(store.loaded).toBe(false)
    await store.load()
    expect(api.listCategories).toHaveBeenCalledTimes(2)
  })

  it('reset() makes a response that was still in flight land nowhere', async () => {
    let release!: (value: ApiResult<unknown>) => void
    api.listCategories.mockReturnValueOnce(new Promise((resolve) => (release = resolve)))
    const store = useCategoriesStore()
    const pending = store.refresh()

    store.reset()
    release(listOf(cat('a', 'Apples')))
    await pending

    expect(store.items).toEqual([])
  })

  it('a sign-out empties the categories, so the next user never sees them', async () => {
    const user = { id: 'u1', email: 'a@example.com' }
    authApi.login.mockResolvedValue(ok(user))
    authApi.logout.mockResolvedValue(ok(undefined, 204))
    api.listCategories.mockResolvedValue(listOf(cat('a', 'Apples')))
    const session = useSessionStore()
    const store = useCategoriesStore()
    await session.login({ email: user.email, password: 'a-long-password-1' })
    await store.load()
    expect(store.items).toHaveLength(1)

    await session.logout()

    expect(store.items).toEqual([])
    expect(store.loaded).toBe(false)
  })

  it('a different user signing in on the same page starts with no categories', async () => {
    authApi.login.mockResolvedValueOnce(ok({ id: 'u1', email: 'a@example.com' }))
    authApi.login.mockResolvedValueOnce(ok({ id: 'u2', email: 'b@example.com' }))
    api.listCategories.mockResolvedValue(listOf(cat('a', 'Apples')))
    const session = useSessionStore()
    const store = useCategoriesStore()
    await session.login({ email: 'a@example.com', password: 'a-long-password-1' })
    await store.load()

    await session.login({ email: 'b@example.com', password: 'a-long-password-2' })

    expect(store.items).toEqual([])
  })
})

describe('categories store: create', () => {
  it('sends the name, then shows the server list order', async () => {
    api.listCategories.mockResolvedValueOnce(listOf(cat('a', 'Apples'), cat('z', 'Zoo')))
    const store = useCategoriesStore()
    await store.refresh()
    api.createCategory.mockResolvedValue(ok(cat('b', 'Bread'), 201))
    api.listCategories.mockResolvedValueOnce(
      listOf(cat('a', 'Apples'), cat('b', 'Bread'), cat('z', 'Zoo')),
    )

    const result = await store.create('Bread')

    expect(api.createCategory).toHaveBeenCalledWith({ name: 'Bread' })
    expect(result.ok).toBe(true)
    expect(store.items.map((c) => c.name)).toEqual(['Apples', 'Bread', 'Zoo'])
  })

  it('keeps the new category when the refresh after it fails', async () => {
    api.createCategory.mockResolvedValue(ok(cat('b', 'Bread'), 201))
    api.listCategories.mockResolvedValueOnce(failure({ kind: 'network' }))
    const store = useCategoriesStore()

    await store.create('Bread')

    expect(store.items.map((c) => c.name)).toEqual(['Bread'])
  })

  it('changes nothing when the create fails', async () => {
    api.createCategory.mockResolvedValue(failure({ kind: 'conflict', status: 409, detail: 'x' }))
    const store = useCategoriesStore()

    const result = await store.create('Bread')

    expect(result.ok).toBe(false)
    expect(store.items).toEqual([])
    expect(api.listCategories).not.toHaveBeenCalled()
  })
})

describe('categories store: rename', () => {
  async function withApples() {
    api.listCategories.mockResolvedValueOnce(listOf(cat('a', 'Apples', 3), cat('z', 'Zoo')))
    const store = useCategoriesStore()
    await store.refresh()
    return store
  }

  it('sends name and the category version, then stores the answer', async () => {
    const store = await withApples()
    api.renameCategory.mockResolvedValue(ok(cat('a', 'Pears', 4)))

    await store.rename(cat('a', 'Apples', 3), 'Pears')

    expect(api.renameCategory).toHaveBeenCalledWith('a', { name: 'Pears', version: 3 })
    expect(store.items[0]).toMatchObject({ name: 'Pears', version: 4 })
  })

  it('removes the category when the server says 404', async () => {
    const store = await withApples()
    api.renameCategory.mockResolvedValue(failure({ kind: 'not-found', status: 404 }))

    const result = await store.rename(cat('a', 'Apples', 3), 'Pears')

    expect(result.ok).toBe(false)
    expect(store.items.map((c) => c.id)).toEqual(['z'])
  })

  it('keeps everything on a 409', async () => {
    const store = await withApples()
    api.renameCategory.mockResolvedValue(failure({ kind: 'conflict', status: 409, detail: 'x' }))

    await store.rename(cat('a', 'Apples', 3), 'Pears')

    expect(store.items[0]).toMatchObject({ name: 'Apples', version: 3 })
  })
})

describe('categories store: remove', () => {
  async function withTwo() {
    api.listCategories.mockResolvedValueOnce(listOf(cat('a', 'Apples'), cat('z', 'Zoo')))
    const store = useCategoriesStore()
    await store.refresh()
    return store
  }

  it('removes the category on 204', async () => {
    const store = await withTwo()
    api.deleteCategory.mockResolvedValue(ok(undefined, 204))

    await store.remove('a')

    expect(api.deleteCategory).toHaveBeenCalledWith('a')
    expect(store.items.map((c) => c.id)).toEqual(['z'])
  })

  it('removes the category on 404 too, and still returns the error', async () => {
    const store = await withTwo()
    api.deleteCategory.mockResolvedValue(failure({ kind: 'not-found', status: 404 }))

    const result = await store.remove('a')

    expect(result).toEqual({ ok: false, error: { kind: 'not-found', status: 404 } })
    expect(store.items.map((c) => c.id)).toEqual(['z'])
  })

  it('keeps the category on a server error', async () => {
    const store = await withTwo()
    api.deleteCategory.mockResolvedValue(failure({ kind: 'server', status: 500 }))

    await store.remove('a')

    expect(store.items).toHaveLength(2)
  })
})
