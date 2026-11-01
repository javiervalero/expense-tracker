import { afterEach, describe, expect, it, vi } from 'vitest'

import type { ApiResult } from '../http'
import { createReceiptUpload, deleteReceipt, getReceipt } from '../receipts'
import type { Receipt, ReceiptUpload, ReceiptUploadRequest } from '../types'

const requestMock = vi.hoisted(() => vi.fn())

vi.mock('../http', async (original) => ({
  ...(await original<typeof import('../http')>()),
  request: requestMock,
}))

const ID = '0b7c1f0e-5d3a-4c8e-9a41-2f6d8e7b1a90'
const BAD_IDS = ['', '../x', 'a/b', 'a?b', 'a#b', 'a'.repeat(65)]
const body: ReceiptUploadRequest = { contentType: 'application/pdf', size: 2048 }

afterEach(() => {
  requestMock.mockReset()
  vi.unstubAllGlobals()
})

describe('receipts api', () => {
  it('createReceiptUpload() asks POST on the upload path with the body as given', async () => {
    const answer: ApiResult<ReceiptUpload> = {
      ok: true,
      status: 201,
      data: {
        receiptId: ID,
        url: 'https://b.s3.eu-west-1.amazonaws.com/',
        fields: { key: 'k' },
        expiresAt: '2026-10-01T10:05:00Z',
        maxBytes: 10_485_760,
      },
    }
    requestMock.mockResolvedValue(answer)

    const result = await createReceiptUpload(ID, body)

    expect(requestMock).toHaveBeenCalledWith('POST', `/expenses/${ID}/receipt/upload`, {
      auth: 'session',
      body,
    })
    expect(result).toBe(answer)
  })

  it('createReceiptUpload() sends the declared size exactly as given', async () => {
    requestMock.mockResolvedValue({ ok: false, error: { kind: 'network' } })

    await createReceiptUpload(ID, { contentType: 'image/png', size: 777 })

    const options = requestMock.mock.calls[0]?.[2] as { body: ReceiptUploadRequest }
    expect(options.body).toEqual({ contentType: 'image/png', size: 777 })
  })

  it('createReceiptUpload() passes the signal when given', async () => {
    requestMock.mockResolvedValue({ ok: false, error: { kind: 'aborted' } })
    const signal = new AbortController().signal

    await createReceiptUpload(ID, body, signal)

    expect(requestMock).toHaveBeenCalledWith('POST', `/expenses/${ID}/receipt/upload`, {
      auth: 'session',
      body,
      signal,
    })
  })

  it('getReceipt() asks GET on the receipt path', async () => {
    const answer: ApiResult<Receipt> = { ok: false, error: { kind: 'not-found', status: 404 } }
    requestMock.mockResolvedValue(answer)

    const result = await getReceipt(ID)

    expect(requestMock).toHaveBeenCalledWith('GET', `/expenses/${ID}/receipt`, { auth: 'session' })
    expect(result).toBe(answer)
  })

  it('getReceipt() passes the signal when given', async () => {
    requestMock.mockResolvedValue({ ok: false, error: { kind: 'aborted' } })
    const signal = new AbortController().signal

    await getReceipt(ID, signal)

    expect(requestMock).toHaveBeenCalledWith('GET', `/expenses/${ID}/receipt`, {
      auth: 'session',
      signal,
    })
  })

  it('deleteReceipt() asks DELETE on the receipt path', async () => {
    requestMock.mockResolvedValue({ ok: true, status: 204, data: undefined })

    await deleteReceipt(ID)

    expect(requestMock).toHaveBeenCalledWith('DELETE', `/expenses/${ID}/receipt`, {
      auth: 'session',
    })
  })

  it.each(BAD_IDS)('refuses the id %j without calling request', async (id) => {
    const results = await Promise.all([
      createReceiptUpload(id, body),
      getReceipt(id),
      deleteReceipt(id),
    ])

    for (const result of results) {
      expect(result).toEqual({ ok: false, error: { kind: 'invalid-request' } })
    }
    expect(requestMock).not.toHaveBeenCalled()
  })
})

describe('receipts api through the real request', () => {
  it('sends the three receipt paths under /api/v1 (they pass the path check)', async () => {
    const actual = await vi.importActual<typeof import('../receipts')>('../receipts')
    const httpActual = await vi.importActual<typeof import('../http')>('../http')
    requestMock.mockImplementation(httpActual.request)
    const fetchMock = vi.fn(() => Promise.resolve(new Response(null, { status: 204 })))
    vi.stubGlobal('fetch', fetchMock)

    await actual.createReceiptUpload(ID, body)
    await actual.getReceipt(ID)
    await actual.deleteReceipt(ID)

    const urls = fetchMock.mock.calls.map((call) => String((call as unknown[])[0]))
    expect(urls).toEqual([
      `/api/v1/expenses/${ID}/receipt/upload`,
      `/api/v1/expenses/${ID}/receipt`,
      `/api/v1/expenses/${ID}/receipt`,
    ])
  })
})
