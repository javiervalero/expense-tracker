import { nextTick } from 'vue'
import { START_LOCATION } from 'vue-router'
import type { Router } from 'vue-router'

import { useSessionStore } from '@/stores/session'

const APP_NAME = 'Expense tracker'

/**
 * After each navigation: the tab title becomes "<Page> · Expense tracker" and focus moves to the page's h1, so a screen
 * reader announces the new page (plan phase-4 C15). The title is the route's fixed `meta.title`, never user text.
 *
 * Skipped: a navigation that did not happen; the first page load (title only); a change of the query only (applying a
 * filter must not pull focus away); and while "Can't reach the server" is shown, because that page sets its own title
 * and focus.
 */
export function installFocusManagement(router: Router): void {
  router.afterEach((to, from, failure) => {
    if (failure || useSessionStore().status === 'unreachable') {
      return
    }
    document.title = to.meta.title ? `${to.meta.title} · ${APP_NAME}` : APP_NAME
    // The first page load keeps focus where the browser put it, so the skip link stays the first Tab stop.
    if (from === START_LOCATION || to.path === from.path) {
      return
    }
    // The new view is drawn after this hook returns.
    void nextTick(() => {
      const heading = document.querySelector<HTMLElement>('main h1')
      if (heading) {
        // Views declare it too; this keeps a heading that forgot from being unfocusable.
        heading.tabIndex = -1
        heading.focus()
      }
    })
  })
}
