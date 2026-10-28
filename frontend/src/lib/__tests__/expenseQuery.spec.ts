import { describe, expect, it } from 'vitest'

import {
  buildApiParams,
  checkFilters,
  filtersKey,
  filtersToQuery,
  isUuidV4,
  NO_FILTERS,
  parseFilters,
} from '../expenseQuery'

const ID = '0b7c1f0e-5d3a-4c8e-9a41-2f6d8e7b1a90'

describe('parseFilters', () => {
  it('reads from, category and q and round-trips through filtersToQuery', () => {
    const query = { from: '2026-10-01', category: 'none', q: 'lunch' }

    const filters = parseFilters(query)

    expect(filters).toEqual({ from: '2026-10-01', to: '', category: 'none', q: 'lunch' })
    expect(filtersToQuery(filters)).toEqual(query)
  })

  it('reads a category uuid and both dates', () => {
    const filters = parseFilters({ from: '2026-01-31', to: '2026-02-28', category: ID })

    expect(filters).toEqual({ from: '2026-01-31', to: '2026-02-28', category: ID, q: '' })
  })

  it.each([
    ['not a date', { from: 'yesterday' }],
    ['an impossible day', { to: '2026-02-30' }],
    ['a date before the contract range', { from: '1999-12-31' }],
    ['a date after the contract range', { to: '2100-01-01' }],
    ['a date with a time', { from: '2026-10-01T00:00:00Z' }],
    ['an upper-case uuid', { category: ID.toUpperCase() }],
    ['a version 1 uuid', { category: '0b7c1f0e-5d3a-1c8e-9a41-2f6d8e7b1a90' }],
    ['"None" in another case', { category: 'None' }],
    ['a category with a trailing newline', { category: `${ID}\n` }],
    ['an empty q', { q: '' }],
    ['a blank q', { q: '   ' }],
    ['a q over 100 code points', { q: 'a'.repeat(101) }],
    ['a repeated category', { category: ['none', 'none'] }],
    ['a repeated q', { q: ['a', 'b'] }],
    ['a parameter with no value', { from: null }],
  ])('drops %s', (_name, query) => {
    expect(parseFilters(query)).toEqual(NO_FILTERS)
  })

  it('ignores a parameter it does not know', () => {
    const filters = parseFilters({ foo: '1', cursor: 'abc', limit: '5', q: 'tea' })

    expect(filters).toEqual({ ...NO_FILTERS, q: 'tea' })
    expect(filtersToQuery(filters)).toEqual({ q: 'tea' })
  })

  it('trims q and counts it in code points', () => {
    expect(parseFilters({ q: '  tea  ' }).q).toBe('tea')
    expect(parseFilters({ q: '😀'.repeat(100) }).q).toBe('😀'.repeat(100))
    expect(parseFilters({ q: '😀'.repeat(101) }).q).toBe('')
  })

  it('keeps hostile text as plain text', () => {
    expect(parseFilters({ q: '<img src=x onerror=alert(1)>' }).q).toBe(
      '<img src=x onerror=alert(1)>',
    )
  })
})

describe('filtersToQuery and filtersKey', () => {
  it('leaves out empty values', () => {
    expect(filtersToQuery(NO_FILTERS)).toEqual({})
  })

  it('gives the same key for the same filters and a different one otherwise', () => {
    const a = parseFilters({ q: 'tea', from: '2026-10-01' })
    const b = parseFilters({ from: '2026-10-01', q: 'tea', foo: 'x' })

    expect(filtersKey(a)).toBe(filtersKey(b))
    expect(filtersKey(a)).not.toBe(filtersKey(NO_FILTERS))
  })
})

describe('checkFilters', () => {
  it('accepts no filters and a good set', () => {
    expect(checkFilters(NO_FILTERS)).toEqual({})
    expect(checkFilters({ from: '2026-10-01', to: '2026-10-01', category: ID, q: 'tea' })).toEqual(
      {},
    )
  })

  it('refuses from after to, on the to field', () => {
    const problems = checkFilters({ ...NO_FILTERS, from: '2026-10-02', to: '2026-10-01' })

    expect(Object.keys(problems)).toEqual(['to'])
    expect(problems.to).toMatch(/before/)
  })

  it('refuses a q over 100 code points and accepts exactly 100', () => {
    expect(checkFilters({ ...NO_FILTERS, q: 'a'.repeat(101) }).q).toMatch(/100/)
    expect(checkFilters({ ...NO_FILTERS, q: 'a'.repeat(100) })).toEqual({})
    expect(checkFilters({ ...NO_FILTERS, q: `  ${'a'.repeat(100)}  ` })).toEqual({})
  })

  it('refuses a bad date and a bad category', () => {
    const problems = checkFilters({ from: '2026-13-01', to: 'x', category: 'ABC', q: '' })

    expect(Object.keys(problems).sort()).toEqual(['category', 'from', 'to'])
  })
})

describe('buildApiParams', () => {
  it('always has limit=50 and nothing else for no filters', () => {
    expect(buildApiParams(NO_FILTERS).toString()).toBe('limit=50')
  })

  it('sends only the filters that are set, never an empty value', () => {
    const params = buildApiParams({ from: '2026-10-01', to: '', category: 'none', q: '' })

    expect(params.toString()).toBe('from=2026-10-01&category=none&limit=50')
    for (const [, value] of params) {
      expect(value).not.toBe('')
    }
  })

  it('adds the cursor untouched, and encodes it as one value', () => {
    const cursor = 'a+b/c=d&e=f %7e'

    const params = buildApiParams({ ...NO_FILTERS, q: 'tea' }, cursor)

    expect(params.get('cursor')).toBe(cursor)
    expect(params.getAll('cursor')).toHaveLength(1)
    expect([...params.keys()]).toEqual(['q', 'limit', 'cursor'])
  })

  it('leaves out a null or empty cursor', () => {
    expect(buildApiParams(NO_FILTERS, null).has('cursor')).toBe(false)
    expect(buildApiParams(NO_FILTERS, '').has('cursor')).toBe(false)
  })

  it('re-checks the filters it is given, so an unchecked value is never sent', () => {
    const params = buildApiParams({
      from: 'nope',
      to: '2026-10-01',
      category: 'ABC',
      q: `${'a'.repeat(101)}`,
    })

    expect(params.toString()).toBe('to=2026-10-01&limit=50')
  })

  it('trims q before sending it', () => {
    expect(buildApiParams({ ...NO_FILTERS, q: '  tea ' }).get('q')).toBe('tea')
  })
})

describe('isUuidV4', () => {
  it('accepts a lower-case v4 uuid only', () => {
    expect(isUuidV4(ID)).toBe(true)
    expect(isUuidV4(ID.toUpperCase())).toBe(false)
    expect(isUuidV4('none')).toBe(false)
    expect(isUuidV4(`${ID}x`)).toBe(false)
  })
})
