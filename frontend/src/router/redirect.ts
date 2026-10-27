import type { Router } from 'vue-router'

import { isInternalPath } from '@/lib/navigation'

/** Where a refused or missing redirect goes. */
export const DEFAULT_REDIRECT = '/expenses'

const MAX_REDIRECT_LENGTH = 2048

/**
 * Turns the `?redirect=` value into a place to go after sign-in. Only a path of this app that needs a session is
 * accepted (plan phase-4 C7); anything else gives `/expenses`. The result is the router's own resolved `fullPath`, never
 * the raw text.
 */
export function safeRedirect(raw: unknown, router: Router): string {
  if (typeof raw !== 'string' || raw.length > MAX_REDIRECT_LENGTH || !isInternalPath(raw)) {
    return DEFAULT_REDIRECT
  }
  const resolved = router.resolve(raw)
  const isPrivateRoute =
    resolved.matched.length > 0 &&
    resolved.name !== 'not-found' &&
    (resolved.meta.access ?? 'private') === 'private'
  return isPrivateRoute ? resolved.fullPath : DEFAULT_REDIRECT
}
