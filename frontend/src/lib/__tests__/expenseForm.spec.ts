import { describe, expect, it } from 'vitest'

import { validateExpenseForm } from '../expenseForm'

const valid = { date: '2026-10-01', amount: '12,50', description: ' Lunch ', categoryId: '' }

describe('validateExpenseForm', () => {
  it('returns the parsed values: cents as an integer, trimmed text, Uncategorized as null', () => {
    expect(validateExpenseForm(valid)).toEqual({
      ok: true,
      value: { date: '2026-10-01', amountCents: 1250, description: 'Lunch', categoryId: null },
    })
  })

  it('keeps a chosen category id', () => {
    const result = validateExpenseForm({ ...valid, categoryId: 'abc' })

    expect(result).toMatchObject({ ok: true, value: { categoryId: 'abc' } })
  })

  it.each([
    ['', 'Enter a date.'],
    ['2026-02-30', 'Enter a real date between 2000-01-01 and 2099-12-31.'],
    ['1999-12-31', 'Enter a real date between 2000-01-01 and 2099-12-31.'],
  ])('refuses the date %j', (date, message) => {
    expect(validateExpenseForm({ ...valid, date })).toEqual({
      ok: false,
      fields: { date: message },
    })
  })

  it.each([
    ['', 'Enter an amount.'],
    ['-5', 'Enter the amount without a minus sign.'],
    ['abc', 'Enter an amount such as 12.50.'],
    ['1.234', 'Use at most 2 decimals.'],
    ['0', 'The amount must be at least 0.01.'],
    ['1000000.01', 'The amount can be at most 1,000,000.00.'],
    ['99999999999', 'The amount can be at most 1,000,000.00.'],
  ])('refuses the amount %j', (amount, message) => {
    expect(validateExpenseForm({ ...valid, amount })).toEqual({
      ok: false,
      fields: { amount: message },
    })
  })

  it('refuses an empty and a too long description', () => {
    expect(validateExpenseForm({ ...valid, description: '   ' })).toEqual({
      ok: false,
      fields: { description: 'Enter a description.' },
    })
    expect(validateExpenseForm({ ...valid, description: 'x'.repeat(201) })).toEqual({
      ok: false,
      fields: { description: 'Description can have at most 200 characters.' },
    })
  })

  it('counts a description in code points, not UTF-16 units', () => {
    const result = validateExpenseForm({ ...valid, description: '\u{1F600}'.repeat(200) })

    expect(result.ok).toBe(true)
  })

  it('reports every invalid field at once', () => {
    const result = validateExpenseForm({ date: '', amount: '', description: '', categoryId: '' })

    expect(result).toMatchObject({ ok: false })
    expect(Object.keys(result.ok ? {} : result.fields)).toEqual(['date', 'amount', 'description'])
  })
})
