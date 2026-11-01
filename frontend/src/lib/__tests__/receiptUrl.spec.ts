import { afterEach, describe, expect, it, vi } from 'vitest'

import { isSafeUploadUrl, isSafeViewUrl } from '../receiptUrl'

const SCRIPT_SCHEME = 'java' + 'script:'
const SIGNED = 'https://b.s3.eu-west-1.amazonaws.com/receipts/u/e/r?X-Amz-Signature=abc'
const APP_ORIGIN = 'https://app.example'

afterEach(() => {
  vi.unstubAllEnvs()
  vi.unstubAllGlobals()
})

// Rejected by both checks.
const HOSTILE: readonly string[] = [
  `${SCRIPT_SCHEME}alert(1)`,
  'data:text/html,<b>x</b>',
  'blob:https://x.example/uuid',
  'http://evil.example',
  'http://evil.example/',
  'http://localhost:9001/',
  'http://localhost:9000.evil.example/',
  'http://localhost:9000@evil.example',
  'https://x.example@evil.example/',
  'https://user:pw@x.example/',
  'https://x.example/#frag',
  '/relative/path',
  'x.example/path',
  '',
  ' https://x.example/',
  'https://x.example/ ',
  '\nhttps://x.example/',
  'https:\\\\x.example',
  'https:\\\\x.example\\path',
  String(null),
  String(undefined),
  String({}),
  // The parser would repair these into a valid address; they are refused as not plainly valid.
  'https:x.example',
  'https:/x.example',
  'https:///x.example',
  'https://x.example/\u0085',
  'https://x\u0085.example/',
  'https://x​.example/',
  'https://x.example/​',
  'https://x.example/ ',
  'https://bücher.example/',
  'https://x.example/café',
]

// Accepted by both checks.
const ACCEPTED_BOTH: readonly string[] = [
  'https://bucket.s3.eu-west-1.amazonaws.com/',
  'https://bucket.s3.eu-west-1.amazonaws.com',
  'http://localhost:9000/',
  'http://localhost:9000/expense-bucket',
  'https://xn--bcher-kva.example/',
  'HTTPS://x.example/',
  'http://LOCALHOST:9000',
]

describe('isSafeUploadUrl', () => {
  it.each([
    ['a plain https address', ACCEPTED_BOTH[0]],
    ['an https address without a path', ACCEPTED_BOTH[1]],
    ['the dev origin', ACCEPTED_BOTH[2]],
    ['the dev origin with a path', ACCEPTED_BOTH[3]],
    ['a punycode host (plain ASCII)', ACCEPTED_BOTH[4]],
    ['an upper-case scheme (the parser lower-cases it, harmless)', ACCEPTED_BOTH[5]],
    ['an upper-case dev host (the parser lower-cases it, harmless)', ACCEPTED_BOTH[6]],
  ])('accepts %s', (_name, value) => {
    expect(isSafeUploadUrl(value)).toBe(true)
  })

  it.each(HOSTILE)('rejects %j', (value) => {
    expect(isSafeUploadUrl(value)).toBe(false)
  })

  it('rejects a query', () => {
    expect(isSafeUploadUrl('https://x.example/?a=1')).toBe(false)
    expect(isSafeUploadUrl('https://x.example/?')).toBe(false)
  })

  it('rejects a fragment', () => {
    expect(isSafeUploadUrl('https://x.example/#a')).toBe(false)
  })
})

describe('isSafeViewUrl', () => {
  it.each([SIGNED, 'https://x.example/a', 'http://localhost:9000/b/k?X-Amz-Signature=abc'])(
    'accepts %s',
    (value) => {
      expect(isSafeViewUrl(value)).toBe(true)
    },
  )

  it.each(ACCEPTED_BOTH)('accepts %s like the upload check', (value) => {
    expect(isSafeViewUrl(value)).toBe(true)
  })

  it('allows a query, unlike the upload check', () => {
    expect(isSafeViewUrl(SIGNED)).toBe(true)
    expect(isSafeUploadUrl(SIGNED)).toBe(false)
  })

  it.each(HOSTILE)('rejects %j', (value) => {
    expect(isSafeViewUrl(value)).toBe(false)
  })

  it('rejects a fragment even with a query', () => {
    expect(isSafeViewUrl('https://x.example/a?b=1#c')).toBe(false)
  })
})

describe('missing or wrong-typed input', () => {
  it('gives false for null and undefined, so a caller can pass receipt.viewUrl directly', () => {
    expect(isSafeViewUrl(null)).toBe(false)
    expect(isSafeViewUrl(undefined)).toBe(false)
    expect(isSafeUploadUrl(null)).toBe(false)
    expect(isSafeUploadUrl(undefined)).toBe(false)
  })

  it('does not throw on a non-string passed by mistake', () => {
    expect(isSafeViewUrl(42 as unknown as string)).toBe(false)
    expect(isSafeUploadUrl({} as unknown as string)).toBe(false)
    expect(isSafeUploadUrl(42 as unknown as string)).toBe(false)
  })
})

// A same-origin request carries the session cookie even with withCredentials=false, so the app's own origin is refused.
describe('the app origin', () => {
  it('is refused by both checks (an https app)', () => {
    vi.stubGlobal('location', { origin: APP_ORIGIN })

    expect(isSafeUploadUrl(`${APP_ORIGIN}/api/v1/x`)).toBe(false)
    expect(isSafeViewUrl(`${APP_ORIGIN}/api/v1/x`)).toBe(false)
    expect(isSafeViewUrl(`${APP_ORIGIN}/api/v1/x?a=1`)).toBe(false)
    expect(isSafeUploadUrl(`${APP_ORIGIN}`)).toBe(false)
  })

  it('is refused whatever its spelling', () => {
    vi.stubGlobal('location', { origin: APP_ORIGIN })

    expect(isSafeUploadUrl('HTTPS://APP.EXAMPLE/x')).toBe(false)
    expect(isSafeUploadUrl('https://app.example:443/x')).toBe(false)
  })

  it('still accepts a different https host and a sibling subdomain', () => {
    vi.stubGlobal('location', { origin: APP_ORIGIN })

    expect(isSafeUploadUrl('https://other.example/x')).toBe(true)
    expect(isSafeUploadUrl('https://files.app.example/x')).toBe(true)
    expect(isSafeViewUrl('https://other.example/x?a=1')).toBe(true)
  })

  it('accepts the dev origin while the app runs on another port', () => {
    vi.stubGlobal('location', { origin: 'http://localhost:5173' })

    expect(isSafeUploadUrl('http://localhost:9000/bucket')).toBe(true)
    expect(isSafeViewUrl('http://localhost:9000/bucket/k?a=1')).toBe(true)
  })

  it('refuses the dev origin when the app itself is served from it', () => {
    vi.stubGlobal('location', { origin: 'http://localhost:9000' })

    expect(isSafeUploadUrl('http://localhost:9000/bucket')).toBe(false)
    expect(isSafeViewUrl('http://localhost:9000/bucket')).toBe(false)
  })
})

describe('the dev origin in a production build', () => {
  it('is accepted in development', () => {
    vi.stubEnv('DEV', true)

    expect(isSafeUploadUrl('http://localhost:9000/')).toBe(true)
    expect(isSafeViewUrl('http://localhost:9000/k?a=1')).toBe(true)
  })

  it('is refused when DEV is false, while https is still accepted', () => {
    vi.stubEnv('DEV', false)

    expect(isSafeUploadUrl('http://localhost:9000/')).toBe(false)
    expect(isSafeViewUrl('http://localhost:9000/k?a=1')).toBe(false)
    expect(isSafeUploadUrl('https://bucket.example/')).toBe(true)
    expect(isSafeViewUrl(SIGNED)).toBe(true)
  })
})
