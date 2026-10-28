import { createPinia, setActivePinia } from 'pinia'
import { createMemoryHistory } from 'vue-router'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import type { ApiResult } from '@/api/http'
import type { ApiError } from '@/api/problem'
import type { User } from '@/api/types'
import { useSessionStore } from '@/stores/session'
import { createAppRouter } from '..'
import { redirectFor } from '../guards'

const api = vi.hoisted(() => ({ me: vi.fn() }))
vi.mock('@/api/auth', () => api)
vi.mock('@/lib/navigation', () => ({ navigateTo: vi.fn() }))

const user: User = { id: 'u1', email: 'a@example.com' }
const UUID = '0b7c1f0e-5d3a-4c8e-9a41-2f6d8e7b1a90'

const signedIn: ApiResult<User> = { ok: true, status: 200, data: user }
const signedOut: ApiResult<User> = { ok: false, error: { kind: 'unauthorized', status: 401 } }
const down = (error: ApiError): ApiResult<User> => ({ ok: false, error })

beforeEach(() => {
  setActivePinia(createPinia())
})

afterEach(() => {
  api.me.mockReset()
})

function newRouter() {
  return createAppRouter(createMemoryHistory())
}

describe('guards: signed out', () => {
  beforeEach(() => api.me.mockResolvedValue(signedOut))

  it('sends a private route to /login with the full path as redirect', async () => {
    const router = newRouter()

    await router.push('/expenses?from=2026-01-01')

    // The router writes the query itself: it leaves "/", "?" and "=" as they are inside a value (all legal there) and
    // encodes "&", "#" and "+". The value read back is exactly the path asked for.
    const { fullPath, name, query } = router.currentRoute.value
    expect(name).toBe('login')
    expect(fullPath).toBe('/login?redirect=/expenses?from=2026-01-01')
    expect(query).toEqual({ redirect: '/expenses?from=2026-01-01' })
  })

  it.each(['/expenses/new', '/categories', `/expenses/${UUID}`])(
    'sends %s to /login',
    async (path) => {
      const router = newRouter()

      await router.push(path)

      expect(router.currentRoute.value.name).toBe('login')
      expect(router.currentRoute.value.query.redirect).toBe(path)
    },
  )

  it.each(['/login', '/register'])('lets the guest route %s through', async (path) => {
    const router = newRouter()

    await router.push(path)

    expect(router.currentRoute.value.fullPath).toBe(path)
  })

  it('reaches not-found without being sent to /login', async () => {
    const router = newRouter()

    await router.push('/no/such/page')

    expect(router.currentRoute.value.name).toBe('not-found')
    expect(router.currentRoute.value.fullPath).toBe('/no/such/page')
  })

  it('keeps & and # of the original query inside the redirect value', async () => {
    const router = newRouter()

    await router.push({ path: '/expenses', query: { q: 'a&reason=x#b' } })

    expect(router.currentRoute.value.query).toEqual({ redirect: '/expenses?q=a%26reason=x%23b' })
    expect(router.currentRoute.value.hash).toBe('')
  })

  it('/ goes to /expenses and then to /login with that redirect', async () => {
    const router = newRouter()

    await router.push('/')

    expect(router.currentRoute.value.name).toBe('login')
    expect(router.currentRoute.value.query.redirect).toBe('/expenses')
  })
})

describe('guards: signed in', () => {
  beforeEach(() => api.me.mockResolvedValue(signedIn))

  it.each(['/login', '/register'])('sends %s to /expenses', async (path) => {
    const router = newRouter()

    await router.push(path)

    expect(router.currentRoute.value.fullPath).toBe('/expenses')
  })

  it('/ redirects to /expenses', async () => {
    const router = newRouter()

    await router.push('/')

    expect(router.currentRoute.value.fullPath).toBe('/expenses')
    expect(router.currentRoute.value.name).toBe('expenses')
  })

  it.each([
    ['/expenses', 'expenses'],
    ['/expenses/new', 'expense-new'],
    [`/expenses/${UUID}`, 'expense-edit'],
    ['/categories', 'categories'],
  ])('allows %s', async (path, name) => {
    const router = newRouter()

    await router.push(path)

    expect(router.currentRoute.value.name).toBe(name)
  })

  it('passes the id param to the edit route', async () => {
    const router = newRouter()

    await router.push(`/expenses/${UUID}`)

    expect(router.currentRoute.value.params.id).toBe(UUID)
  })

  it.each([
    ['a malformed id', '/expenses/NOT-A-UUID'],
    ['an upper-case uuid', `/expenses/${UUID.toUpperCase()}`],
    ['a uuid that is not version 4', '/expenses/0b7c1f0e-5d3a-1c8e-9a41-2f6d8e7b1a90'],
    ['an upper-case path', '/EXPENSES'],
    ['an unknown path', '/nope'],
  ])('resolves %s to not-found', async (_name, path) => {
    const router = newRouter()

    await router.push(path)

    expect(router.currentRoute.value.name).toBe('not-found')
  })

  it('every route has a plain-text title', () => {
    const router = newRouter()

    for (const route of router.getRoutes().filter((r) => r.name !== undefined)) {
      expect(route.meta.title).toEqual(expect.any(String))
      expect(route.meta.title).not.toBe('')
    }
  })
})

describe('guards: the service cannot be reached', () => {
  it.each<[string, ApiError]>([
    ['503', { kind: 'unavailable', status: 503, retryAfterSeconds: null }],
    ['network', { kind: 'network' }],
    ['timeout', { kind: 'timeout' }],
  ])('on %s keeps the private route and never goes to /login', async (_name, error) => {
    api.me.mockResolvedValue(down(error))
    const router = newRouter()

    await router.push('/expenses?from=2026-01-01')

    expect(router.currentRoute.value.fullPath).toBe('/expenses?from=2026-01-01')
    expect(useSessionStore().status).toBe('unreachable')
  })

  it('keeps / as /expenses, without going to /login', async () => {
    api.me.mockResolvedValue(down({ kind: 'network' }))
    const router = newRouter()

    await router.push('/')

    expect(router.currentRoute.value.fullPath).toBe('/expenses')
  })

  it('keeps the address of a later navigation too', async () => {
    api.me.mockResolvedValue(down({ kind: 'network' }))
    const router = newRouter()
    await router.push('/expenses')

    await router.push('/categories?x=1')

    expect(router.currentRoute.value.fullPath).toBe('/categories?x=1')
    expect(api.me).toHaveBeenCalledTimes(1)
  })

  it('keeps a guest route too', async () => {
    api.me.mockResolvedValue(down({ kind: 'network' }))
    const router = newRouter()

    await router.push('/login')

    expect(router.currentRoute.value.name).toBe('login')
  })
})

describe('guards: waiting for the session', () => {
  it('a navigation waits while the status is unknown, and asks once for many', async () => {
    let resolveMe: (value: ApiResult<User>) => void = () => {}
    api.me.mockReturnValue(
      new Promise<ApiResult<User>>((resolve) => {
        resolveMe = resolve
      }),
    )
    const router = newRouter()
    let settled = false

    const first = router.push('/expenses').then(() => (settled = true))
    const second = router.push('/categories')
    await Promise.resolve()
    await Promise.resolve()

    expect(settled).toBe(false)
    expect(useSessionStore().status).toBe('unknown')

    resolveMe(signedIn)
    await Promise.all([first, second])

    expect(api.me).toHaveBeenCalledTimes(1)
    expect(useSessionStore().status).toBe('signed-in')
    expect(router.currentRoute.value.name).toBe('categories')
  })

  it('does not ask for not-found, which is public', async () => {
    const router = newRouter()

    await router.push('/nope')

    expect(api.me).not.toHaveBeenCalled()
  })

  it('uses the store of the active pinia at navigation time', async () => {
    api.me.mockResolvedValue(signedIn)
    const router = newRouter()
    setActivePinia(createPinia())

    await router.push('/expenses')

    expect(useSessionStore().status).toBe('signed-in')
  })
})

describe('two routers', () => {
  it('have independent guards: each follows the session of its own app', async () => {
    api.me.mockResolvedValueOnce(signedOut).mockResolvedValueOnce(signedIn)
    const pinia1 = createPinia()
    const pinia2 = createPinia()
    const router1 = newRouter()
    const router2 = newRouter()

    setActivePinia(pinia1)
    await router1.push('/expenses')
    setActivePinia(pinia2)
    await router2.push('/expenses')

    expect(router1.currentRoute.value.name).toBe('login')
    expect(router2.currentRoute.value.name).toBe('expenses')
    expect(api.me).toHaveBeenCalledTimes(2)
  })
})

describe('redirectFor', () => {
  const to = (access: 'private' | 'guest' | 'public' | undefined, fullPath: string) => ({
    meta: access === undefined ? {} : { access },
    fullPath,
  })

  it('treats a route with no access as private', () => {
    expect(redirectFor(to(undefined, '/x'), 'signed-out')).toEqual({
      name: 'login',
      query: { redirect: '/x' },
    })
    expect(redirectFor(to(undefined, '/x'), 'signed-in')).toBe(true)
  })

  it('lets public routes through in every state', () => {
    for (const status of ['unknown', 'signed-in', 'signed-out', 'unreachable'] as const) {
      expect(redirectFor(to('public', '/x'), status)).toBe(true)
    }
  })

  it('sends a signed-in user away from guest routes only', () => {
    expect(redirectFor(to('guest', '/login'), 'signed-in')).toBe('/expenses')
    expect(redirectFor(to('guest', '/login'), 'signed-out')).toBe(true)
  })

  it('lets everything through when unreachable', () => {
    expect(redirectFor(to('private', '/x'), 'unreachable')).toBe(true)
    expect(redirectFor(to('guest', '/login'), 'unreachable')).toBe(true)
  })
})
