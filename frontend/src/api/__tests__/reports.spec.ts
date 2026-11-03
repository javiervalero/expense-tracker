import { afterEach, describe, expect, it, vi } from 'vitest'

import type { ApiResult } from '../http'
import type { MonthlyReport } from '../types'
import { getMonthlyReport } from '../reports'

const requestMock = vi.hoisted(() => vi.fn())

vi.mock('../http', async (original) => ({
  ...(await original<typeof import('../http')>()),
  request: requestMock,
}))

const report: MonthlyReport = {
  month: '2026-10',
  totalCents: 0,
  expenseCount: 0,
  complete: true,
  categories: [],
}

afterEach(() => {
  requestMock.mockReset()
})

describe('reports api', () => {
  it('getMonthlyReport() asks GET /reports/monthly with the month as a session call and passes the signal', async () => {
    const answer: ApiResult<MonthlyReport> = { ok: true, status: 200, data: report }
    requestMock.mockResolvedValue(answer)
    const signal = new AbortController().signal

    const result = await getMonthlyReport('2026-10', signal)

    expect(requestMock).toHaveBeenCalledTimes(1)
    const [method, path, options] = requestMock.mock.calls[0] as [
      string,
      string,
      { auth: string; query: URLSearchParams; signal: AbortSignal },
    ]
    expect(method).toBe('GET')
    expect(path).toBe('/reports/monthly')
    expect(options.auth).toBe('session')
    expect(options.query.toString()).toBe('month=2026-10')
    expect(options.signal).toBe(signal)
    expect(result).toBe(answer)
  })

  it('getMonthlyReport() leaves the signal out when there is none', async () => {
    requestMock.mockResolvedValue({ ok: true, status: 200, data: report })

    await getMonthlyReport('2026-10')

    const [, , options] = requestMock.mock.calls[0] as [string, string, Record<string, unknown>]
    expect(Object.hasOwn(options, 'signal')).toBe(false)
  })

  it.each(['2026-13', '', '2026-1', '2100-01'])(
    'getMonthlyReport(%j) resolves invalid-request without any request',
    async (month) => {
      const result = await getMonthlyReport(month)

      expect(result).toEqual({ ok: false, error: { kind: 'invalid-request' } })
      expect(requestMock).not.toHaveBeenCalled()
    },
  )
})
