import { describe, expect, it } from 'vitest'

import { MAX_DETAIL_CODE_POINTS, MAX_VALIDATION_ERRORS, mapResponseError } from '../problem'

function map(status: number, body: unknown = undefined, retryAfter: string | null = null) {
  return mapResponseError({ status, body, retryAfter, credentialCheck: false })
}

const problem = (status: number, extra: object = {}) => ({ title: 'T', status, ...extra })

describe('mapResponseError: kinds by status', () => {
  it.each([
    [400, 'bad-request'],
    [401, 'unauthorized'],
    [403, 'forbidden'],
    [404, 'not-found'],
    [413, 'client-bug'],
    [415, 'client-bug'],
    [500, 'server'],
    [502, 'server'],
    [405, 'server'],
    [302, 'server'],
  ])('maps %d to %s', (status, kind) => {
    expect(map(status, problem(status))).toEqual({ kind, status })
  })

  it('maps 401 to invalid-credentials for a credential check', () => {
    const error = mapResponseError({
      status: 401,
      body: problem(401),
      retryAfter: null,
      credentialCheck: true,
    })
    expect(error).toEqual({ kind: 'invalid-credentials', status: 401 })
  })

  it('does not treat other statuses of a credential check specially', () => {
    const error = mapResponseError({
      status: 429,
      body: undefined,
      retryAfter: '30',
      credentialCheck: true,
    })
    expect(error).toEqual({ kind: 'rate-limited', status: 429, retryAfterSeconds: 30 })
  })

  it('never puts the server text on kinds other than conflict and validation', () => {
    const error = map(500, problem(500, { detail: 'secret internals' }))
    expect(JSON.stringify(error)).not.toContain('secret')
  })

  it.each([
    ['invalid JSON (undefined)', undefined],
    ['null', null],
    ['a string', 'oops'],
    ['an array', []],
    ['no title', { status: 500 }],
    ['a numeric title', { title: 5 }],
  ])('gives the status kind for an unusable body: %s', (_name, body) => {
    expect(map(404, body)).toEqual({ kind: 'not-found', status: 404 })
    expect(map(500, body)).toEqual({ kind: 'server', status: 500 })
  })
})

describe('mapResponseError: 429 and 503', () => {
  it('carries the parsed Retry-After', () => {
    expect(map(429, problem(429), '120')).toEqual({
      kind: 'rate-limited',
      status: 429,
      retryAfterSeconds: 120,
    })
    expect(map(503, problem(503), '7')).toEqual({
      kind: 'unavailable',
      status: 503,
      retryAfterSeconds: 7,
    })
  })

  it('carries null for a missing or invalid Retry-After', () => {
    expect(map(429, undefined, null)).toMatchObject({ retryAfterSeconds: null })
    expect(map(503, undefined, 'soon')).toMatchObject({ retryAfterSeconds: null })
  })
})

describe('mapResponseError: 409', () => {
  it('carries the server detail', () => {
    expect(map(409, problem(409, { detail: 'Already taken.' }))).toEqual({
      kind: 'conflict',
      status: 409,
      detail: 'Already taken.',
    })
  })

  it.each([
    ['no body', undefined],
    ['no title', { detail: 'x' }],
    ['no detail', problem(409)],
    ['a non-string detail', problem(409, { detail: 5 })],
  ])('has a null detail with %s', (_name, body) => {
    expect(map(409, body)).toEqual({ kind: 'conflict', status: 409, detail: null })
  })
})

describe('mapResponseError: 422', () => {
  const validation = (errors: unknown) => map(422, problem(422, { errors }))

  it('maps a one-segment pointer to a field', () => {
    expect(validation([{ pointer: '/description', detail: 'Too long.' }])).toEqual({
      kind: 'validation',
      status: 422,
      fieldErrors: { description: 'Too long.' },
      formErrors: [],
    })
  })

  it('maps the empty pointer to the form level', () => {
    expect(validation([{ pointer: '', detail: 'Nothing to change.' }])).toEqual({
      kind: 'validation',
      status: 422,
      fieldErrors: {},
      formErrors: ['Nothing to change.'],
    })
  })

  it('unescapes ~1 and ~0 in the right order', () => {
    const error = validation([
      { pointer: '/a~1b', detail: 'one' },
      { pointer: '/c~01d', detail: 'two' },
      { pointer: '/e~0f', detail: 'three' },
    ])
    expect(error).toMatchObject({
      fieldErrors: { 'a/b': 'one', 'c~1d': 'two', 'e~f': 'three' },
      formErrors: [],
    })
  })

  it.each([['/a/b'], ['/a/'], ['//'], ['/'], ['name'], ['/items/0/name']])(
    'maps the pointer %j to the form level',
    (pointer) => {
      expect(validation([{ pointer, detail: 'msg' }])).toMatchObject({
        fieldErrors: {},
        formErrors: ['msg'],
      })
    },
  )

  it('keeps the first message when several errors name one field', () => {
    const error = validation([
      { pointer: '/name', detail: 'first' },
      { pointer: '/name', detail: 'second' },
    ])
    expect(error).toMatchObject({ fieldErrors: { name: 'first' }, formErrors: [] })
  })

  it('keeps every form-level message in order', () => {
    const error = validation([
      { pointer: '', detail: 'a' },
      { pointer: '/x/y', detail: 'b' },
    ])
    expect(error).toMatchObject({ formErrors: ['a', 'b'] })
  })

  it('gives fieldErrors a null prototype', () => {
    const error = validation([
      { pointer: '/name', detail: 'x' },
      { pointer: '/hasOwnProperty', detail: 'own' },
    ])
    const fieldErrors = error.kind === 'validation' ? error.fieldErrors : {}
    expect('toString' in fieldErrors).toBe(false)
    expect((fieldErrors as Record<string, unknown>).constructor).toBeUndefined()
    expect(Object.hasOwn(fieldErrors, 'hasOwnProperty')).toBe(true)
    expect(fieldErrors['hasOwnProperty']).toBe('own')
  })

  it('keeps a field named __proto__ as an own key', () => {
    const error = validation([{ pointer: '/__proto__', detail: 'odd' }])
    const fieldErrors = error.kind === 'validation' ? error.fieldErrors : {}
    expect(Object.keys(fieldErrors)).toEqual(['__proto__'])
  })

  it('skips malformed entries and keeps the good ones', () => {
    const error = validation([
      null,
      'x',
      { pointer: 5, detail: 'bad pointer' },
      { pointer: '/a' },
      { pointer: '/ok', detail: 'fine' },
    ])
    expect(error).toMatchObject({ fieldErrors: { ok: 'fine' }, formErrors: [] })
  })

  it.each([
    ['missing', undefined],
    ['not an array', 'nope'],
    ['an object', {}],
    ['empty', []],
    ['all malformed', [{}, 1]],
  ])('gives server when errors are %s', (_name, errors) => {
    expect(validation(errors)).toEqual({ kind: 'server', status: 422 })
  })

  it('gives server for a 422 with no usable problem body', () => {
    expect(map(422, undefined)).toEqual({ kind: 'server', status: 422 })
    expect(map(422, { errors: [{ pointer: '', detail: 'x' }] })).toEqual({
      kind: 'server',
      status: 422,
    })
  })
})

describe('mapResponseError: caps on server text', () => {
  const long = (length: number) => 'é'.repeat(length - 1) + '😀'

  it('keeps only the first 20 errors, form and field together, in server order', () => {
    const errors = Array.from({ length: 25 }, (_v, index) =>
      index % 2 === 0
        ? { pointer: `/f${index}`, detail: `d${index}` }
        : { pointer: '', detail: `d${index}` },
    )

    const error = map(422, problem(422, { errors }))

    expect(MAX_VALIDATION_ERRORS).toBe(20)
    const fieldCount = error.kind === 'validation' ? Object.keys(error.fieldErrors).length : -1
    const formCount = error.kind === 'validation' ? error.formErrors.length : -1
    expect(fieldCount + formCount).toBe(20)
    expect(error).toMatchObject({ formErrors: expect.arrayContaining(['d1', 'd19']) })
    expect(JSON.stringify(error)).not.toContain('d20')
  })

  it('cuts a long 422 detail to 500 code points and adds an ellipsis', () => {
    const error = map(422, problem(422, { errors: [{ pointer: '/a', detail: long(600) }] }))

    const text = error.kind === 'validation' ? (error.fieldErrors['a'] ?? '') : ''
    expect(MAX_DETAIL_CODE_POINTS).toBe(500)
    expect([...text]).toHaveLength(501)
    expect(text.endsWith('…')).toBe(true)
    expect(text.startsWith('éé')).toBe(true)
  })

  it('cuts a long 409 detail the same way', () => {
    const error = map(409, problem(409, { detail: long(600) }))

    const text = error.kind === 'conflict' ? (error.detail ?? '') : ''
    expect([...text]).toHaveLength(501)
    expect(text.endsWith('…')).toBe(true)
  })

  it('leaves a detail of exactly 500 code points alone', () => {
    const detail = long(500)

    expect(map(409, problem(409, { detail }))).toMatchObject({ detail })
  })

  it('cuts a very long astral string without splitting a pair', () => {
    const error = map(409, problem(409, { detail: '𝒳'.repeat(100_000) }))

    const text = error.kind === 'conflict' ? (error.detail ?? '') : ''
    expect(text).toBe('𝒳'.repeat(500) + '…')
  })

  it('does not split a surrogate pair when cutting', () => {
    const error = map(409, problem(409, { detail: '😀'.repeat(600) }))

    const text = error.kind === 'conflict' ? (error.detail ?? '') : ''
    expect(text).toBe('😀'.repeat(500) + '…')
  })
})
