import { ref } from 'vue'
import type { App, InjectionKey, Ref } from 'vue'
import type { Router } from 'vue-router'

/** True once something unexpected failed; App shows a generic banner while it is true. */
export const appErrorKey: InjectionKey<Ref<boolean>> = Symbol('appError')

/**
 * Sends every error Vue catches (render, hooks, handlers) and every navigation error to a generic banner and nowhere else (plan phase-4 C16). The
 * error itself is dropped on purpose: nothing is logged and nothing of it reaches the page, so no detail can leak. The
 * flag lives in the app, not in a module, so each app (and each test) has its own.
 */
export function installErrorHandler(app: App, router: Router): void {
  const failed = ref(false)
  app.provide(appErrorKey, failed)
  const report = (): void => {
    failed.value = true
  }
  app.config.errorHandler = report
  // Vue Router does not use errorHandler: without a handler of its own it logs navigation errors with console.error.
  router.onError(report)
}
