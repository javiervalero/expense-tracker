import { createPinia, setActivePinia } from 'pinia'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import type { ApiResult } from '@/api/http'
import { getUnauthorizedHandler, setUnauthorizedHandler } from '@/api/http'
import type { ApiError } from '@/api/problem'
import type { User } from '@/api/types'
import { useSessionStore } from '../session'

const api = vi.hoisted(() => ({
  me: vi.fn(),
  login: vi.fn(),
  register: vi.fn(),
  logout: vi.fn(),
}))
const navigateToMock = vi.hoisted(() => vi.fn<(url: string) => void>())

vi.mock('@/api/auth', () => api)
vi.mock('@/lib/navigation', () => ({ navigateTo: navigateToMock }))

const user: User = { id: 'u1', email: 'a@example.com' }
const credentials = { email: 'a@example.com', password: 'a-long-password-1' }

function success<T>(data: T, status = 200): ApiResult<T> {
  return { ok: true, status, data }
}
function failure(error: ApiError): ApiResult<never> {
  return { ok: false, error }
}

const expiryHandler = vi.fn()

beforeEach(() => {
  setActivePinia(createPinia())
  setUnauthorizedHandler(expiryHandler)
})

afterEach(() => {
  setUnauthorizedHandler(null)
  expiryHandler.mockReset()
  Object.values(api).forEach((mock) => mock.mockReset())
  navigateToMock.mockReset()
})

describe('session store: ensureLoaded', () => {
  it('starts unknown, with no user', () => {
    const session = useSessionStore()

    expect(session.status).toBe('unknown')
    expect(session.user).toBeNull()
  })

  it('calls me() once for three concurrent callers', async () => {
    api.me.mockResolvedValue(success(user))
    const session = useSessionStore()

    await Promise.all([session.ensureLoaded(), session.ensureLoaded(), session.ensureLoaded()])
    await session.ensureLoaded()

    expect(api.me).toHaveBeenCalledTimes(1)
  })

  it('is signed in with the user after a 200', async () => {
    api.me.mockResolvedValue(success(user))
    const session = useSessionStore()

    await session.ensureLoaded()

    expect(session.status).toBe('signed-in')
    expect(session.user).toEqual(user)
  })

  it('is signed out after a 401', async () => {
    api.me.mockResolvedValue(failure({ kind: 'unauthorized', status: 401 }))
    const session = useSessionStore()

    await session.ensureLoaded()

    expect(session.status).toBe('signed-out')
    expect(session.user).toBeNull()
  })

  it.each<[string, ApiError]>([
    ['503', { kind: 'unavailable', status: 503, retryAfterSeconds: null }],
    ['500', { kind: 'server', status: 500 }],
    ['network failure', { kind: 'network' }],
    ['timeout', { kind: 'timeout' }],
    ['unreadable success body', { kind: 'server', status: 200 }],
    ['429', { kind: 'rate-limited', status: 429, retryAfterSeconds: 5 }],
  ])('is unreachable after %s, never signed out', async (_name, error) => {
    api.me.mockResolvedValue(failure(error))
    const session = useSessionStore()

    await session.ensureLoaded()

    expect(session.status).toBe('unreachable')
    expect(session.user).toBeNull()
  })

  it('a rejected me() is unreachable, and retry() recovers (no rejected memo is kept)', async () => {
    api.me.mockRejectedValueOnce(new Error('boom')).mockResolvedValueOnce(success(user))
    const session = useSessionStore()

    await expect(session.ensureLoaded()).resolves.toBeUndefined()
    expect(session.status).toBe('unreachable')

    await session.retry()
    expect(session.status).toBe('signed-in')
    expect(session.user).toEqual(user)
  })

  it('retry() asks again and can recover', async () => {
    api.me.mockResolvedValueOnce(failure({ kind: 'network' })).mockResolvedValueOnce(success(user))
    const session = useSessionStore()
    await session.ensureLoaded()

    await session.retry()

    expect(api.me).toHaveBeenCalledTimes(2)
    expect(session.status).toBe('signed-in')
    expect(session.user).toEqual(user)
  })

  it('retry() can also end signed out', async () => {
    api.me
      .mockResolvedValueOnce(failure({ kind: 'network' }))
      .mockResolvedValueOnce(failure({ kind: 'unauthorized', status: 401 }))
    const session = useSessionStore()
    await session.ensureLoaded()

    await session.retry()

    expect(session.status).toBe('signed-out')
  })

  it('keeps the memo after a retry: later callers do not ask again', async () => {
    api.me.mockResolvedValue(success(user))
    const session = useSessionStore()

    await session.retry()
    await session.ensureLoaded()

    expect(api.me).toHaveBeenCalledTimes(1)
  })

  it('a second store (new pinia) asks again: nothing is shared between apps', async () => {
    api.me.mockResolvedValue(success(user))
    await useSessionStore().ensureLoaded()
    setActivePinia(createPinia())

    await useSessionStore().ensureLoaded()

    expect(api.me).toHaveBeenCalledTimes(2)
  })
})

describe('session store: login and register', () => {
  it.each([
    ['login', 200],
    ['register', 201],
  ] as const)('%s stores the user on success and returns the result', async (name, status) => {
    const result = success(user, status)
    api[name].mockResolvedValue(result)
    const session = useSessionStore()

    const returned = await session[name](credentials)

    expect(api[name]).toHaveBeenCalledWith(credentials)
    expect(returned).toBe(result)
    expect(session.status).toBe('signed-in')
    expect(session.user).toEqual(user)
  })

  it.each([
    ['login', { kind: 'invalid-credentials', status: 401 }],
    ['register', { kind: 'conflict', status: 409, detail: null }],
    ['register', { kind: 'network' }],
  ] as const)('%s stores nothing on failure %j and returns the error', async (name, error) => {
    const result = failure(error)
    api[name].mockResolvedValue(result)
    const session = useSessionStore()

    const returned = await session[name](credentials)

    expect(returned).toBe(result)
    expect(session.status).toBe('unknown')
    expect(session.user).toBeNull()
  })

  it('a failed login does not sign out a user who is already signed in', async () => {
    api.me.mockResolvedValue(success(user))
    api.login.mockResolvedValue(failure({ kind: 'invalid-credentials', status: 401 }))
    const session = useSessionStore()
    await session.ensureLoaded()

    await session.login(credentials)

    expect(session.status).toBe('signed-in')
    expect(session.user).toEqual(user)
  })
})

describe('session store: logout', () => {
  async function signedIn() {
    api.me.mockResolvedValue(success(user))
    const session = useSessionStore()
    await session.ensureLoaded()
    return session
  }

  it('on 204 does a full page load of /login and clears the user', async () => {
    api.logout.mockResolvedValue(success(undefined, 204))
    const session = await signedIn()

    const error = await session.logout()

    expect(error).toBeNull()
    expect(navigateToMock).toHaveBeenCalledExactlyOnceWith('/login')
    expect(session.user).toBeNull()
    expect(session.status).toBe('signed-out')
  })

  it('goes to /login before it marks the user signed out', async () => {
    api.logout.mockResolvedValue(success(undefined, 204))
    const session = await signedIn()
    let statusAtNavigation: string | null = null
    navigateToMock.mockImplementation(() => {
      statusAtNavigation = session.status
    })

    await session.logout()

    expect(statusAtNavigation).toBe('signed-in')
    expect(session.status).toBe('signed-out')
  })

  it('removes the unauthorized handler for the duration of the call', async () => {
    let seenDuringCall: unknown = 'not called'
    api.logout.mockImplementation(() => {
      seenDuringCall = getUnauthorizedHandler()
      return Promise.resolve(success(undefined, 204))
    })
    const session = await signedIn()

    await session.logout()

    expect(seenDuringCall).toBeNull()
  })

  it.each<[string, ApiError]>([
    ['503', { kind: 'unavailable', status: 503, retryAfterSeconds: null }],
    ['network failure', { kind: 'network' }],
    ['500', { kind: 'server', status: 500 }],
  ])('on %s stays signed in, returns the error and restores the handler', async (_name, error) => {
    api.logout.mockResolvedValue(failure(error))
    const session = await signedIn()

    const returned = await session.logout()

    expect(returned).toEqual(error)
    expect(navigateToMock).not.toHaveBeenCalled()
    expect(session.status).toBe('signed-in')
    expect(session.user).toEqual(user)
    expect(getUnauthorizedHandler()).toBe(expiryHandler)
  })

  it('a 401 means already signed out: it goes to /login and shows no error', async () => {
    api.logout.mockResolvedValue(failure({ kind: 'unauthorized', status: 401 }))
    const session = await signedIn()

    const returned = await session.logout()

    expect(returned).toBeNull()
    expect(navigateToMock).toHaveBeenCalledExactlyOnceWith('/login')
    expect(session.status).toBe('signed-out')
    expect(session.user).toBeNull()
  })

  it('two overlapping calls make one API call and share the answer', async () => {
    let finish: (value: ApiResult<void>) => void = () => {}
    api.logout.mockReturnValue(
      new Promise<ApiResult<void>>((resolve) => {
        finish = resolve
      }),
    )
    const session = await signedIn()

    const first = session.logout()
    const second = session.logout()
    finish(failure({ kind: 'network' }))

    expect(await first).toEqual({ kind: 'network' })
    expect(await second).toEqual({ kind: 'network' })
    expect(api.logout).toHaveBeenCalledTimes(1)
    expect(getUnauthorizedHandler()).toBe(expiryHandler)
  })

  it('after a failure the user can try again, and the handler is still the original', async () => {
    api.logout
      .mockResolvedValueOnce(failure({ kind: 'network' }))
      .mockResolvedValueOnce(success(undefined, 204))
    const session = await signedIn()

    await session.logout()
    const second = await session.logout()

    expect(second).toBeNull()
    expect(api.logout).toHaveBeenCalledTimes(2)
    expect(navigateToMock).toHaveBeenCalledExactlyOnceWith('/login')
  })

  it('restores the handler when the call throws, and allows another attempt', async () => {
    api.logout
      .mockRejectedValueOnce(new Error('boom'))
      .mockResolvedValueOnce(success(undefined, 204))
    const session = await signedIn()

    await expect(session.logout()).rejects.toThrow('boom')

    expect(getUnauthorizedHandler()).toBe(expiryHandler)
    expect(session.status).toBe('signed-in')
    await expect(session.logout()).resolves.toBeNull()
  })
})
