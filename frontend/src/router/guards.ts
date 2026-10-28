import type { RouteLocationNormalized, RouteLocationRaw, Router } from 'vue-router'

import { useSessionStore } from '@/stores/session'
import type { SessionStatus } from '@/stores/session'
import { DEFAULT_REDIRECT } from './redirect'

type Destination = Pick<RouteLocationNormalized, 'meta' | 'fullPath'>

/**
 * Where a visitor in this session state must go instead of `to`, or `true` to stay. Shared by the guard and by the
 * Retry button of the "Can't reach the server" page, which re-applies it once the session is known.
 *
 * `unreachable` always stays: the route is kept and App shows the service-unavailable page over it, so the address is
 * not lost and the user is never sent to /login on a guess.
 */
export function redirectFor(to: Destination, status: SessionStatus): RouteLocationRaw | true {
  const access = to.meta.access ?? 'private'
  if (access === 'private' && status === 'signed-out') {
    return { name: 'login', query: { redirect: to.fullPath } }
  }
  if (access === 'guest' && status === 'signed-in') {
    return DEFAULT_REDIRECT
  }
  return true
}

/** Adds the navigation guard. It returns a value and never calls `next()`; the store is read inside the guard. */
export function installGuards(router: Router): void {
  router.beforeEach(async (to) => {
    if (to.meta.access === 'public') {
      return true
    }
    const session = useSessionStore()
    await session.ensureLoaded()
    return redirectFor(to, session.status)
  })
}
