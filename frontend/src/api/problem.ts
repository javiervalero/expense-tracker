import { parseRetryAfter } from './retryAfter'

/**
 * Why a call did not give data. Only `conflict` (409) and `validation` (422) carry text from the server, because the
 * contract promises those texts are written for people and never repeat a value. Every other kind has no message here:
 * the views choose fixed text for it (plan phase-4 C4).
 */
export type ApiError =
  | { kind: 'network' }
  | { kind: 'timeout' }
  | { kind: 'aborted' }
  /** The call was refused before sending: a path that is not a plain API path (a bug in the caller, not the server). */
  | { kind: 'invalid-request' }
  | { kind: 'bad-request'; status: number }
  | { kind: 'invalid-credentials'; status: number }
  | { kind: 'unauthorized'; status: number }
  | { kind: 'forbidden'; status: number }
  | { kind: 'not-found'; status: number }
  | { kind: 'conflict'; status: number; detail: string | null }
  | { kind: 'client-bug'; status: number }
  | {
      kind: 'validation'
      status: number
      /** Field name to message. The first message wins when one field has several. */
      fieldErrors: Readonly<Record<string, string>>
      /** Messages about the whole form, in the order the server gave them. */
      formErrors: string[]
    }
  | { kind: 'rate-limited'; status: number; retryAfterSeconds: number | null }
  | { kind: 'unavailable'; status: number; retryAfterSeconds: number | null }
  | { kind: 'server'; status: number }

export interface ResponseErrorInput {
  status: number
  /** The parsed JSON body, or undefined when there was none or it was not JSON. */
  body: unknown
  /** The raw `Retry-After` header value, or null. */
  retryAfter: string | null
  /** True for the sign-in call: a 401 there means wrong credentials, not an expired session. */
  credentialCheck: boolean
}

/** At most this many `errors[]` entries are read (form and field together, in server order). */
export const MAX_VALIDATION_ERRORS = 20
/** Server text (409 detail, 422 `errors[].detail`) is cut to this many code points, then `…` is added. */
export const MAX_DETAIL_CODE_POINTS = 500

type ProblemBody = Record<string, unknown> & { title: string }

function isRecord(value: unknown): value is Record<string, unknown> {
  return typeof value === 'object' && value !== null && !Array.isArray(value)
}

/** A problem document needs at least a string `title` (the API always sends one; RFC 9457 makes every member optional). */
function asProblem(body: unknown): ProblemBody | null {
  return isRecord(body) && typeof body.title === 'string' ? { ...body, title: body.title } : null
}

type PointerTarget = { field: string } | { form: true }

/** RFC 6901: split on "/", then unescape "~1" before "~0". Only a pointer with exactly one non-empty segment names a field. */
function locate(pointer: string): PointerTarget {
  if (!pointer.startsWith('/')) {
    return { form: true }
  }
  const segments = pointer.slice(1).split('/')
  const [segment] = segments
  if (segments.length !== 1 || segment === undefined || segment === '') {
    return { form: true }
  }
  return { field: segment.replaceAll('~1', '/').replaceAll('~0', '~') }
}

function capText(text: string): string {
  // The UTF-16 length is at least the code-point count, so a string this short already fits.
  if (text.length <= MAX_DETAIL_CODE_POINTS) {
    return text
  }
  let kept = ''
  let count = 0
  for (const point of text) {
    if (count === MAX_DETAIL_CODE_POINTS) {
      return `${kept}…`
    }
    kept += point
    count += 1
  }
  return text
}

function readValidationEntries(value: unknown): { pointer: string; detail: string }[] {
  if (!Array.isArray(value)) {
    return []
  }
  const entries: { pointer: string; detail: string }[] = []
  for (const item of (value as unknown[]).slice(0, MAX_VALIDATION_ERRORS)) {
    if (isRecord(item) && typeof item.pointer === 'string' && typeof item.detail === 'string') {
      entries.push({ pointer: item.pointer, detail: capText(item.detail) })
    }
  }
  return entries
}

function mapValidation(status: number, problem: ProblemBody | null): ApiError {
  const entries = readValidationEntries(problem?.errors)
  if (entries.length === 0) {
    return { kind: 'server', status }
  }
  // A null prototype, so a server-chosen field name (`toString`, `__proto__`) is just a key and shadows nothing.
  const fieldErrors: Record<string, string> = Object.create(null) as Record<string, string>
  const formErrors: string[] = []
  for (const { pointer, detail } of entries) {
    const target = locate(pointer)
    if (!('field' in target)) {
      formErrors.push(detail)
    } else if (!(target.field in fieldErrors)) {
      fieldErrors[target.field] = detail
    }
  }
  return { kind: 'validation', status, fieldErrors, formErrors }
}

/** Turns an error response into an ApiError. Never throws, whatever the body holds. */
export function mapResponseError(input: ResponseErrorInput): ApiError {
  const { status, body, retryAfter, credentialCheck } = input
  const problem = asProblem(body)
  switch (status) {
    case 400:
      return { kind: 'bad-request', status }
    case 401:
      return { kind: credentialCheck ? 'invalid-credentials' : 'unauthorized', status }
    case 403:
      return { kind: 'forbidden', status }
    case 404:
      return { kind: 'not-found', status }
    case 409:
      return {
        kind: 'conflict',
        status,
        detail: typeof problem?.detail === 'string' ? capText(problem.detail) : null,
      }
    case 413:
    case 415:
      return { kind: 'client-bug', status }
    case 422:
      return mapValidation(status, problem)
    case 429:
      return { kind: 'rate-limited', status, retryAfterSeconds: parseRetryAfter(retryAfter) }
    case 503:
      return { kind: 'unavailable', status, retryAfterSeconds: parseRetryAfter(retryAfter) }
    default:
      return { kind: 'server', status }
  }
}
