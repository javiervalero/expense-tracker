import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import { UPLOAD_TIMEOUT_MS } from '../../config'
import { uploadReceipt } from '../upload'

// A fake XMLHttpRequest. It dispatches the events itself (plan R7: jsdom does not send anything on its own).
class FakeXhr {
  static instances: FakeXhr[] = []
  static openError: Error | null = null

  upload: { onprogress: ((event: ProgressEvent) => void) | null } = { onprogress: null }
  onload: (() => void) | null = null
  onerror: (() => void) | null = null
  ontimeout: (() => void) | null = null
  onabort: (() => void) | null = null
  status = 0
  timeout = 0
  withCredentials = false
  method: string | null = null
  url: string | null = null
  sent: FormData | null = null
  readonly open = vi.fn((method: string, url: string) => {
    if (FakeXhr.openError !== null) {
      throw FakeXhr.openError
    }
    this.method = method
    this.url = url
  })
  readonly setRequestHeader = vi.fn()
  readonly abort = vi.fn(() => {
    this.onabort?.()
  })
  readonly send = vi.fn((data: FormData) => {
    this.sent = data
  })

  constructor() {
    FakeXhr.instances.push(this)
  }

  progress(loaded: number, total: number, lengthComputable = true): void {
    this.upload.onprogress?.(new ProgressEvent('progress', { loaded, total, lengthComputable }))
  }

  finish(status: number): void {
    this.status = status
    this.onload?.()
  }
}

const SCRIPT_SCHEME = 'java' + 'script:'
const GOOD_URL = 'https://bucket.s3.eu-west-1.amazonaws.com/'
const GOOD_FIELDS = {
  key: 'receipts/u/e/r',
  'Content-Type': 'image/png',
  Policy: 'p',
  'X-Amz-Algorithm': 'AWS4-HMAC-SHA256',
  'X-Amz-Credential': 'c',
  'X-Amz-Date': 'd',
  'X-Amz-Security-Token': 't',
  'X-Amz-Signature': 's',
}
const file = new File([new Uint8Array([1, 2, 3])], 'r.png', { type: 'image/png' })

function xhr(): FakeXhr {
  const instance = FakeXhr.instances[0]
  if (instance === undefined) {
    throw new Error('no request was created')
  }
  return instance
}

// Starts an upload. The implementation must create and send the request synchronously (no await before send()).
function start(
  options?: Parameters<typeof uploadReceipt>[2],
  target: { url: string; fields: Record<string, string> } = { url: GOOD_URL, fields: GOOD_FIELDS },
) {
  return uploadReceipt(target, file, options)
}

beforeEach(() => {
  FakeXhr.instances = []
  FakeXhr.openError = null
  vi.stubGlobal('XMLHttpRequest', FakeXhr)
})

afterEach(() => {
  vi.unstubAllGlobals()
})

describe('uploadReceipt request', () => {
  it('posts to the target url with POST', async () => {
    const result = start()

    xhr().finish(204)
    await result

    expect(xhr().method).toBe('POST')
    expect(xhr().url).toBe(GOOD_URL)
  })

  it('sends the fields in the order given and the file last', async () => {
    const result = start()

    xhr().finish(204)
    await result

    const entries = [...(xhr().sent as FormData).entries()]
    expect(entries.map(([name]) => name)).toEqual([...Object.keys(GOOD_FIELDS), 'file'])
    expect(entries.slice(0, -1).map(([, value]) => value)).toEqual(Object.values(GOOD_FIELDS))
    const last = entries[entries.length - 1]?.[1]
    expect(last).toBeInstanceOf(File)
    expect((last as File).name).toBe('r.png')
    expect((last as File).size).toBe(3)
  })

  it('keeps a different field order as given', async () => {
    const result = start(undefined, {
      url: GOOD_URL,
      fields: { 'X-Amz-Signature': 's', key: 'k', Policy: 'p' },
    })

    xhr().finish(204)
    await result

    const names = [...(xhr().sent as FormData).keys()]
    expect(names).toEqual(['X-Amz-Signature', 'key', 'Policy', 'file'])
  })

  it('sends without credentials, with the upload timeout and no custom header', async () => {
    const result = start()

    xhr().finish(204)
    await result

    expect(xhr().withCredentials).toBe(false)
    expect(xhr().timeout).toBe(UPLOAD_TIMEOUT_MS)
    expect(xhr().setRequestHeader).not.toHaveBeenCalled()
  })

  it('accepts exactly the dev origin http://localhost:9000/', async () => {
    const result = start(undefined, { url: 'http://localhost:9000/', fields: { key: 'k' } })

    xhr().finish(204)

    await expect(result).resolves.toEqual({ ok: true })
  })
})

describe('uploadReceipt progress', () => {
  it('reports whole-number percents from lengthComputable events', async () => {
    const onProgress = vi.fn()
    const result = start({ onProgress })

    xhr().progress(0, 300)
    xhr().progress(100, 300)
    xhr().progress(200, 300)
    xhr().progress(300, 300)
    xhr().finish(204)
    await result

    expect(onProgress.mock.calls.map(([percent]) => percent)).toEqual([0, 33, 67, 100])
    for (const [percent] of onProgress.mock.calls) {
      expect(Number.isInteger(percent)).toBe(true)
    }
  })

  it('ignores events that are not lengthComputable', async () => {
    const onProgress = vi.fn()
    const result = start({ onProgress })

    xhr().progress(50, 0, false)
    xhr().finish(204)
    await result

    expect(onProgress).not.toHaveBeenCalled()
  })

  it('never reports above 100', async () => {
    const onProgress = vi.fn()
    const result = start({ onProgress })

    xhr().progress(500, 300)
    xhr().finish(204)
    await result

    expect(onProgress).toHaveBeenCalledWith(100)
    expect(onProgress.mock.calls).toEqual([[100]])
  })

  it('works without an onProgress callback', async () => {
    const result = start()

    xhr().progress(1, 2)
    xhr().finish(204)

    await expect(result).resolves.toEqual({ ok: true })
  })
})

describe('uploadReceipt outcome', () => {
  it('204 gives ok', async () => {
    const result = start()
    xhr().finish(204)
    await expect(result).resolves.toEqual({ ok: true })
  })

  it('400 gives size', async () => {
    const result = start()
    xhr().finish(400)
    await expect(result).resolves.toEqual({ ok: false, error: { kind: 'size' } })
  })

  it('403 gives expired', async () => {
    const result = start()
    xhr().finish(403)
    await expect(result).resolves.toEqual({ ok: false, error: { kind: 'expired' } })
  })

  it.each([200, 201, 301, 404, 500, 503, 0])('status %i gives interrupted', async (status) => {
    const result = start()
    xhr().finish(status)
    await expect(result).resolves.toEqual({ ok: false, error: { kind: 'interrupted' } })
  })

  it('a network error gives interrupted', async () => {
    const result = start()
    xhr().onerror?.()
    await expect(result).resolves.toEqual({ ok: false, error: { kind: 'interrupted' } })
  })

  it('a timeout gives interrupted', async () => {
    const result = start()
    xhr().ontimeout?.()
    await expect(result).resolves.toEqual({ ok: false, error: { kind: 'interrupted' } })
  })

  it('a thrown open() gives a value, not a rejection', async () => {
    FakeXhr.openError = new Error('boom')
    const result = uploadReceipt({ url: GOOD_URL, fields: GOOD_FIELDS }, file)
    await expect(result).resolves.toEqual({ ok: false, error: { kind: 'interrupted' } })
  })

  it('a thrown send() gives a value, not a rejection', async () => {
    vi.stubGlobal(
      'XMLHttpRequest',
      class extends FakeXhr {
        override readonly send = vi.fn(() => {
          throw new Error('boom')
        })
      },
    )
    const result = uploadReceipt({ url: GOOD_URL, fields: GOOD_FIELDS }, file)
    await expect(result).resolves.toEqual({ ok: false, error: { kind: 'interrupted' } })
  })
})

describe('uploadReceipt abort', () => {
  it('aborts the request and gives aborted when the signal fires', async () => {
    const controller = new AbortController()
    const result = start({ signal: controller.signal })

    controller.abort()

    expect(xhr().abort).toHaveBeenCalledTimes(1)
    await expect(result).resolves.toEqual({ ok: false, error: { kind: 'aborted' } })
  })

  it('gives aborted without sending when the signal is already aborted', async () => {
    const controller = new AbortController()
    controller.abort()

    const result = await uploadReceipt({ url: GOOD_URL, fields: GOOD_FIELDS }, file, {
      signal: controller.signal,
    })

    expect(result).toEqual({ ok: false, error: { kind: 'aborted' } })
    expect(FakeXhr.instances).toHaveLength(0)
  })

  it('does nothing when the signal fires after the upload finished', async () => {
    const controller = new AbortController()
    const result = start({ signal: controller.signal })
    xhr().finish(204)
    await result

    controller.abort()

    expect(xhr().abort).not.toHaveBeenCalled()
  })
})

describe('uploadReceipt refuses before open()', () => {
  const BAD_URLS: readonly string[] = [
    'https://user:pw@x.example/',
    'https://user@x.example/',
    'https://x.example/?a=1',
    'https://x.example/#frag',
    `${SCRIPT_SCHEME}alert(1)`,
    'data:text/plain,hello',
    'http://evil.example',
    'http://evil.example/',
    'http://localhost:9001/',
    'http://localhost:9000.evil.example/',
    '/relative',
    'x.example',
    '',
  ]
  const BAD_FIELDS: readonly string[] = [
    'acl',
    'success_action_redirect',
    'x-amz',
    'X-Amz',
    'x-amz-date',
    'policy',
    'key ',
    'Key',
    'content-type',
    '',
  ]

  it.each(BAD_URLS)('refuses the url %j', async (url) => {
    const result = await uploadReceipt({ url, fields: { key: 'k' } }, file)

    expect(result).toEqual({ ok: false, error: { kind: 'invalid-request' } })
    expect(FakeXhr.instances).toHaveLength(0)
  })

  it.each(BAD_FIELDS)('refuses the field name %j', async (name) => {
    const result = await uploadReceipt({ url: GOOD_URL, fields: { key: 'k', [name]: 'v' } }, file)

    expect(result).toEqual({ ok: false, error: { kind: 'invalid-request' } })
    expect(FakeXhr.instances).toHaveLength(0)
  })
})

describe('uploadReceipt progress edge cases', () => {
  it('reports nothing for a computable event with a total of 0', async () => {
    const onProgress = vi.fn()
    const result = start({ onProgress })

    xhr().progress(0, 0, true)
    xhr().progress(10, 0, true)
    xhr().finish(204)
    await result

    expect(onProgress).not.toHaveBeenCalled()
  })

  it.each([
    ['finished', (x: FakeXhr) => x.finish(204)],
    ['failed', (x: FakeXhr) => x.onerror?.()],
    ['timed out', (x: FakeXhr) => x.ontimeout?.()],
    ['aborted', (x: FakeXhr) => x.onabort?.()],
  ])('does not call onProgress after the upload %s', async (_name, settle) => {
    const onProgress = vi.fn()
    const result = start({ onProgress })
    xhr().progress(50, 100)

    settle(xhr())
    await result
    xhr().progress(100, 100)

    expect(onProgress.mock.calls).toEqual([[50]])
  })
})

describe('uploadReceipt settles once', () => {
  it('keeps aborted when a load event follows the abort', async () => {
    const result = start()

    xhr().onabort?.()
    xhr().finish(204)

    await expect(result).resolves.toEqual({ ok: false, error: { kind: 'aborted' } })
  })

  it('keeps interrupted when a timeout follows an error', async () => {
    const result = start()

    xhr().onerror?.()
    xhr().ontimeout?.()

    await expect(result).resolves.toEqual({ ok: false, error: { kind: 'interrupted' } })
  })

  it('keeps ok when an error follows the load', async () => {
    const result = start()

    xhr().finish(204)
    xhr().onerror?.()

    await expect(result).resolves.toEqual({ ok: true })
  })
})

describe('uploadReceipt abort after settling', () => {
  it.each([
    ['an error', (x: FakeXhr) => x.onerror?.()],
    ['a timeout', (x: FakeXhr) => x.ontimeout?.()],
  ])('does not abort the request after %s', async (_name, settle) => {
    const controller = new AbortController()
    const result = start({ signal: controller.signal })
    settle(xhr())
    await result

    controller.abort()

    expect(xhr().abort).not.toHaveBeenCalled()
  })

  it('does not abort the request after send() threw', async () => {
    const instances: FakeXhr[] = []
    vi.stubGlobal(
      'XMLHttpRequest',
      class extends FakeXhr {
        override readonly send = vi.fn(() => {
          throw new Error('boom')
        })
        constructor() {
          super()
          instances.push(this)
        }
      },
    )
    const controller = new AbortController()

    const result = await start({ signal: controller.signal })
    controller.abort()

    expect(result).toEqual({ ok: false, error: { kind: 'interrupted' } })
    expect(instances[0]?.abort).not.toHaveBeenCalled()
  })
})

describe('uploadReceipt refuses a malformed target', () => {
  const NAMES_16 = [
    'key',
    'Content-Type',
    'Policy',
    ...Array.from({ length: 13 }, (_, index) => `X-Amz-F${index}`),
  ]
  const toFields = (names: readonly string[]): Record<string, string> =>
    Object.fromEntries(names.map((name) => [name, 'v']))

  const BAD_TARGETS: ReadonlyArray<readonly [string, unknown]> = [
    ['a null target', null],
    ['an undefined target', undefined],
    ['a number url', { url: 42, fields: { key: 'k' } }],
    ['a missing url', { fields: { key: 'k' } }],
    ['a null url', { url: null, fields: { key: 'k' } }],
    ['null fields', { url: GOOD_URL, fields: null }],
    ['missing fields', { url: GOOD_URL }],
    ['an empty array as fields', { url: GOOD_URL, fields: [] }],
    ['an array as fields', { url: GOOD_URL, fields: ['key'] }],
    ['a string as fields', { url: GOOD_URL, fields: 'key' }],
    ['a class instance as fields', { url: GOOD_URL, fields: new (class Fields {})() }],
    ['a number value', { url: GOOD_URL, fields: { key: 1 } }],
    ['a null value', { url: GOOD_URL, fields: { key: null } }],
    ['an undefined value', { url: GOOD_URL, fields: { key: undefined } }],
    ['an object value', { url: GOOD_URL, fields: { key: {} } }],
    ['an array value', { url: GOOD_URL, fields: { key: ['k'] } }],
    ['17 fields', { url: GOOD_URL, fields: toFields([...NAMES_16, 'X-Amz-F13']) }],
    ['a parsed __proto__ field', { url: GOOD_URL, fields: JSON.parse('{"__proto__":"x"}') }],
    ['a constructor field', { url: GOOD_URL, fields: { constructor: 'x' } }],
    ['a prototype field', { url: GOOD_URL, fields: { prototype: 'x' } }],
    ['a bare X-Amz- field', { url: GOOD_URL, fields: { 'X-Amz-': 'x' } }],
    ['an X-Amz- field with only a newline', { url: GOOD_URL, fields: { 'X-Amz-\n': 'x' } }],
    ['an X-Amz- field with a newline inside', { url: GOOD_URL, fields: { 'X-Amz-a\nb': 'x' } }],
    ['a key field with a trailing newline', { url: GOOD_URL, fields: { 'key\n': 'x' } }],
  ]

  it.each(BAD_TARGETS)(
    'gives invalid-request for %s and creates nothing',
    async (_name, target) => {
      const result = await uploadReceipt(target as never, file)

      expect(result).toEqual({ ok: false, error: { kind: 'invalid-request' } })
      expect(FakeXhr.instances).toHaveLength(0)
    },
  )

  it('accepts exactly 16 fields', async () => {
    const result = uploadReceipt({ url: GOOD_URL, fields: toFields(NAMES_16) }, file)

    xhr().finish(204)

    await expect(result).resolves.toEqual({ ok: true })
  })

  it('accepts fields that have no prototype', async () => {
    const fields: Record<string, string> = Object.assign(Object.create(null) as object, {
      key: 'k',
    })
    const result = uploadReceipt({ url: GOOD_URL, fields }, file)

    xhr().finish(204)

    await expect(result).resolves.toEqual({ ok: true })
  })
})
