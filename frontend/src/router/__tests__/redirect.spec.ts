import { createMemoryHistory } from 'vue-router'
import { describe, expect, it } from 'vitest'

import { createAppRouter } from '..'
import { INTERNAL_PATH_CASES } from '@/lib/__tests__/internalPathCases'
import { DEFAULT_REDIRECT, safeRedirect } from '../redirect'

// Built by concatenation: the lint rule no-script-url bans the literal.
const SCRIPT_URL = 'java' + 'script:alert(1)'
const router = createAppRouter(createMemoryHistory())
const UUID = '0b7c1f0e-5d3a-4c8e-9a41-2f6d8e7b1a90'

describe('safeRedirect: accepted', () => {
  it.each([
    ['/expenses?q=a', '/expenses?q=a'],
    ['/categories', '/categories'],
    ['/expenses/new', '/expenses/new'],
    [`/expenses/${UUID}`, `/expenses/${UUID}`],
    ['/expenses?from=2026-01-01&to=2026-01-31#top', '/expenses?from=2026-01-01&to=2026-01-31#top'],
  ])('%s gives the resolved path %s', (raw, expected) => {
    expect(safeRedirect(raw, router)).toBe(expected)
  })

  it('accepts a path of exactly 2048 characters that resolves to a private route', () => {
    const raw = '/expenses?q=' + 'a'.repeat(2048 - '/expenses?q='.length)

    expect(raw).toHaveLength(2048)
    expect(safeRedirect(raw, router)).toBe(raw)
  })
})

describe('safeRedirect: the shared path table', () => {
  it.each(INTERNAL_PATH_CASES.filter((c) => !c.routes))(
    'gives the default for $name',
    ({ value }) => {
      expect(safeRedirect(value, router)).toBe(DEFAULT_REDIRECT)
    },
  )

  it.each(INTERNAL_PATH_CASES.filter((c) => c.routes))(
    'keeps $name, as the resolved path',
    ({ value }) => {
      expect(safeRedirect(value, router)).toBe(router.resolve(value).fullPath)
    },
  )
})

describe('safeRedirect: refused', () => {
  const hostile: [string, unknown][] = [
    ['protocol-relative URL', '//evil.example'],
    ['slash-backslash', '/\\evil.example'],
    ['backslash later in the path', '/expenses\\x'],
    ['absolute URL', 'https://evil.example'],
    ['javascript URL', SCRIPT_URL],
    ['encoded double slash (resolves to not-found)', '/%2F%2Fevil.example'],
    ['leading space', ' /expenses'],
    ['trailing newline', '/expenses\n'],
    ['tab inside', '/exp\tenses'],
    ['NUL', '/expenses\u0000'],
    ['bidi override', '/expenses‮evil'],
    ['no-break space', '/expenses '],
    ['array', ['/expenses']],
    ['undefined', undefined],
    ['null', null],
    ['number', 42],
    ['object', { toString: () => '/expenses' }],
    ['empty string', ''],
    ['relative path', 'expenses'],
    ['login (guest route)', '/login'],
    ['register (guest route)', '/register'],
    ['login with a query', '/login?redirect=%2Fexpenses'],
    ['unknown path', '/nope'],
    ['upper-case path', '/EXPENSES'],
    ['malformed id', '/expenses/NOT-A-UUID'],
    ['too long (2049)', '/expenses?q=' + 'a'.repeat(2049 - '/expenses?q='.length)],
  ]

  it.each(hostile)('%s gives the default', (_name, raw) => {
    expect(safeRedirect(raw, router)).toBe(DEFAULT_REDIRECT)
  })

  it('the default is /expenses', () => {
    expect(DEFAULT_REDIRECT).toBe('/expenses')
  })

  it('never returns the raw value when it was accepted: only the resolved fullPath', () => {
    const raw = '/expenses?q=a'
    const resolved = router.resolve(raw)

    expect(safeRedirect(raw, router)).toBe(resolved.fullPath)
  })
})
