import { request } from './http'
import type { ApiResult } from './http'
import type { MonthlyReport } from './types'
import { isIsoMonth } from '../lib/months'

// The report operation of contract/openapi.yaml. It needs the session, so a 401 runs the expiry handler.

const INVALID_MONTH: ApiResult<never> = { ok: false, error: { kind: 'invalid-request' } }

/** `GET /reports/monthly?month=YYYY-MM`: 200 with the month's totals. A month that is not valid sends no request. */
export function getMonthlyReport(
  month: string,
  signal?: AbortSignal,
): Promise<ApiResult<MonthlyReport>> {
  return isIsoMonth(month)
    ? request<MonthlyReport>('GET', '/reports/monthly', {
        auth: 'session',
        query: new URLSearchParams({ month }),
        ...(signal === undefined ? {} : { signal }),
      })
    : Promise.resolve(INVALID_MONTH)
}
