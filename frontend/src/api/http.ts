import { API_BASE, REQUEST_TIMEOUT_MS } from '../config'
import { failureReason } from './abort'
import { mapResponseError } from './problem'
import type { ApiError } from './problem'

/**
 * `server` with a 2xx status means "the success body was unreadable" (not JSON); any other `ApiError` comes from a
 * non-2xx answer or from a failure before an answer.
 */
export type ApiResult<T> = { ok: true; status: number; data: T } | { ok: false; error: ApiError }

export type HttpMethod = 'GET' | 'POST' | 'PUT' | 'PATCH' | 'DELETE'

/**
 * What a 401 means for this call. Required, so no call can forget to say:
 * - `session`: an ordinary call that needs the session; a 401 means it expired, so the unauthorized handler runs;
 * - `probe`: asking whether there is a session (`GET /auth/me`); a 401 is the normal "signed out", kind `unauthorized`,
 *   and the handler does not run;
 * - `credentials`: sign-in; a 401 means wrong email or password (kind `invalid-credentials`), and the handler does not run;
 * - `public`: a call that needs no session (register); a 401 is kind `unauthorized`, and the handler does not run.
 */
export type AuthMode = 'session' | 'probe' | 'credentials' | 'public'

export interface RequestOptions {
  auth: AuthMode
  /** A JSON-serialisable body. Adds `Content-Type: application/json`. */
  body?: unknown
  /** Query parameters, encoded by URLSearchParams and added after the path has been checked. */
  query?: URLSearchParams
  /** Cancels the request (for example when a component is unmounted). Combined with the timeout. */
  signal?: AbortSignal
}

let unauthorizedHandler: (() => void) | null = null
let unauthorizedFired = false

/**
 * Sets the function to run when a `session` call gets a 401. It runs once, however many 401s arrive, until this
 * function is called again (which re-arms it); `null` removes the handler. It must not throw: if it does, the error is
 * dropped so `request` can keep its promise never to throw.
 */
export function setUnauthorizedHandler(handler: (() => void) | null): void {
  unauthorizedHandler = handler
  unauthorizedFired = false
}

function notifyUnauthorized(): void {
  if (unauthorizedFired || unauthorizedHandler === null) {
    return
  }
  unauthorizedFired = true
  try {
    unauthorizedHandler()
  } catch {
    // Nothing useful to do here and nothing to log (no console in app code). It never re-arms on its own, because the
    // real handler does a full page load (plan C5); a handler that failed has not, so let the next 401 try again.
    unauthorizedFired = false
  }
}

// Letters, digits and "-", "_", ".", "~" between single slashes. Stricter than a URL allows, on purpose: no "%", "?",
// "#", "@", "\", spaces or control characters can reach the address.
const SAFE_PATH = /^(?:\/[A-Za-z0-9._~-]+)+$/
const SEGMENT = /^[A-Za-z0-9-]{1,64}$/

function isSafePath(path: string): boolean {
  return (
    SAFE_PATH.test(path) && !path.split('/').some((segment) => segment === '.' || segment === '..')
  )
}

/**
 * The whole path of one resource, or null when `id` is not 1 to 64 letters, digits or "-" (a uuid qualifies). Callers
 * must not build these paths by hand: an unchecked value in a template literal (even `null`) would still be sent.
 */
export function resourcePath(collection: '/categories' | '/expenses', id: string): string | null {
  return SEGMENT.test(id) ? `${collection}/${id}` : null
}

function buildHeaders(hasBody: boolean): Record<string, string> {
  // The complete set, on every request. CloudFront forwards only these headers (ADR 0012 section 4.1): add no other.
  return {
    Accept: 'application/json',
    'X-Requested-With': 'XMLHttpRequest',
    ...(hasBody ? { 'Content-Type': 'application/json' } : {}),
  }
}

function parseJson(text: string): { ok: true; value: unknown } | { ok: false } {
  try {
    return { ok: true, value: JSON.parse(text) as unknown }
  } catch {
    return { ok: false }
  }
}

/** The media type alone (no parameters, any case) must be exactly JSON or problem JSON. */
function isJsonContent(response: Response): boolean {
  const mediaType = (response.headers.get('Content-Type') ?? '').split(';')[0]?.trim().toLowerCase()
  return mediaType === 'application/json' || mediaType === 'application/problem+json'
}

function buildUrl(path: string, query: URLSearchParams | undefined): string {
  const search = query?.toString() ?? ''
  return `${API_BASE}${path}${search === '' ? '' : `?${search}`}`
}

/**
 * Calls the API. Never throws: every failure is a value in `ApiResult`. A path that is not a plain API path is
 * refused as `invalid-request` before anything is sent (build resource paths with `resourcePath`).
 *
 * A 204 gives `data: undefined`: call those endpoints as `request<void>`. Success bodies are trusted to match `T` (the
 * server checks its answers against the contract, ADR 0011); problem bodies are not.
 */
export async function request<T>(
  method: HttpMethod,
  path: string,
  options: RequestOptions,
): Promise<ApiResult<T>> {
  if (!isSafePath(path)) {
    return { ok: false, error: { kind: 'invalid-request' } }
  }
  const { body, signal, query, auth } = options
  const hasBody = body !== undefined

  let response: Response
  let text = ''
  try {
    // Inside the try: a browser without these functions gives `network`, not a throw.
    const timeout = AbortSignal.timeout(REQUEST_TIMEOUT_MS)
    const combined = signal ? AbortSignal.any([signal, timeout]) : timeout
    response = await fetch(buildUrl(path, query), {
      method,
      headers: buildHeaders(hasBody),
      credentials: 'same-origin',
      // The API never redirects; a redirect would be something in the way, so fail rather than follow it.
      redirect: 'error',
      signal: combined,
      ...(hasBody ? { body: JSON.stringify(body) } : {}),
    })
    if (response.status !== 204) {
      text = await response.text()
    }
  } catch (error) {
    return { ok: false, error: { kind: failureReason(error, signal) } }
  }

  if (response.status === 204) {
    // The one cast of "no data" to T: 204 endpoints are called as request<void>.
    return { ok: true, status: 204, data: undefined as T }
  }
  const parsed = parseJson(text)
  if (response.ok) {
    return parsed.ok
      ? { ok: true, status: response.status, data: parsed.value as T }
      : { ok: false, error: { kind: 'server', status: response.status } }
  }

  if (response.status === 401 && auth === 'session') {
    notifyUnauthorized()
  }
  return {
    ok: false,
    error: mapResponseError({
      status: response.status,
      body: parsed.ok && isJsonContent(response) ? parsed.value : undefined,
      retryAfter: response.headers.get('Retry-After'),
      credentialCheck: auth === 'credentials',
    }),
  }
}
