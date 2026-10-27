import { afterEach, beforeEach, describe, expect, expectTypeOf, it, vi } from 'vitest'

import { REQUEST_TIMEOUT_MS } from '../../config'
import { request, resourcePath, setUnauthorizedHandler } from '../http'
import type { ApiResult } from '../http'

type FetchCall = [string, RequestInit]

function stubFetch(
  respond: () => Response | Promise<Response>,
): ReturnType<typeof vi.fn<(url: string, init: RequestInit) => Promise<Response>>> {
  const fetchMock = vi.fn<(url: string, init: RequestInit) => Promise<Response>>(() =>
    Promise.resolve(respond()),
  )
  vi.stubGlobal('fetch', fetchMock)
  return fetchMock
}

function stubFetchRejecting(error: unknown): void {
  vi.stubGlobal(
    'fetch',
    vi.fn(() => Promise.reject(error)),
  )
}

function json(body: unknown, status = 200, contentType = 'application/json'): Response {
  return new Response(JSON.stringify(body), { status, headers: { 'Content-Type': contentType } })
}

function problemResponse(status: number, extra: object = {}, headers: object = {}): Response {
  return new Response(JSON.stringify({ title: 'T', status, ...extra }), {
    status,
    headers: { 'Content-Type': 'application/problem+json', ...headers },
  })
}

function lastCall(fetchMock: ReturnType<typeof stubFetch>): FetchCall {
  return fetchMock.mock.calls.at(-1) as unknown as FetchCall
}

function headerObject(init: RequestInit): Record<string, string> {
  return Object.fromEntries(new Headers(init.headers).entries())
}

beforeEach(() => {
  setUnauthorizedHandler(null)
})

afterEach(() => {
  vi.unstubAllGlobals()
  vi.useRealTimers()
})

describe('request: shape', () => {
  it.each(['GET', 'POST', 'PATCH', 'PUT', 'DELETE'] as const)(
    'sends %s with the CSRF header, JSON accept, same-origin credentials and the /api/v1 prefix',
    async (method) => {
      const fetchMock = stubFetch(() => json({}))

      await request(method, '/categories', { auth: 'public' })

      const [url, init] = lastCall(fetchMock)
      expect(url).toBe('/api/v1/categories')
      expect(init).toEqual({
        method,
        headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        credentials: 'same-origin',
        redirect: 'error',
        signal: expect.any(AbortSignal),
      })
    },
  )

  it('serialises a JSON body and adds Content-Type, and nothing else', async () => {
    const fetchMock = stubFetch(() => json({ id: 'x' }, 201))

    await request('POST', '/expenses', {
      auth: 'public',
      body: { description: 'Tea', amountCents: 250 },
    })

    const [, init] = lastCall(fetchMock)
    expect(init.body).toBe('{"description":"Tea","amountCents":250}')
    expect(headerObject(init)).toEqual({
      accept: 'application/json',
      'content-type': 'application/json',
      'x-requested-with': 'XMLHttpRequest',
    })
  })

  it.each([
    ['null', null, 'null'],
    ['0', 0, '0'],
    ['false', false, 'false'],
  ])('sends a body of %s (it is not "no body")', async (_name, body, serialised) => {
    const fetchMock = stubFetch(() => json({}))

    await request('POST', '/x', { auth: 'public', body })

    expect(lastCall(fetchMock)[1].body).toBe(serialised)
    expect(headerObject(lastCall(fetchMock)[1])['content-type']).toBe('application/json')
  })

  it('passes a signal that is not aborted', async () => {
    const fetchMock = stubFetch(() => json({}))

    await request('GET', '/x', { auth: 'public' })

    expect(lastCall(fetchMock)[1].signal?.aborted).toBe(false)
  })
})

describe('request: results', () => {
  it('returns data undefined for 204 without reading a body', async () => {
    stubFetch(() => new Response(null, { status: 204 }))

    await expect(request('DELETE', '/expenses/1', { auth: 'public' })).resolves.toEqual({
      ok: true,
      status: 204,
      data: undefined,
    })
  })

  it.each([200, 201])('returns the parsed JSON for %d', async (status) => {
    stubFetch(() => json({ id: 'abc' }, status))

    await expect(request<{ id: string }>('GET', '/x', { auth: 'public' })).resolves.toEqual({
      ok: true,
      status,
      data: { id: 'abc' },
    })
  })

  it.each([
    ['text/html', () => new Response('<html></html>', { status: 200 })],
    ['invalid JSON', () => new Response('{nope', { status: 200 })],
    ['an empty body', () => new Response('', { status: 200 })],
  ])('gives server for a 200 with %s', async (_name, make) => {
    stubFetch(make)

    await expect(request('GET', '/x', { auth: 'public' })).resolves.toEqual({
      ok: false,
      error: { kind: 'server', status: 200 },
    })
  })

  it('ignores a JSON body that has no Content-Type header at all', async () => {
    const bytes = new TextEncoder().encode('{"title":"T","detail":"x"}')
    stubFetch(() => new Response(bytes, { status: 409 }))

    expect(await request('POST', '/x', { auth: 'public', body: {} })).toEqual({
      ok: false,
      error: { kind: 'conflict', status: 409, detail: null },
    })
  })

  it('maps an error status to its kind', async () => {
    stubFetch(() => problemResponse(404))

    await expect(request('GET', '/x', { auth: 'public' })).resolves.toEqual({
      ok: false,
      error: { kind: 'not-found', status: 404 },
    })
  })

  it('reads a 409 detail from application/problem+json', async () => {
    stubFetch(() => problemResponse(409, { detail: 'Name taken.' }))

    const result = await request('POST', '/categories', { auth: 'public', body: {} })

    expect(result).toEqual({
      ok: false,
      error: { kind: 'conflict', status: 409, detail: 'Name taken.' },
    })
  })

  it('reads a 422 field error', async () => {
    stubFetch(() => problemResponse(422, { errors: [{ pointer: '/name', detail: 'Required.' }] }))

    const result = await request('POST', '/categories', { auth: 'public', body: {} })

    expect(result).toEqual({
      ok: false,
      error: {
        kind: 'validation',
        status: 422,
        fieldErrors: { name: 'Required.' },
        formErrors: [],
      },
    })
  })

  it.each([
    [
      'text/html',
      () => new Response('<h1>Bad</h1>', { status: 502, headers: { 'Content-Type': 'text/html' } }),
    ],
    [
      'invalid JSON',
      () =>
        new Response('{', { status: 500, headers: { 'Content-Type': 'application/problem+json' } }),
    ],
    [
      'an empty Content-Type header',
      () => new Response('{"title":"T"}', { status: 500, headers: { 'Content-Type': '' } }),
    ],
    ['no title', () => json({ status: 500 }, 500, 'application/problem+json')],
  ])('gives server for an error body that is %s', async (_name, make) => {
    stubFetch(make)

    const result = await request('GET', '/x', { auth: 'public' })

    expect(result).toMatchObject({ ok: false, error: { kind: 'server' } })
  })

  it('ignores the detail of a text/html 409', async () => {
    stubFetch(
      () =>
        new Response('{"title":"T","detail":"x"}', {
          status: 409,
          headers: { 'Content-Type': 'text/html' },
        }),
    )

    expect(await request('POST', '/x', { auth: 'public', body: {} })).toEqual({
      ok: false,
      error: { kind: 'conflict', status: 409, detail: null },
    })
  })

  it('accepts application/json for an error body', async () => {
    stubFetch(() => json({ title: 'T', detail: 'D' }, 409, 'application/json; charset=utf-8'))

    expect(await request('POST', '/x', { auth: 'public', body: {} })).toMatchObject({
      error: { kind: 'conflict', detail: 'D' },
    })
  })

  it.each([
    [429, 'rate-limited'],
    [503, 'unavailable'],
  ])('carries Retry-After on %d', async (status, kind) => {
    stubFetch(() => problemResponse(status, {}, { 'Retry-After': '120' }))

    expect(await request('GET', '/x', { auth: 'public' })).toEqual({
      ok: false,
      error: { kind, status, retryAfterSeconds: 120 },
    })
  })

  it('carries a null Retry-After when the header is invalid', async () => {
    stubFetch(() => problemResponse(429, {}, { 'Retry-After': '0' }))

    expect(await request('GET', '/x', { auth: 'public' })).toMatchObject({
      error: { retryAfterSeconds: null },
    })
  })

  it('types data from the type argument', async () => {
    stubFetch(() => json({ id: 'a' }))

    const result = await request<{ id: string }>('GET', '/x', { auth: 'public' })

    expectTypeOf(result).toEqualTypeOf<ApiResult<{ id: string }>>()
    if (result.ok) {
      expectTypeOf(result.data).toEqualTypeOf<{ id: string }>()
    }
    expect(result.ok).toBe(true)
  })
})

describe('request: failures', () => {
  it('gives network for a fetch TypeError', async () => {
    stubFetchRejecting(new TypeError('Failed to fetch'))

    expect(await request('GET', '/x', { auth: 'public' })).toEqual({
      ok: false,
      error: { kind: 'network' },
    })
  })

  it('gives timeout for a TimeoutError', async () => {
    stubFetchRejecting(new DOMException('timed out', 'TimeoutError'))

    expect(await request('GET', '/x', { auth: 'public' })).toEqual({
      ok: false,
      error: { kind: 'timeout' },
    })
  })

  it('gives aborted when the caller aborts', async () => {
    const controller = new AbortController()
    // A plain TypeError, so only the caller's own signal can explain the result.
    stubFetchRejecting(new TypeError('Failed to fetch'))
    controller.abort()

    expect(await request('GET', '/x', { auth: 'public', signal: controller.signal })).toEqual({
      ok: false,
      error: { kind: 'aborted' },
    })
  })

  it('gives aborted for an AbortError even without a caller signal', async () => {
    stubFetchRejecting(new DOMException('aborted', 'AbortError'))

    expect(await request('GET', '/x', { auth: 'public' })).toEqual({
      ok: false,
      error: { kind: 'aborted' },
    })
  })

  it('forwards a later caller abort to the signal fetch received', async () => {
    const controller = new AbortController()
    let received: AbortSignal | undefined
    vi.stubGlobal(
      'fetch',
      vi.fn(
        (_url: string, init: RequestInit) =>
          new Promise<Response>((_resolve, reject) => {
            received = init.signal ?? undefined
            init.signal?.addEventListener('abort', () => reject(init.signal?.reason))
          }),
      ),
    )

    const pending = request('GET', '/x', { auth: 'public', signal: controller.signal })
    expect(received?.aborted).toBe(false)
    controller.abort()

    expect(received?.aborted).toBe(true)
    expect(await pending).toEqual({ ok: false, error: { kind: 'aborted' } })
  })

  it('starts the platform timeout with REQUEST_TIMEOUT_MS and gives timeout when it fires', async () => {
    const timeoutController = new AbortController()
    const timeoutSpy = vi.spyOn(AbortSignal, 'timeout').mockReturnValue(timeoutController.signal)
    vi.stubGlobal(
      'fetch',
      vi.fn(
        (_url: string, init: RequestInit) =>
          new Promise<Response>((_resolve, reject) => {
            init.signal?.addEventListener('abort', () => reject(init.signal?.reason))
          }),
      ),
    )

    const pending = request('GET', '/x', { auth: 'public' })
    timeoutController.abort(new DOMException('timed out', 'TimeoutError'))

    expect(timeoutSpy).toHaveBeenCalledWith(REQUEST_TIMEOUT_MS)
    expect(await pending).toEqual({ ok: false, error: { kind: 'timeout' } })
    timeoutSpy.mockRestore()
  })

  it('gives network, not a throw, when AbortSignal.any fails', async () => {
    const anySpy = vi.spyOn(AbortSignal, 'any').mockImplementation(() => {
      throw new TypeError('not supported')
    })
    const fetchMock = stubFetch(() => json({}))

    const result = await request('GET', '/x', {
      auth: 'public',
      signal: new AbortController().signal,
    })

    expect(result).toEqual({ ok: false, error: { kind: 'network' } })
    expect(fetchMock).not.toHaveBeenCalled()
    anySpy.mockRestore()
  })

  it.each([
    ['TimeoutError', 'timeout'],
    ['AbortError', 'aborted'],
    ['Other', 'network'],
  ])('maps a body read that fails with %s to %s', async (name, kind) => {
    stubFetch(
      () =>
        ({
          status: 200,
          ok: true,
          headers: new Headers(),
          text: () => Promise.reject(new DOMException('x', name)),
        }) as unknown as Response,
    )

    expect(await request('GET', '/x', { auth: 'public' })).toEqual({ ok: false, error: { kind } })
  })

  it.each([['boom'], [null], [undefined], [{}], [{ name: 'Other' }]])(
    'never throws, even when the failure is %j',
    async (failure) => {
      stubFetchRejecting(failure)

      expect(await request('GET', '/x', { auth: 'public' })).toEqual({
        ok: false,
        error: { kind: 'network' },
      })
    },
  )
})

describe('auth modes', () => {
  it.each([
    ['session', 'unauthorized', 1],
    ['probe', 'unauthorized', 0],
    ['public', 'unauthorized', 0],
    ['credentials', 'invalid-credentials', 0],
  ] as const)(
    'on a 401 with auth %s gives %s and runs the handler %d times',
    async (auth, kind, runs) => {
      const handler = vi.fn()
      setUnauthorizedHandler(handler)
      stubFetch(() => problemResponse(401))

      const result = await request('GET', '/x', { auth })

      expect(result).toEqual({ ok: false, error: { kind, status: 401 } })
      expect(handler).toHaveBeenCalledTimes(runs)
    },
  )

  it('refuses a call with no auth mode at the type level', () => {
    // @ts-expect-error auth is required: every call must say what a 401 means
    const options: Parameters<typeof request>[2] = {}
    // @ts-expect-error the old flags no longer exist
    const old: Parameters<typeof request>[2] = { secured: true }
    expect([options, old]).toBeDefined()
  })
})

describe('setUnauthorizedHandler', () => {
  it('is called once for three parallel 401s answered out of order', async () => {
    const handler = vi.fn()
    setUnauthorizedHandler(handler)
    const resolvers: ((response: Response) => void)[] = []
    vi.stubGlobal(
      'fetch',
      vi.fn(() => new Promise<Response>((resolve) => resolvers.push(resolve))),
    )

    const calls = ['/a', '/b', '/c'].map((path) => request('GET', path, { auth: 'session' }))
    expect(resolvers).toHaveLength(3)
    for (const resolve of [...resolvers].reverse()) {
      resolve(problemResponse(401))
      await Promise.resolve()
    }
    const results = await Promise.all(calls)

    expect(handler).toHaveBeenCalledTimes(1)
    expect(results.every((result) => !result.ok && result.error.kind === 'unauthorized')).toBe(true)
  })

  it('is not called for other statuses on a session call', async () => {
    const handler = vi.fn()
    setUnauthorizedHandler(handler)
    stubFetch(() => problemResponse(403))

    await request('GET', '/x', { auth: 'session' })

    expect(handler).not.toHaveBeenCalled()
  })

  it('fires again after setUnauthorizedHandler is called again', async () => {
    const first = vi.fn()
    const second = vi.fn()
    stubFetch(() => problemResponse(401))
    setUnauthorizedHandler(first)
    await request('GET', '/a', { auth: 'session' })

    setUnauthorizedHandler(second)
    await request('GET', '/a', { auth: 'session' })

    expect(first).toHaveBeenCalledTimes(1)
    expect(second).toHaveBeenCalledTimes(1)
  })

  it('still returns the result when no handler is set', async () => {
    stubFetch(() => problemResponse(401))

    expect(await request('GET', '/a', { auth: 'session' })).toMatchObject({
      error: { kind: 'unauthorized' },
    })
  })

  it('returns the result when the handler throws, and re-arms for the next 401', async () => {
    const handler = vi.fn(() => {
      throw new Error('boom')
    })
    setUnauthorizedHandler(handler)
    stubFetch(() => problemResponse(401))

    const first = await request('GET', '/a', { auth: 'session' })
    await request('GET', '/a', { auth: 'session' })

    expect(first).toMatchObject({ error: { kind: 'unauthorized' } })
    expect(handler).toHaveBeenCalledTimes(2)
  })
})

describe('request: path guard', () => {
  const uuid = '3f2b8c1e-5a4d-4e6f-9b7a-1c2d3e4f5a6b'

  it.each([
    ['dot-dot segment', '/../categories/x'],
    ['dot segment', '/./categories'],
    ['trailing dot-dot', '/expenses/..'],
    ['encoded dots', '/expenses/%2e%2e'],
    ['query', '/expenses/a?b'],
    ['fragment', '/expenses/a#b'],
    ['double slash', '//evil'],
    ['inner double slash', '/a//b'],
    ['trailing slash', '/a/'],
    ['backslash', '/a\\b'],
    ['at sign', '/a@b'],
    ['space', '/a b'],
    ['newline', '/a\nb'],
    ['NUL', '/a\u0000b'],
    ['unicode space', '/a b'],
    ['no leading slash', 'categories'],
    ['absolute URL', 'https://evil.example/x'],
    ['empty', ''],
    ['root only', '/'],
  ])('refuses %s without calling fetch', async (_name, path) => {
    const fetchMock = stubFetch(() => json({}))

    const result = await request('GET', path, { auth: 'public' })

    expect(result).toEqual({ ok: false, error: { kind: 'invalid-request' } })
    expect(fetchMock).not.toHaveBeenCalled()
  })

  it('lets a plain uuid path through', async () => {
    const fetchMock = stubFetch(() => json({}))

    await request('GET', `/expenses/${uuid}`, { auth: 'session' })

    expect(lastCall(fetchMock)[0]).toBe(`/api/v1/expenses/${uuid}`)
  })

  it('appends the query after the guard, encoding & = + and spaces', async () => {
    const fetchMock = stubFetch(() => json({}))
    const query = new URLSearchParams({ cursor: 'a&b=c+d', q: 'x y&z' })

    await request('GET', '/expenses', { auth: 'session', query })

    expect(lastCall(fetchMock)[0]).toBe('/api/v1/expenses?cursor=a%26b%3Dc%2Bd&q=x+y%26z')
    expect(new URL(lastCall(fetchMock)[0], 'http://x').searchParams.get('cursor')).toBe('a&b=c+d')
  })

  it('adds no "?" for an empty query', async () => {
    const fetchMock = stubFetch(() => json({}))

    await request('GET', '/expenses', { auth: 'session', query: new URLSearchParams() })

    expect(lastCall(fetchMock)[0]).toBe('/api/v1/expenses')
  })

  it('does not let the query rescue a bad path', async () => {
    const fetchMock = stubFetch(() => json({}))

    const result = await request('GET', '/a?x=1', { auth: 'public', query: new URLSearchParams() })

    expect(result).toMatchObject({ ok: false, error: { kind: 'invalid-request' } })
    expect(fetchMock).not.toHaveBeenCalled()
  })
})

describe('resourcePath', () => {
  const uuid = '3f2b8c1e-5a4d-4e6f-9b7a-1c2d3e4f5a6b'

  it.each([
    ['/expenses', uuid],
    ['/categories', 'abc'],
    ['/expenses', 'A-1'],
    ['/expenses', 'a'.repeat(64)],
  ] as const)('builds the whole path for %s and %s', (collection, id) => {
    expect(resourcePath(collection, id)).toBe(`${collection}/${id}`)
  })

  it.each([
    [''],
    ['a'.repeat(65)],
    ['../x'],
    ['..'],
    ['.'],
    ['a/b'],
    ['a%2e'],
    ['a?b'],
    ['a b'],
    ['a_b'],
    ['é'],
    ['a\nb'],
    ['null'.concat('/')],
  ])('gives null for the id %j, so nothing is ever interpolated', (id) => {
    expect(resourcePath('/expenses', id)).toBeNull()
  })

  it('only allows the two collections', () => {
    // @ts-expect-error '/auth' is not a resource collection
    const path = resourcePath('/auth', 'abc')
    expect(path).toBe('/auth/abc')
  })
})

describe('request: media type of an error body', () => {
  it.each([
    ['Application/Problem+JSON; charset=utf-8', 'D'],
    ['application/json ;charset=utf-8', 'D'],
    ['  APPLICATION/JSON', 'D'],
    ['text/plain; x=application/json', null],
    ['application/jsonp', null],
    ['application/json-patch+json', null],
    ['text/html', null],
  ])('with Content-Type %j gives detail %j', async (contentType, detail) => {
    stubFetch(() => json({ title: 'T', detail: 'D' }, 409, contentType))

    expect(await request('POST', '/x', { auth: 'public', body: {} })).toEqual({
      ok: false,
      error: { kind: 'conflict', status: 409, detail },
    })
  })
})
