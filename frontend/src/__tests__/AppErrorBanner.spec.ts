import { flushPromises } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createApp } from 'vue'
import { createMemoryHistory } from 'vue-router'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import App from '../App.vue'
import { installErrorHandler } from '../lib/appError'
import { createAppRouter } from '../router'

const api = vi.hoisted(() => ({ me: vi.fn() }))
const health = vi.hoisted(() => ({ fetchHealth: vi.fn() }))
vi.mock('../api/auth', () => api)
vi.mock('../api/health', () => health)
vi.mock('../lib/navigation', () => ({ navigateTo: vi.fn() }))
vi.mock('../api/categories', () =>
  import('@/api/__tests__/emptyCategoriesApi').then((m) => m.emptyCategoriesApi()),
)
vi.mock('../api/expenses', () =>
  import('@/api/__tests__/emptyExpensesApi').then((m) => m.emptyExpensesApi()),
)

const CONSOLE_METHODS = ['log', 'info', 'warn', 'error', 'debug', 'trace'] as const
const consoleSpies = CONSOLE_METHODS.map((name) =>
  vi.spyOn(console, name).mockImplementation(() => undefined),
)

beforeEach(() => {
  api.me.mockResolvedValue({ ok: true, status: 200, data: { id: 'u', email: 'a@b.c' } })
})

afterEach(() => {
  api.me.mockReset()
  health.fetchHealth.mockReset()
  consoleSpies.forEach((spy) => spy.mockClear())
  document.body.replaceChildren()
})

// An unexpected failure inside a component: the status probe rejects in onMounted, which Vue sends to errorHandler.
async function startApp() {
  health.fetchHealth.mockRejectedValue(new Error('secret internal detail'))
  const pinia = createPinia()
  setActivePinia(pinia)
  const router = createAppRouter(createMemoryHistory())
  await router.push('/expenses')
  const app = createApp(App)
  installErrorHandler(app, router)
  app.use(pinia).use(router)
  const host = document.createElement('div')
  document.body.append(host)
  app.mount(host)
  await flushPromises()
  return { app, host, router }
}

describe('global error banner', () => {
  it('shows a generic alert when something unexpected fails, without leaking the error', async () => {
    const { host } = await startApp()

    const alert = host.querySelector('[role="alert"]')
    expect(alert?.textContent).toContain('Something went wrong')
    expect(alert?.textContent).not.toContain('secret internal detail')
  })

  it('calls no console method', async () => {
    await startApp()

    for (const spy of consoleSpies) {
      expect(spy).not.toHaveBeenCalled()
    }
  })

  it('the banner can be dismissed', async () => {
    const { host } = await startApp()

    host.querySelector<HTMLButtonElement>('[role="alert"] button')?.click()
    await flushPromises()

    expect(host.querySelector('[role="alert"]')).toBeNull()
  })

  it('Dismiss moves focus to the main area', async () => {
    const { host } = await startApp()

    host.querySelector<HTMLButtonElement>('[role="alert"] button')?.click()
    await flushPromises()

    expect(document.activeElement).toBe(host.querySelector('main'))
  })

  it('a throwing navigation guard shows the banner and calls no console method', async () => {
    const { host, router } = await startApp()
    host.querySelector<HTMLButtonElement>('[role="alert"] button')?.click()
    await flushPromises()
    consoleSpies.forEach((spy) => spy.mockClear())
    router.beforeEach((to) => {
      if (to.path === '/categories') {
        throw new Error('guard exploded')
      }
    })

    await router.push('/categories').catch(() => undefined)
    await flushPromises()

    expect(host.querySelector('[role="alert"]')?.textContent).toContain('Something went wrong')
    for (const spy of consoleSpies) {
      expect(spy).not.toHaveBeenCalled()
    }
  })

  it('keeps the page usable behind the banner', async () => {
    const { host } = await startApp()

    expect(host.querySelector('main h1')?.textContent).toBe('Expenses')
  })
})

describe('installErrorHandler', () => {
  it('registers a handler that is safe to call with anything', () => {
    const app = createApp({ render: () => null })
    installErrorHandler(app, createAppRouter(createMemoryHistory()))

    expect(() => app.config.errorHandler?.(undefined, null, '')).not.toThrow()
  })
})
