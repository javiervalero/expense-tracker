import type { ApiResult } from '@/api/http'
import type { CategoryTotal, MonthlyReport } from '@/api/types'
import { FOOD, TRAVEL } from './expenseFixtures'

export function categoryTotal(overrides: Partial<CategoryTotal> = {}): CategoryTotal {
  return { categoryId: FOOD, name: 'Food', totalCents: 2002, expenseCount: 2, ...overrides }
}

/** October 2026: Food 2002 (2), Travel 2004 (2), Uncategorized 1004 (1); total 5010, 5 expenses. */
export function monthlyReport(overrides: Partial<MonthlyReport> = {}): MonthlyReport {
  return {
    month: '2026-10',
    totalCents: 5010,
    expenseCount: 5,
    complete: true,
    categories: [
      categoryTotal({ categoryId: TRAVEL, name: 'Travel', totalCents: 2004 }),
      categoryTotal(),
      categoryTotal({ categoryId: null, name: null, totalCents: 1004, expenseCount: 1 }),
    ],
    ...overrides,
  }
}

export function reportOk(report: MonthlyReport = monthlyReport()): ApiResult<MonthlyReport> {
  return { ok: true, status: 200, data: report }
}
