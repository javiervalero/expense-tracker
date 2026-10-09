import { describe, expect, it, vi } from 'vitest'

import {
  applyForwardHeaders,
  buildForwardHeaders,
  isAllowedOrigin,
  rejectForeignOrigin,
} from './proxyHeaders'

const ORIGIN_VERIFY = 'local-origin-verify-value'

describe('buildForwardHeaders', () => {
  it('sets the viewer address as host:port for an IPv4 client', () => {
    const headers = buildForwardHeaders('192.0.2.10', 51234, ORIGIN_VERIFY)

    expect(headers['CloudFront-Viewer-Address']).toBe('192.0.2.10:51234')
  })

  it('turns an IPv4-mapped IPv6 address into plain IPv4', () => {
    const headers = buildForwardHeaders('::ffff:192.0.2.10', 51234, ORIGIN_VERIFY)

    expect(headers['CloudFront-Viewer-Address']).toBe('192.0.2.10:51234')
  })

  it('accepts the IPv4-mapped prefix in upper case', () => {
    const headers = buildForwardHeaders('::FFFF:192.0.2.10', 80, ORIGIN_VERIFY)

    expect(headers['CloudFront-Viewer-Address']).toBe('192.0.2.10:80')
  })

  it('writes an IPv6 client as addr:port without brackets', () => {
    const headers = buildForwardHeaders('2001:db8::1', 443, ORIGIN_VERIFY)

    expect(headers['CloudFront-Viewer-Address']).toBe('2001:db8::1:443')
  })

  it('does not treat a non-mapped address that merely contains ffff as IPv4-mapped', () => {
    const headers = buildForwardHeaders('2001:ffff:192.0.2.10', 9, ORIGIN_VERIFY)

    expect(headers['CloudFront-Viewer-Address']).toBe('2001:ffff:192.0.2.10:9')
  })

  it('sets the origin-verify header to the given value', () => {
    const headers = buildForwardHeaders('192.0.2.10', 1, ORIGIN_VERIFY)

    expect(headers['X-Origin-Verify']).toBe(ORIGIN_VERIFY)
  })

  it('leaves the viewer address out when the socket has no address, never guessing one', () => {
    const headers = buildForwardHeaders(undefined, undefined, ORIGIN_VERIFY)

    expect(headers).toEqual({ 'X-Origin-Verify': ORIGIN_VERIFY })
  })

  it('leaves the viewer address out when only the port is missing', () => {
    const headers = buildForwardHeaders('192.0.2.10', undefined, ORIGIN_VERIFY)

    expect(headers).not.toHaveProperty('CloudFront-Viewer-Address')
  })
})

describe('applyForwardHeaders', () => {
  function fakeProxyRequest() {
    const headers = new Map<string, string>([
      ['cloudfront-viewer-address', '6.6.6.6:666'],
      ['x-origin-verify', 'forged-by-the-browser'],
      ['accept', 'application/json'],
    ])
    return {
      headers,
      removeHeader: vi.fn((name: string): void => {
        headers.delete(name.toLowerCase())
      }),
      setHeader: vi.fn((name: string, value: string): void => {
        headers.set(name.toLowerCase(), value)
      }),
    }
  }

  it('overwrites a client-supplied viewer address with the socket-derived one', () => {
    const proxyReq = fakeProxyRequest()

    applyForwardHeaders(proxyReq, { remoteAddress: '::ffff:192.0.2.10', remotePort: 5000 }, 'v')

    expect(proxyReq.headers.get('cloudfront-viewer-address')).toBe('192.0.2.10:5000')
  })

  it('overwrites a client-supplied origin-verify value', () => {
    const proxyReq = fakeProxyRequest()

    applyForwardHeaders(proxyReq, { remoteAddress: '192.0.2.10', remotePort: 5000 }, ORIGIN_VERIFY)

    expect(proxyReq.headers.get('x-origin-verify')).toBe(ORIGIN_VERIFY)
  })

  it('removes a client-supplied viewer address even when the socket address is unknown', () => {
    const proxyReq = fakeProxyRequest()

    applyForwardHeaders(proxyReq, { remoteAddress: undefined, remotePort: undefined }, 'v')

    expect(proxyReq.headers.has('cloudfront-viewer-address')).toBe(false)
  })

  it('leaves unrelated headers alone', () => {
    const proxyReq = fakeProxyRequest()

    applyForwardHeaders(proxyReq, { remoteAddress: '192.0.2.10', remotePort: 5000 }, 'v')

    expect(proxyReq.headers.get('accept')).toBe('application/json')
  })
})

describe('isAllowedOrigin', () => {
  it.each(['http://localhost:5173', 'http://127.0.0.1:5173'])('allows %s', (origin) => {
    expect(isAllowedOrigin(origin)).toBe(true)
  })

  it('allows a request without an Origin header (curl, health checks, same-origin GET)', () => {
    expect(isAllowedOrigin(undefined)).toBe(true)
  })

  it.each([
    'http://evil.example',
    'https://localhost:5173',
    'http://localhost:5174',
    'http://localhost',
    'http://localhost:5173.evil.example',
    'http://evil.example/http://localhost:5173',
    'null',
    '',
    'HTTP://LOCALHOST:5173',
  ])('rejects the Origin %j', (origin) => {
    expect(isAllowedOrigin(origin)).toBe(false)
  })
})

describe('rejectForeignOrigin', () => {
  function fakeResponse() {
    return { statusCode: 200, end: vi.fn() }
  }

  it('answers 403 and ends the response for a foreign Origin, returning true', () => {
    const res = fakeResponse()

    const rejected = rejectForeignOrigin({ headers: { origin: 'http://evil.example' } }, res)

    expect(rejected).toBe(true)
    expect(res.statusCode).toBe(403)
    expect(res.end).toHaveBeenCalledTimes(1)
  })

  it('leaves the response alone for the local dev origin', () => {
    const res = fakeResponse()

    const rejected = rejectForeignOrigin({ headers: { origin: 'http://localhost:5173' } }, res)

    expect(rejected).toBe(false)
    expect(res.statusCode).toBe(200)
    expect(res.end).not.toHaveBeenCalled()
  })

  it('leaves the response alone when there is no Origin header', () => {
    const res = fakeResponse()

    expect(rejectForeignOrigin({ headers: {} }, res)).toBe(false)
    expect(res.end).not.toHaveBeenCalled()
  })
})
