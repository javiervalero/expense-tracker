import { beforeEach, describe, expect, it, vi } from 'vitest'

import { deferred, failed } from '@/api/__tests__/expenseFixtures'
import { monthlyReport, reportOk } from '@/api/__tests__/reportFixtures'
import { describeSummaryError } from '@/lib/summaryMessages'
import { useMonthlyReport } from '../useMonthlyReport'

const api = vi.hoisted(() => ({ getMonthlyReport: vi.fn() }))
vi.mock('@/api/reports', () => api)

function signalOfCall(index: number): AbortSignal {
  return api.getMonthlyReport.mock.calls.at(index)?.[1] as AbortSignal
}

beforeEach(() => api.getMonthlyReport.mockReset())

describe('useMonthlyReport', () => {
  it('starts idle with nothing loaded', () => {
    const report = useMonthlyReport()

    expect(report.status.value).toBe('idle')
    expect(report.data.value).toBeNull()
    expect(report.month.value).toBe('')
  })

  it('sends one request for a month, with a signal, and keeps the answer', async () => {
    api.getMonthlyReport.mockResolvedValueOnce(reportOk())
    const report = useMonthlyReport()

    const pending = report.load('2026-10')
    expect(report.status.value).toBe('loading')
    expect(report.month.value).toBe('2026-10')
    await pending

    expect(api.getMonthlyReport).toHaveBeenCalledTimes(1)
    expect(api.getMonthlyReport.mock.calls[0]?.[0]).toBe('2026-10')
    expect(signalOfCall(0).aborted).toBe(false)
    expect(report.status.value).toBe('loaded')
    expect(report.data.value).toEqual(monthlyReport())
    expect(report.message.value).toBe('')
  })

  it('aborts the previous request when the month changes and drops its late answer', async () => {
    const first = deferred<unknown>()
    api.getMonthlyReport.mockReturnValueOnce(first.promise)
    api.getMonthlyReport.mockResolvedValueOnce(reportOk(monthlyReport({ month: '2026-09' })))
    const report = useMonthlyReport()

    const stale = report.load('2026-10')
    await report.load('2026-09')
    first.resolve(reportOk(monthlyReport({ month: '2026-10' })))
    await stale

    expect(signalOfCall(0).aborted).toBe(true)
    expect(signalOfCall(1).aborted).toBe(false)
    expect(report.data.value?.month).toBe('2026-09')
    expect(report.month.value).toBe('2026-09')
    expect(report.status.value).toBe('loaded')
  })

  it('drops a late failure of a superseded request', async () => {
    const first = deferred<unknown>()
    api.getMonthlyReport.mockReturnValueOnce(first.promise)
    api.getMonthlyReport.mockResolvedValueOnce(reportOk())
    const report = useMonthlyReport()

    const stale = report.load('2026-08')
    await report.load('2026-10')
    first.resolve(failed({ kind: 'network' }))
    await stale

    expect(report.status.value).toBe('loaded')
    expect(report.message.value).toBe('')
  })

  it('clears the old data while the next month loads', async () => {
    api.getMonthlyReport.mockResolvedValueOnce(reportOk())
    api.getMonthlyReport.mockReturnValueOnce(new Promise(() => undefined))
    const report = useMonthlyReport()
    await report.load('2026-10')

    void report.load('2026-09')

    expect(report.status.value).toBe('loading')
    expect(report.data.value).toBeNull()
  })

  it('shows the message of the error kind and no data', async () => {
    const error = { kind: 'server', status: 500 } as const
    api.getMonthlyReport.mockResolvedValueOnce(failed(error))
    const report = useMonthlyReport()

    await report.load('2026-10')

    expect(report.status.value).toBe('error')
    expect(report.data.value).toBeNull()
    expect(report.message.value).toBe(describeSummaryError(error))
    expect(report.message.value).not.toBe('')
  })

  it('retry asks again for the same month and clears the error', async () => {
    api.getMonthlyReport.mockResolvedValueOnce(failed({ kind: 'network' }))
    api.getMonthlyReport.mockResolvedValueOnce(reportOk())
    const report = useMonthlyReport()
    await report.load('2026-10')

    await report.retry()

    expect(api.getMonthlyReport).toHaveBeenCalledTimes(2)
    expect(api.getMonthlyReport.mock.calls[1]?.[0]).toBe('2026-10')
    expect(report.status.value).toBe('loaded')
    expect(report.message.value).toBe('')
  })

  it('retry before any load sends nothing', async () => {
    const report = useMonthlyReport()

    await report.retry()

    expect(api.getMonthlyReport).not.toHaveBeenCalled()
    expect(report.status.value).toBe('idle')
  })

  it('stop aborts the request and drops its answer', async () => {
    const pending = deferred<unknown>()
    api.getMonthlyReport.mockReturnValueOnce(pending.promise)
    const report = useMonthlyReport()

    const run = report.load('2026-10')
    report.stop()
    pending.resolve(reportOk())
    await run

    expect(signalOfCall(0).aborted).toBe(true)
    expect(report.data.value).toBeNull()
    expect(report.status.value).toBe('loading')
  })

  it('stop with nothing in flight does nothing', () => {
    const report = useMonthlyReport()

    expect(() => report.stop()).not.toThrow()
  })
})
