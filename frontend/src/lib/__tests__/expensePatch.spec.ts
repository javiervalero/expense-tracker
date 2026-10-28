import { describe, expect, it } from 'vitest'

import type { Expense } from '@/api/types'
import { buildExpensePatch } from '../expensePatch'

const CATEGORY = '0b7c1f0e-5d3a-4c8e-9a41-2f6d8e7b1a90'
const original: Expense = {
  id: '5a1c1f0e-5d3a-4c8e-9a41-2f6d8e7b1a91',
  date: '2026-10-01',
  amountCents: 1250,
  description: 'Lunch',
  categoryId: CATEGORY,
  version: 3,
  createdAt: '2026-10-01T10:00:00Z',
  updatedAt: '2026-10-01T10:00:00Z',
}
const same = {
  date: '2026-10-01',
  amountCents: 1250,
  description: 'Lunch',
  categoryId: CATEGORY as string | null,
}

describe('buildExpensePatch', () => {
  it('sends only the changed members plus the version', () => {
    expect(buildExpensePatch(original, { ...same, amountCents: 1300 })).toEqual({
      version: 3,
      amountCents: 1300,
    })
    expect(
      buildExpensePatch(original, { ...same, date: '2026-10-02', description: 'Dinner' }),
    ).toEqual({ version: 3, date: '2026-10-02', description: 'Dinner' })
  })

  it('sends categoryId null when the category changes to Uncategorized', () => {
    expect(buildExpensePatch(original, { ...same, categoryId: null })).toEqual({
      version: 3,
      categoryId: null,
    })
  })

  it('sends a new category id', () => {
    const other = '1b7c1f0e-5d3a-4c8e-9a41-2f6d8e7b1a99'
    expect(buildExpensePatch(original, { ...same, categoryId: other })).toEqual({
      version: 3,
      categoryId: other,
    })
  })

  it('sends a category on an expense that had none', () => {
    expect(
      buildExpensePatch({ ...original, categoryId: null }, { ...same, categoryId: CATEGORY }),
    ).toEqual({ version: 3, categoryId: CATEGORY })
  })

  it('returns null when nothing changed', () => {
    expect(buildExpensePatch(original, same)).toBeNull()
  })

  it('does not send a description that is the same after trimming', () => {
    expect(buildExpensePatch(original, { ...same, description: '  Lunch  ' })).toBeNull()
  })

  it('sends the trimmed description when it did change', () => {
    expect(buildExpensePatch(original, { ...same, description: '  Dinner ' })).toEqual({
      version: 3,
      description: 'Dinner',
    })
  })
})
