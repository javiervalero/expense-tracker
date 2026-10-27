import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createMemoryHistory } from 'vue-router'
import type { Component } from 'vue'
import { vi } from 'vitest'

import * as authApi from '@/api/auth'
import type { ApiResult } from '@/api/http'
import type { User } from '@/api/types'
import App from '@/App.vue'
import { createAppRouter } from '@/router'

// Used by the sign-in and register specs. Each spec file mocks '@/api/auth', '@/api/health' and '@/lib/navigation'
// itself (vi.mock only works in the file that calls it); this file mounts the whole App on a real router, so the guard,
// the App watcher and focus management take part.

export const user: User = { id: 'u1', email: 'a@example.com' }
export const okUser: ApiResult<User> = { ok: true, status: 200, data: user }
export const signedOut: ApiResult<User> = {
  ok: false,
  error: { kind: 'unauthorized', status: 401 },
}

export function fail(error: object): ApiResult<User> {
  return { ok: false, error } as ApiResult<User>
}

/** Deferred promise, to hold a request "in flight". */
export function deferred<T>() {
  let resolve!: (value: T) => void
  const promise = new Promise<T>((done) => {
    resolve = done
  })
  return { promise, resolve }
}

/** Mounts App with nobody signed in (`me` answers 401) at `path`. */
/** `onError` receives what Vue would send to the app's error handler (an unexpected throw in a handler). */
export async function renderSignedOut(path: string, onError?: (error: unknown) => void) {
  vi.mocked(authApi.me).mockResolvedValue(signedOut)
  const pinia = createPinia()
  setActivePinia(pinia)
  const router = createAppRouter(createMemoryHistory())
  await router.push(path)
  const host = document.createElement('div')
  document.body.appendChild(host)
  const wrapper = mount(App, {
    attachTo: host,
    global: { plugins: [pinia, router], config: { errorHandler: onError } },
  })
  await flushPromises()
  return { router, wrapper }
}

type Wrapper =
  | Awaited<ReturnType<typeof renderSignedOut>>['wrapper']
  | Awaited<ReturnType<typeof renderBare>>['wrapper']

/** Types into the form (ids `<prefix>-email`, `<prefix>-password`) and submits it. */
export async function fillAndSubmit(
  wrapper: Wrapper,
  prefix: string,
  email: string,
  password: string,
) {
  await wrapper.get(`#${prefix}-email`).setValue(email)
  await wrapper.get(`#${prefix}-password`).setValue(password)
  await wrapper.get('form').trigger('submit')
  await flushPromises()
}

/**
 * Mounts one view on a real router with NO App around it. App's watcher also redirects a signed-in user, which hides
 * what the view itself asked for; here only the view navigates, and `replace` is spied on to see the exact path.
 */
export async function renderBare(view: Component, path: string) {
  vi.mocked(authApi.me).mockResolvedValue(signedOut)
  const pinia = createPinia()
  setActivePinia(pinia)
  const router = createAppRouter(createMemoryHistory())
  const replace = vi.spyOn(router, 'replace')
  await router.push(path)
  const host = document.createElement('div')
  document.body.appendChild(host)
  const wrapper = mount(view, { attachTo: host, global: { plugins: [pinia, router] } })
  await flushPromises()
  replace.mockClear()
  return { router, wrapper, replace }
}
