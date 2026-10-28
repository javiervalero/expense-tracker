import type { Router } from 'vue-router'

import { setUnauthorizedHandler } from '@/api/http'
import { navigateTo } from '@/lib/navigation'

/**
 * Sets the one handler for "a secured call got a 401 in the middle of a session" (plan phase-4 C5). Call it once, from
 * main.ts. It does a full page load of /login with the page the user was on, so nothing of the old session stays in
 * memory; `reason` is a fixed value the login page turns into "Your session ended".
 */
export function installSessionExpiry(router: Router): void {
  setUnauthorizedHandler(() => {
    const params = new URLSearchParams({
      redirect: router.currentRoute.value.fullPath,
      reason: 'expired',
    })
    navigateTo(`/login?${params.toString()}`)
  })
}
