import { createMemoryHistory } from 'vue-router'
import { createPinia, setActivePinia } from 'pinia'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import { request, setUnauthorizedHandler } from '@/api/http'
import { createAppRouter } from '..'
import { installSessionExpiry } from '../expiry'

const navigateToMock = vi.hoisted(() => vi.fn<(url: string) => void>())
vi.mock('@/lib/navigation', () => ({ navigateTo: navigateToMock }))
vi.mock('@/api/auth', () => ({
  me: vi.fn(() => Promise.resolve({ ok: true, status: 200, data: { id: 'u', email: 'a@b.c' } })),
}))

beforeEach(() => {
  setActivePinia(createPinia())
})

afterEach(() => {
  setUnauthorizedHandler(null)
  navigateToMock.mockReset()
  vi.unstubAllGlobals()
})

async function routerAt(path: string) {
  const router = createAppRouter(createMemoryHistory())
  await router.push(path)
  return router
}

async function expire(): Promise<void> {
  vi.stubGlobal(
    'fetch',
    vi.fn(() =>
      Promise.resolve(
        new Response(JSON.stringify({ title: 'T', status: 401 }), {
          status: 401,
          headers: { 'Content-Type': 'application/problem+json' },
        }),
      ),
    ),
  )
  await request('GET', '/expenses', { auth: 'session' })
}

describe('installSessionExpiry', () => {
  it('sends the browser to /login with the current path and reason=expired, once', async () => {
    const router = await routerAt('/expenses?from=2026-01-01')
    installSessionExpiry(router)

    await expire()
    await expire()

    expect(navigateToMock).toHaveBeenCalledExactlyOnceWith(
      '/login?redirect=%2Fexpenses%3Ffrom%3D2026-01-01&reason=expired',
    )
  })

  it('does nothing until a session call gets a 401', async () => {
    const router = await routerAt('/expenses')

    installSessionExpiry(router)

    expect(navigateToMock).not.toHaveBeenCalled()
  })

  it('encodes & and # in the current query so they cannot add parameters', async () => {
    const router = await routerAt('/expenses?q=' + encodeURIComponent('a&reason=x#b'))
    installSessionExpiry(router)

    await expire()

    const url = new URL(navigateToMock.mock.calls[0]![0], 'http://localhost')
    expect(url.pathname).toBe('/login')
    expect([...url.searchParams.keys()]).toEqual(['redirect', 'reason'])
    expect(url.searchParams.get('reason')).toBe('expired')
    expect(url.searchParams.get('redirect')).toBe('/expenses?q=a%26reason%3Dx%23b')
    expect(url.hash).toBe('')
  })

  it('reads the route at the time of the 401, not at install time', async () => {
    const router = await routerAt('/expenses')
    installSessionExpiry(router)
    await router.push('/categories')

    await expire()

    expect(navigateToMock).toHaveBeenCalledExactlyOnceWith(
      '/login?redirect=%2Fcategories&reason=expired',
    )
  })
})
