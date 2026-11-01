import { DEV_UPLOAD_ORIGIN } from '../config'
import { appOrigin } from './navigation'

// Anything that is not a visible ASCII character (space, control and invisible characters, non-ASCII text) or is a
// backslash: the URL parser would quietly repair these (strip, drop, convert to punycode, turn "\" into "/"), so such
// text is refused before parsing. Signed bucket addresses are plain ASCII.
const FORBIDDEN_CHARACTER = /[^\x21-\x7e]|\\/u

// The scheme must be followed by exactly "//": the parser also reads "https:x.example" and "https:///x.example".
const SCHEME_AND_SLASHES = /^https?:\/\/(?!\/)/iu

function parse(text: string): URL | null {
  if (FORBIDDEN_CHARACTER.test(text) || !SCHEME_AND_SLASHES.test(text)) {
    return null
  }
  try {
    return new URL(text)
  } catch {
    return null
  }
}

// Plain http for the local object store is a development convenience only; a production build accepts https alone.
function isAllowedOrigin(url: URL): boolean {
  if (url.origin === appOrigin()) {
    // Same-origin requests carry the session cookie even without withCredentials, so the app's own origin never
    // counts as a bucket.
    return false
  }
  return url.protocol === 'https:' || (import.meta.env.DEV && url.origin === DEV_UPLOAD_ORIGIN)
}

// An allowed origin, no userinfo, no fragment (also not an empty one).
function isSafeLocation(url: URL, text: string): boolean {
  return isAllowedOrigin(url) && url.username === '' && url.password === '' && !text.includes('#')
}

function isSafe(text: string | null | undefined, allowQuery: boolean): boolean {
  if (typeof text !== 'string') {
    return false
  }
  const url = parse(text)
  return url !== null && isSafeLocation(url, text) && (allowQuery || !text.includes('?'))
}

/** An address the browser may POST a receipt to (plan C19): https or the dev origin, and no query. */
export function isSafeUploadUrl(text: string | null | undefined): boolean {
  return isSafe(text, false)
}

/** An address that may be opened or shown as an image (plan C19): like the upload check, but a query is allowed. */
export function isSafeViewUrl(text: string | null | undefined): boolean {
  return isSafe(text, true)
}
