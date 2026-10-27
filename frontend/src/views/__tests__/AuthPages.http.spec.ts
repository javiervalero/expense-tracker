import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createMemoryHistory } from 'vue-router'
import { afterEach, describe, expect, it, vi } from 'vitest'

import App from '@/App.vue'
import { createAppRouter } from '@/router'

// The real API client and session store over a stubbed fetch: the message shown for an error response must come from
// the client's own fixed text, never from the body the server sent.
vi.mock('@/lib/navigation', async (original) => ({
  ...(await original<typeof import('@/lib/navigation')>()),
  navigateTo: vi.fn(),
}))
vi.mock('@/api/health', () => ({ fetchHealth: () => new Promise(() => {}) }))

const SERVER_TEXT = 'Server detail that must never appear'

function problem(status: number, extra: object = {}, headers: object = {}): Response {
  return new Response(JSON.stringify({ title: 'T', status, detail: SERVER_TEXT, ...extra }), {
    status,
    headers: { 'Content-Type': 'application/problem+json', ...headers },
  })
}

function stubApi(answer: (url: string) => Response) {
  const fetchMock = vi.fn((url: string) => Promise.resolve(answer(url)))
  vi.stubGlobal('fetch', fetchMock)
  return fetchMock
}

async function open(path: string) {
  const pinia = createPinia()
  setActivePinia(pinia)
  const router = createAppRouter(createMemoryHistory())
  await router.push(path)
  const host = document.createElement('div')
  document.body.appendChild(host)
  const wrapper = mount(App, { attachTo: host, global: { plugins: [pinia, router] } })
  await flushPromises()
  return wrapper
}

afterEach(() => {
  vi.unstubAllGlobals()
  document.body.replaceChildren()
})

describe('sign-in and register over the real client', () => {
  it('a 401 from sign-in shows the fixed message, not the server detail', async () => {
    stubApi(() => problem(401))
    const wrapper = await open('/login')
    await wrapper.get('#login-email').setValue('a@example.com')
    await wrapper.get('#login-password').setValue('whatever password')

    await wrapper.get('form').trigger('submit')
    await flushPromises()

    expect(wrapper.get('[role="alert"]').text()).toBe('Email or password is incorrect.')
    expect(wrapper.html()).not.toContain(SERVER_TEXT)
  })

  it('a 429 from sign-in reads Retry-After 897 as about 15 minutes', async () => {
    stubApi((url) =>
      url.endsWith('/auth/me') ? problem(401) : problem(429, {}, { 'Retry-After': '897' }),
    )
    const wrapper = await open('/login')
    await wrapper.get('#login-email').setValue('a@example.com')
    await wrapper.get('#login-password').setValue('whatever password')

    await wrapper.get('form').trigger('submit')
    await flushPromises()

    expect(wrapper.get('[role="alert"]').text()).toContain('about 15 minutes')
    expect(wrapper.html()).not.toContain(SERVER_TEXT)
  })

  it.each([
    [500, 'Something went wrong on our side. Try again.'],
    [
      503,
      'The service is busy. Try again later. If you tried before, your account may exist: try signing in.',
    ],
  ])('a %i shows fixed text, never the server detail', async (status, message) => {
    stubApi((url) => (url.endsWith('/auth/me') ? problem(401) : problem(status)))
    const wrapper = await open('/register')
    await wrapper.get('#register-email').setValue('a@example.com')
    await wrapper.get('#register-password').setValue('a long enough password')

    await wrapper.get('form').trigger('submit')
    await flushPromises()

    expect(wrapper.get('[role="alert"]').text()).toBe(message)
    expect(wrapper.html()).not.toContain(SERVER_TEXT)
  })

  it('the password travels only in the body of one POST, never in the address or the page', async () => {
    const secret = 'uniq-secret-pw-91 xyz'
    const fetchMock = stubApi((url) => (url.endsWith('/auth/me') ? problem(401) : problem(401)))
    const wrapper = await open('/login')
    await wrapper.get('#login-email').setValue('a@example.com')
    await wrapper.get('#login-password').setValue(secret)

    await wrapper.get('form').trigger('submit')
    await flushPromises()

    const [url, init] = fetchMock.mock.calls.find(([called]) =>
      called.endsWith('/auth/login'),
    ) as unknown as [string, RequestInit]
    expect(url).not.toContain('uniq-secret')
    expect(init.method).toBe('POST')
    expect(String(init.body)).toContain(secret)
    expect(JSON.stringify({ ...init, body: undefined })).not.toContain('uniq-secret')
    expect(wrapper.html()).not.toContain('uniq-secret')
    expect(wrapper.get('#login-password').attributes('value')).toBeUndefined()
  })

  it('a 201 from register signs in and lands on /expenses', async () => {
    stubApi((url) =>
      url.endsWith('/auth/me')
        ? problem(401)
        : new Response(JSON.stringify({ id: 'u1', email: 'a@example.com' }), {
            status: 201,
            headers: { 'Content-Type': 'application/json' },
          }),
    )
    const wrapper = await open('/register')
    await wrapper.get('#register-email').setValue('a@example.com')
    await wrapper.get('#register-password').setValue('a long enough password')

    await wrapper.get('form').trigger('submit')
    await flushPromises()

    expect(wrapper.get('h1').text()).toBe('Expenses')
  })
})
