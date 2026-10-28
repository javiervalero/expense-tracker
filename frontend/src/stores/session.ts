import { defineStore } from 'pinia'
import { ref } from 'vue'

import * as authApi from '@/api/auth'
import { getUnauthorizedHandler, setUnauthorizedHandler } from '@/api/http'
import type { ApiResult } from '@/api/http'
import type { ApiError } from '@/api/problem'
import type { Credentials, User } from '@/api/types'
import { navigateTo } from '@/lib/navigation'
import { announceSignedIn, announceSignedOut } from '@/lib/sessionChannel'

/**
 * `unknown`: not asked yet. `unreachable`: the server could not be asked or answered with an error; that says nothing
 * about the session, so the app never treats it as signed out (plan phase-4 C5).
 */
export type SessionStatus = 'unknown' | 'signed-in' | 'signed-out' | 'unreachable'

// Only the actions below write `status` and `user`; views and components read them and never assign them.
export const useSessionStore = defineStore('session', () => {
  const status = ref<SessionStatus>('unknown')
  const user = ref<User | null>(null)
  // The memo lives in the store instance, so each app (and each test) has its own.
  let loading: Promise<void> | null = null

  function setSignedIn(next: User): void {
    user.value = next
    status.value = 'signed-in'
  }

  function setSignedOut(): void {
    user.value = null
    status.value = 'signed-out'
  }

  async function load(): Promise<void> {
    let result: ApiResult<User>
    try {
      result = await authApi.me()
    } catch {
      // `request` never throws, but a rejected memo would stay rejected for every later caller, so it counts as down.
      user.value = null
      status.value = 'unreachable'
      return
    }
    if (result.ok) {
      setSignedIn(result.data)
    } else if (result.error.kind === 'unauthorized') {
      setSignedOut()
    } else {
      // Network, timeout, 5xx, 429 or an unreadable body: unknown, not "signed out".
      user.value = null
      status.value = 'unreachable'
    }
  }

  /** Asks `GET /auth/me` once, however many callers and however often it is called. */
  function ensureLoaded(): Promise<void> {
    loading ??= load()
    return loading
  }

  /** For the "Can't reach the server" page: forgets the answer and asks again. */
  function retry(): Promise<void> {
    loading = null
    return ensureLoaded()
  }

  async function login(credentials: Credentials): Promise<ApiResult<User>> {
    const result = await authApi.login(credentials)
    if (result.ok) {
      setSignedIn(result.data)
      announceSignedIn(result.data.id)
    }
    return result
  }

  async function register(credentials: Credentials): Promise<ApiResult<User>> {
    const result = await authApi.register(credentials)
    if (result.ok) {
      setSignedIn(result.data)
      announceSignedIn(result.data.id)
    }
    return result
  }

  // The sign-out in flight, shared by overlapping calls (two clicks while the server is slow or down).
  let leaving: Promise<ApiError | null> | null = null

  async function signOut(): Promise<ApiError | null> {
    // A 401 from a request that was already running must not show "your session ended" while the user is leaving.
    const handler = getUnauthorizedHandler()
    setUnauthorizedHandler(null)
    let left = false
    try {
      const result = await authApi.logout()
      // A 401 on logout means the session was already gone, which is what the user asked for: go to /login as for 204,
      // rather than telling them sign-out failed while they are in fact signed out.
      if (result.ok || result.error.kind === 'unauthorized') {
        left = true
        if (result.ok) {
          announceSignedOut()
        }
        navigateTo('/login')
        setSignedOut()
        return null
      }
      return result.error
    } finally {
      // Also when something throws: the handler must never stay removed. After a sign-out the page is reloading.
      if (!left) {
        setUnauthorizedHandler(handler)
      }
    }
  }

  /**
   * Signs out and does a full page load of /login, so nothing of this user stays in memory. Returns null on success, or
   * the error when the server did not confirm (the user stays signed in and the view says "Sign-out failed"). A call
   * while one is running returns the same promise; after a failure the user can try again.
   */
  function logout(): Promise<ApiError | null> {
    leaving ??= signOut().then(
      (error) => {
        if (error !== null) {
          leaving = null
        }
        return error
      },
      (thrown: unknown) => {
        leaving = null
        throw thrown
      },
    )
    return leaving
  }

  return { status, user, ensureLoaded, retry, login, register, logout }
})
