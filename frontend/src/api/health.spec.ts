import { afterEach, describe, expect, it, vi } from 'vitest'

import { fetchHealth } from './health'

function stubFetch(response: Response | Error | DOMException): ReturnType<typeof vi.fn> {
  const fetchMock = vi.fn(() =>
    response instanceof Response ? Promise.resolve(response) : Promise.reject(response),
  )
  vi.stubGlobal('fetch', fetchMock)
  return fetchMock
}

function jsonResponse(body: unknown, status = 200): Response {
  return new Response(JSON.stringify(body), {
    status,
    headers: { 'Content-Type': 'application/json' },
  })
}

afterEach(() => {
  vi.unstubAllGlobals()
})

describe('fetchHealth', () => {
  it('returns ok when the API answers 200 with {"status":"ok"}', async () => {
    stubFetch(jsonResponse({ status: 'ok' }))

    await expect(fetchHealth()).resolves.toEqual({ kind: 'ok' })
  })

  it('calls GET /api/v1/health asking for JSON', async () => {
    const fetchMock = stubFetch(jsonResponse({ status: 'ok' }))

    await fetchHealth()

    expect(fetchMock).toHaveBeenCalledTimes(1)
    const [url, init] = fetchMock.mock.calls[0] as unknown as [string, RequestInit]
    expect(url).toBe('/api/v1/health')
    expect(init.method).toBe('GET')
    expect(new Headers(init.headers).get('Accept')).toBe('application/json')
  })

  it('returns an http-status error with the status for a non-200 answer', async () => {
    stubFetch(jsonResponse({ type: 'about:blank', title: 'Bad Gateway', status: 502 }, 502))

    await expect(fetchHealth()).resolves.toEqual({
      kind: 'error',
      reason: 'http-status',
      status: 502,
    })
  })

  it('returns an invalid-json error when the body is not JSON', async () => {
    stubFetch(new Response('<html>oops</html>', { status: 200 }))

    await expect(fetchHealth()).resolves.toEqual({ kind: 'error', reason: 'invalid-json' })
  })

  it.each([
    ['a different status value', { status: 'degraded' }],
    ['a missing status', {}],
    ['a status of the wrong type', { status: true }],
    ['null', null],
    ['an array', ['ok']],
    ['a string', 'ok'],
  ])('returns an unexpected-shape error for %s', async (_label, body) => {
    stubFetch(jsonResponse(body))

    await expect(fetchHealth()).resolves.toEqual({ kind: 'error', reason: 'unexpected-shape' })
  })

  it('returns a network error when fetch rejects', async () => {
    stubFetch(new TypeError('Failed to fetch'))

    await expect(fetchHealth()).resolves.toEqual({ kind: 'error', reason: 'network' })
  })

  it('returns a timeout error when the request times out', async () => {
    stubFetch(new DOMException('The operation timed out.', 'TimeoutError'))

    await expect(fetchHealth()).resolves.toEqual({ kind: 'error', reason: 'timeout' })
  })

  it('returns a timeout error when the timeout fires while the body is being read', async () => {
    const response = new Response('{}', { status: 200 })
    vi.spyOn(response, 'json').mockRejectedValue(
      new DOMException('The operation timed out.', 'TimeoutError'),
    )
    stubFetch(response)

    await expect(fetchHealth()).resolves.toEqual({ kind: 'error', reason: 'timeout' })
  })

  it('returns an aborted error when the caller aborts', async () => {
    stubFetch(new DOMException('The operation was aborted.', 'AbortError'))
    const controller = new AbortController()
    controller.abort()

    await expect(fetchHealth(controller.signal)).resolves.toEqual({
      kind: 'error',
      reason: 'aborted',
    })
  })

  it('returns an aborted error when the caller aborts while the body is being read', async () => {
    const controller = new AbortController()
    const response = new Response('{}', { status: 200 })
    vi.spyOn(response, 'json').mockImplementation(() => {
      controller.abort()
      return Promise.reject(new DOMException('The operation was aborted.', 'AbortError'))
    })
    stubFetch(response)

    await expect(fetchHealth(controller.signal)).resolves.toEqual({
      kind: 'error',
      reason: 'aborted',
    })
  })

  it('passes a signal to fetch that the caller can abort', async () => {
    const fetchMock = stubFetch(jsonResponse({ status: 'ok' }))
    const controller = new AbortController()

    await fetchHealth(controller.signal)
    const [, init] = fetchMock.mock.calls[0] as unknown as [string, RequestInit]
    expect(init.signal?.aborted).toBe(false)
    controller.abort()

    expect(init.signal?.aborted).toBe(true)
  })

  it('passes a signal that fires on its own after the timeout, without a caller signal', async () => {
    const fetchMock = stubFetch(jsonResponse({ status: 'ok' }))

    await fetchHealth()

    const [, init] = fetchMock.mock.calls[0] as unknown as [string, RequestInit]
    expect(init.signal).toBeInstanceOf(AbortSignal)
    expect(init.signal?.aborted).toBe(false)
  })
})
