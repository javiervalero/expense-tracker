import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { h } from 'vue'
import { createMemoryHistory } from 'vue-router'
import type { RouteLocationNormalized, Router } from 'vue-router'
import { afterEach, describe, expect, it, vi } from 'vitest'

import type { ApiResult } from '../../api/http'
import type { User } from '../../api/types'
import App from '../../App.vue'
import { createAppRouter } from '..'
import { installFocusManagement } from '../focus'
import { useSessionStore } from '../../stores/session'

const api = vi.hoisted(() => ({ me: vi.fn() }))
vi.mock('../../api/auth', () => api)
vi.mock('../../lib/navigation', () => ({ navigateTo: vi.fn() }))
vi.mock('@/api/categories', () =>
  import('@/api/__tests__/emptyCategoriesApi').then((m) => m.emptyCategoriesApi()),
)
vi.mock('@/api/expenses', () =>
  import('@/api/__tests__/emptyExpensesApi').then((m) => m.emptyExpensesApi()),
)

const signedIn: ApiResult<User> = { ok: true, status: 200, data: { id: 'u', email: 'a@b.c' } }
let mounted: ReturnType<typeof mount> | null = null

afterEach(() => {
  mounted?.unmount()
  mounted = null
  api.me.mockReset()
  vi.unstubAllGlobals()
  document.title = ''
})

async function start(path: string, me: ApiResult<User> = signedIn) {
  vi.stubGlobal(
    'fetch',
    vi.fn(() => Promise.resolve(new Response('{"status":"ok"}'))),
  )
  api.me.mockResolvedValue(me)
  const pinia = createPinia()
  setActivePinia(pinia)
  const router = createAppRouter(createMemoryHistory())
  mounted = mount(App, { global: { plugins: [pinia, router] }, attachTo: document.body })
  await router.push(path)
  await flushPromises()
  return router
}

describe('focus management after navigation', () => {
  it('sets the title to "<Page> · Expense tracker" and focuses the h1', async () => {
    const router = await start('/expenses')

    await router.push('/categories')
    await flushPromises()

    expect(document.title).toBe('Categories · Expense tracker')
    expect(document.activeElement).toBe(document.querySelector('main h1'))
    expect(document.activeElement?.textContent).toBe('Categories')
  })

  it('does it again on the next navigation', async () => {
    const router = await start('/categories')

    await router.push('/expenses')
    await flushPromises()

    expect(document.title).toBe('Expenses · Expense tracker')
    expect(document.activeElement?.textContent).toBe('Expenses')
  })

  it('uses the title of the page the visitor really lands on after a redirect', async () => {
    await start('/categories', { ok: false, error: { kind: 'unauthorized', status: 401 } })

    expect(document.title).toBe('Sign in · Expense tracker')
  })

  it('on the first page load sets the title but leaves focus alone, so the skip link is the first Tab stop', async () => {
    await start('/categories')

    expect(document.title).toBe('Categories · Expense tracker')
    expect(document.activeElement).toBe(document.body)
  })

  it('does not move focus when only the query changes (filters)', async () => {
    const router = await start('/expenses')
    const other = document.createElement('button')
    document.body.append(other)
    other.focus()

    await router.push('/expenses?q=lunch')
    await flushPromises()

    expect(document.activeElement).toBe(other)
    other.remove()
  })

  it('a redirect gives the title of the page the visitor ends up on', async () => {
    const router = await start('/categories')

    await router.push('/login')
    await flushPromises()

    expect(router.currentRoute.value.path).toBe('/expenses')
    expect(document.title).toBe('Expenses · Expense tracker')
  })

  it('does nothing for a navigation that did not happen (same address again)', async () => {
    const router = await start('/categories')
    document.title = 'kept'

    await router.push('/categories')
    await flushPromises()

    expect(document.title).toBe('kept')
  })

  it('the unavailable page owns its own title and focus', async () => {
    await start('/categories', { ok: false, error: { kind: 'network' } })

    expect(document.title).toBe("Can't reach the server · Expense tracker")
    expect(document.activeElement?.textContent).toBe("Can't reach the server")
  })

  it('titles a page without meta.title with the bare app name', async () => {
    const router = await start('/categories')
    router.addRoute({
      path: '/untitled',
      component: { render: () => h('h1', 'x') },
      meta: { access: 'public' },
    })

    await router.push('/untitled')
    await flushPromises()

    expect(document.title).toBe('Expense tracker')
  })
})

describe('installFocusManagement: while the server is unreachable', () => {
  // Unit level: the unavailable page would overwrite title and focus anyway, so only the hook itself can show that it
  // stood aside.
  it('changes neither the title nor the focus', async () => {
    api.me.mockResolvedValue({ ok: false, error: { kind: 'network' } })
    setActivePinia(createPinia())
    await useSessionStore().ensureLoaded()
    let hook: Parameters<Router['afterEach']>[0] = () => undefined
    const fakeRouter = { afterEach: (fn: typeof hook) => (hook = fn) } as unknown as Router
    installFocusManagement(fakeRouter)
    const other = document.createElement('button')
    document.body.append(other)
    other.focus()
    document.title = 'kept'
    const page = {
      path: '/categories',
      meta: { title: 'Categories' },
    } as unknown as RouteLocationNormalized
    const before = { path: '/expenses', meta: {} } as unknown as RouteLocationNormalized

    await hook(page, before, undefined)
    await flushPromises()

    expect(document.title).toBe('kept')
    expect(document.activeElement).toBe(other)
    other.remove()
  })
})
