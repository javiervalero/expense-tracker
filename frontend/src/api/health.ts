import { API_BASE } from '../config'
import { failureReason } from './abort'
import type { paths } from './schema.gen'

const REQUEST_TIMEOUT_MS = 5000

type HealthBody = paths['/health']['get']['responses'][200]['content']['application/json']

export type HealthResult =
  | { kind: 'ok' }
  | {
      kind: 'error'
      reason: 'network' | 'timeout' | 'aborted' | 'invalid-json' | 'unexpected-shape'
    }
  | { kind: 'error'; reason: 'http-status'; status: number }

function isHealthBody(value: unknown): value is HealthBody {
  return typeof value === 'object' && value !== null && 'status' in value && value.status === 'ok'
}

/**
 * Asks the API whether it is up. Never throws: every failure is a value the caller can show.
 * `signal` lets the caller cancel the request (for example when the component is unmounted); it is combined with the
 * request timeout, whichever comes first wins.
 */
export async function fetchHealth(signal?: AbortSignal): Promise<HealthResult> {
  const timeout = AbortSignal.timeout(REQUEST_TIMEOUT_MS)
  const combined = signal ? AbortSignal.any([signal, timeout]) : timeout
  let response: Response
  try {
    response = await fetch(`${API_BASE}/health`, {
      method: 'GET',
      headers: { Accept: 'application/json' },
      signal: combined,
    })
  } catch (error) {
    return { kind: 'error', reason: failureReason(error, signal) }
  }
  if (response.status !== 200) {
    return { kind: 'error', reason: 'http-status', status: response.status }
  }
  let body: unknown
  try {
    body = await response.json()
  } catch (error) {
    const reason = failureReason(error, signal)
    return { kind: 'error', reason: reason === 'network' ? 'invalid-json' : reason }
  }
  return isHealthBody(body) ? { kind: 'ok' } : { kind: 'error', reason: 'unexpected-shape' }
}
