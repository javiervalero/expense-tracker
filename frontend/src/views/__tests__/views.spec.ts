import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createMemoryHistory } from 'vue-router'
import { beforeEach, describe, expect, it, vi } from 'vitest'

import App from '@/App.vue'
import { createAppRouter } from '@/router'

const api = vi.hoisted(() => ({ me: vi.fn() }))
vi.mock('@/api/auth', () => api)
vi.mock('@/api/categories', () =>
  import('@/api/__tests__/emptyCategoriesApi').then((m) => m.emptyCategoriesApi()),
)
vi.mock('@/api/expenses', () => ({
  getExpense: vi.fn(() => new Promise(() => {})),
  listExpenses: vi
    .fn()
    .mockResolvedValue({ ok: true, status: 200, data: { items: [], nextCursor: null } }),
}))
vi.mock('@/lib/navigation', () => ({ navigateTo: vi.fn() }))
vi.mock('@/api/health', () => ({ fetchHealth: () => new Promise(() => {}) }))

beforeEach(() => {
  setActivePinia(createPinia())
  api.me.mockReset()
})

async function render(path: string, signedIn: boolean) {
  api.me.mockResolvedValue(
    signedIn
      ? { ok: true, status: 200, data: { id: 'u', email: 'a@example.com' } }
      : { ok: false, error: { kind: 'unauthorized', status: 401 } },
  )
  const pinia = createPinia()
  setActivePinia(pinia)
  const router = createAppRouter(createMemoryHistory())
  await router.push(path)
  const wrapper = mount(App, { global: { plugins: [pinia, router] } })
  await flushPromises()
  return wrapper
}

describe('placeholder views', () => {
  it.each([
    ['/login', false, 'Sign in'],
    ['/register', false, 'Create account'],
    ['/expenses', true, 'Expenses'],
    ['/expenses/new', true, 'New expense'],
    ['/expenses/0b7c1f0e-5d3a-4c8e-9a41-2f6d8e7b1a90', true, 'Edit expense'],
    ['/categories', true, 'Categories'],
  ])('%s (signed in: %s) shows one h1 %j', async (path, signedIn, heading) => {
    const wrapper = await render(path, signedIn)

    expect(wrapper.findAll('h1')).toHaveLength(1)
    expect(wrapper.get('h1').text()).toBe(heading)
  })

  it('NotFound shows a page-not-found heading and never repeats the path', async () => {
    const wrapper = await render('/nope/<img src=x onerror=alert(1)>', false)

    expect(wrapper.get('h1').text()).toBe('Page not found')
    expect(wrapper.text()).not.toContain('onerror')
  })
})
