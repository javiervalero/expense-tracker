import { describe, expect, it } from 'vitest'

import {
  DEFAULT_API_PROXY_TARGET,
  DEFAULT_ORIGIN_VERIFY_LOCAL,
  assertNoOriginInPublicEnv,
  resolveDevEnv,
} from './devEnv'

describe('resolveDevEnv', () => {
  it('uses the values that are set', () => {
    const resolved = resolveDevEnv({
      API_PROXY_TARGET: 'http://api:8080',
      ORIGIN_VERIFY_LOCAL: 'some-local-value',
    })

    expect(resolved.apiTarget).toBe('http://api:8080')
    expect(resolved.originVerify).toBe('some-local-value')
  })

  it('falls back to the documented local defaults when nothing is set', () => {
    const resolved = resolveDevEnv({})

    expect(resolved.apiTarget).toBe(DEFAULT_API_PROXY_TARGET)
    expect(resolved.originVerify).toBe(DEFAULT_ORIGIN_VERIFY_LOCAL)
  })

  it('falls back to the default API target when it is set but empty', () => {
    expect(resolveDevEnv({ API_PROXY_TARGET: '' }).apiTarget).toBe(DEFAULT_API_PROXY_TARGET)
  })

  it.each([
    ['empty', ''],
    ['blank', '   '],
  ])(
    'throws when the origin-verify value is set but %s, instead of sending nothing',
    (_label, value) => {
      expect(() => resolveDevEnv({ ORIGIN_VERIFY_LOCAL: value })).toThrow(/ORIGIN_VERIFY_LOCAL/)
    },
  )

  it('never puts the origin-verify value in an error message', () => {
    expect(() => resolveDevEnv({ ORIGIN_VERIFY_LOCAL: ' ' })).not.toThrow(/local-origin/)
  })

  it('returns the cache directory only when one is configured', () => {
    expect(resolveDevEnv({ FRONTEND_CACHE_DIR: '/tmp/vite-cache' }).cacheDir).toBe(
      '/tmp/vite-cache',
    )
    expect(resolveDevEnv({}).cacheDir).toBe('node_modules/.vite')
  })
})

describe('assertNoOriginInPublicEnv', () => {
  it('passes when no variable that reaches the browser mentions ORIGIN', () => {
    expect(() =>
      assertNoOriginInPublicEnv({ VITE_APP_TITLE: 'x', ORIGIN_VERIFY_LOCAL: 'v' }),
    ).not.toThrow()
  })

  it.each(['VITE_ORIGIN_VERIFY_LOCAL', 'VITE_X_ORIGIN', 'VITE_origin_verify'])(
    'throws for %s, which would be copied into the bundle',
    (name) => {
      expect(() => assertNoOriginInPublicEnv({ [name]: 'v' })).toThrow(name)
    },
  )

  it('does not throw for a name that merely ends in ORIGIN without the public prefix', () => {
    expect(() =>
      assertNoOriginInPublicEnv({ S3_CORS_ALLOWED_ORIGIN: 'http://localhost:5173' }),
    ).not.toThrow()
  })
})
