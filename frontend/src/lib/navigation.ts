// An internal path: one "/", then no second "/" or "\" (both make a browser read it as another site), and no "\", space,
// control or invisible formatting character anywhere. The one check shared by navigateTo and safeRedirect.
const INTERNAL_PATH = /^\/(?![/\\])[^\\\s\p{Cc}\p{Cf}]*$/u

export function isInternalPath(value: string): boolean {
  return INTERNAL_PATH.test(value)
}

/**
 * A full page load of an internal path of this site, so nothing held in memory survives (sign-out, expired session).
 * It lives here so tests can replace it: jsdom cannot navigate. Anything that is not an internal path throws and does
 * not navigate, so a bug can never turn this into an open redirect.
 */
export function navigateTo(url: string): void {
  if (!isInternalPath(url)) {
    throw new Error('navigateTo accepts only an internal path')
  }
  window.location.assign(url)
}
